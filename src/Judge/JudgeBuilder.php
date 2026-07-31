<?php

namespace Vizra\Evals\Judge;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Support\Pricing;

/**
 * Fluent configuration for a deferred LLM-judge assertion. Every fluent
 * call is pure configuration; nothing executes during evaluate(). The
 * Runner calls execute() after all deterministic assertions have run —
 * or skipped() when a gate failed — so failed samples never pay for
 * judge tokens.
 */
final class JudgeBuilder
{
    private string $criteria = '';

    private ?string $judgeClass = null;

    private null|int|float $minScore = null;

    /** @var array<string, int|float> */
    private array $dimensions = [];

    private ?string $comparedTo = null;

    private string $prefer = 'actual';

    private float $weight = 1.0;

    private Lab|string|null $provider = null;

    private ?string $model = null;

    public function __construct(
        private readonly string $subject,
        private readonly Row $row,
    ) {}

    public function criteria(string $criteria): self
    {
        $this->criteria = $criteria;

        return $this;
    }

    /**
     * Use a custom judge agent class (must implement HasStructuredOutput
     * with at least {score: int, reasoning: string}).
     */
    public function using(string $agentClass): self
    {
        $this->judgeClass = $agentClass;

        return $this;
    }

    public function minScore(int|float $score): self
    {
        $this->minScore = $score;

        return $this;
    }

    /**
     * Require per-dimension minimum scores, e.g. ['accuracy' => 7, 'tone' => 6].
     */
    public function dimensions(array $dimensions): self
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    public function comparedTo(AgentResponse|string $reference): self
    {
        $this->comparedTo = $reference instanceof AgentResponse ? $reference->text : $reference;

        return $this;
    }

    /**
     * Which side should win a comparedTo() comparison: 'actual' or 'reference'.
     */
    public function prefer(string $which): self
    {
        $this->prefer = $which;

        return $this;
    }

    public function weight(float $weight): self
    {
        $this->weight = $weight;

        return $this;
    }

    public function provider(Lab|string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function model(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    /** @internal called by the Runner */
    public function skipped(): AssertionResult
    {
        $result = new AssertionResult(
            $this->name(),
            AssertionResult::SKIPPED,
            null,
            message: 'Judge skipped because a gated assertion failed.',
            weight: $this->weight,
            type: AssertionResult::TYPE_JUDGE,
        );

        return $result;
    }

    /** @internal called by the Runner */
    public function execute(): AssertionResult
    {
        try {
            return $this->comparedTo === null ? $this->executeScoring() : $this->executeComparison();
        } catch (Throwable $e) {
            $result = AssertionResult::error($this->name(), 'Judge invocation failed: '.$e->getMessage());
            $result->type = AssertionResult::TYPE_JUDGE;
            $result->weight = $this->weight;

            return $result;
        }
    }

    private function executeScoring(): AssertionResult
    {
        $response = $this->invokeJudge($this->resolveJudge(), $this->scoringPrompt());

        $judgment = new Judgment(
            (int) $response['score'],
            (string) $response['reasoning'],
            array_map(fn ($v) => is_numeric($v) ? +$v : 0, (array) ($response['dimensions'] ?? [])),
        );

        $minScore = $this->minScore ?? config('evals.judge.min_score', 7);

        $failedDimensions = [];

        foreach ($this->dimensions as $dimension => $threshold) {
            $actual = $judgment->dimensions[$dimension] ?? null;

            if ($actual === null || $actual < $threshold) {
                $failedDimensions[] = "{$dimension} ".($actual ?? '?')."/{$threshold}";
            }
        }

        $passed = $judgment->score >= $minScore && $failedDimensions === [];

        $result = new AssertionResult(
            $this->name(),
            $passed ? AssertionResult::PASSED : AssertionResult::FAILED,
            round(min(1.0, max(0.0, $judgment->score / 10)), 4),
            expected: ">= {$minScore}/10".($this->dimensions !== [] ? ' and dimensions '.json_encode($this->dimensions) : ''),
            actual: "{$judgment->score}/10".($judgment->dimensions !== [] ? ' '.json_encode($judgment->dimensions) : ''),
            message: $passed
                ? ''
                : trim("Judge scored {$judgment->score}/10 (minimum {$minScore})."
                    .($failedDimensions !== [] ? ' Dimensions below threshold: '.implode(', ', $failedDimensions).'.' : '')),
            weight: $this->weight,
            type: AssertionResult::TYPE_JUDGE,
            judgeReasoning: $judgment->reasoning,
        );

        $result->meta = $this->judgeMeta($response, ['dimensions' => $judgment->dimensions ?: null]);

        return $result;
    }

    private function executeComparison(): AssertionResult
    {
        $judgeClass = $this->judgeClass ?? ComparisonJudgeAgent::class;
        $response = $this->invokeJudge($this->instantiate($judgeClass), $this->comparisonPrompt());

        $winner = (string) $response['winner'];
        $reasoning = (string) $response['reasoning'];

        $preferred = $this->prefer === 'reference' ? 'b' : 'a';
        $passed = $winner === $preferred;

        $score = match ($winner) {
            $preferred => 1.0,
            'tie' => 0.5,
            default => 0.0,
        };

        $describe = fn (string $side) => match ($side) {
            'a' => 'actual',
            'b' => 'reference',
            default => 'tie',
        };

        $result = new AssertionResult(
            $this->name(),
            $passed ? AssertionResult::PASSED : AssertionResult::FAILED,
            $score,
            expected: "winner: {$this->prefer}",
            actual: 'winner: '.$describe($winner),
            message: $passed ? '' : "Judge preferred the {$describe($winner)} response.",
            weight: $this->weight,
            type: AssertionResult::TYPE_JUDGE,
            judgeReasoning: $reasoning,
        );

        $result->meta = $this->judgeMeta($response);

        return $result;
    }

    private function resolveJudge(): Agent
    {
        $class = $this->judgeClass ?? config('evals.judge.agent', JudgeAgent::class);

        if ($class === JudgeAgent::class || is_subclass_of($class, JudgeAgent::class)) {
            return new $class(array_keys($this->dimensions));
        }

        return $this->instantiate($class);
    }

    private function instantiate(string $class): Agent
    {
        return method_exists($class, 'make') ? $class::make() : app($class);
    }

    private function invokeJudge(Agent $judge, string $prompt): StructuredAgentResponse
    {
        $response = $judge->prompt(
            $prompt,
            provider: $this->provider ?? config('evals.judge.provider'),
            model: $this->model ?? config('evals.judge.model'),
        );

        if (! $response instanceof StructuredAgentResponse) {
            throw new \RuntimeException(
                'Judge agent '.$judge::class.' did not return structured output; judges must implement HasStructuredOutput.'
            );
        }

        return $response;
    }

    private function scoringPrompt(): string
    {
        $sections = ['Grade the following AI agent response.'];

        $sections[] = "Criteria:\n".($this->criteria !== '' ? $this->criteria : 'Overall quality, correctness, and helpfulness.');

        if ($this->row->isMultiTurn()) {
            $transcript = collect($this->row->messages)
                ->map(fn (array $message) => strtoupper($message['role']).': '.$message['content'])
                ->implode("\n");

            $sections[] = "Conversation so far:\n".$transcript;
        }

        $sections[] = "User input:\n".$this->row->input;

        if ($this->row->expected !== null) {
            $expected = is_string($this->row->expected)
                ? $this->row->expected
                : json_encode($this->row->expected, JSON_UNESCAPED_UNICODE);

            $sections[] = "Reference (expected) information:\n".$expected;
        }

        if ($this->dimensions !== []) {
            $sections[] = 'Score each of these dimensions as well: '.implode(', ', array_keys($this->dimensions)).'.';
        }

        $sections[] = "Agent response to grade:\n".$this->subject;

        return implode("\n\n", $sections);
    }

    private function comparisonPrompt(): string
    {
        $sections = ['Compare the two AI responses below.'];

        $sections[] = "Criteria:\n".($this->criteria !== '' ? $this->criteria : 'Overall quality, correctness, and helpfulness.');
        $sections[] = "User input:\n".$this->row->input;
        $sections[] = "Response A:\n".$this->subject;
        $sections[] = "Response B:\n".$this->comparedTo;

        return implode("\n\n", $sections);
    }

    private function judgeMeta(StructuredAgentResponse $response, array $extra = []): array
    {
        $provider = $response->meta->provider;
        $model = $response->meta->model;

        return array_filter([
            ...$extra,
            'judge_model' => $model,
            'judge_provider' => $provider,
            'judge_usage' => $response->usage->toArray(),
            'judge_cost' => Pricing::cost($response->usage, $provider, $model),
        ], fn ($value) => $value !== null);
    }

    private function name(): string
    {
        return $this->criteria === ''
            ? 'judge'
            : 'judge:'.Str::slug(Str::limit($this->criteria, 30, ''));
    }
}
