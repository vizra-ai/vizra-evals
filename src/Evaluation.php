<?php

namespace Vizra\Evals;

use Laravel\Ai\Responses\AgentResponse;
use LogicException;
use Throwable;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Assertions\SampleCollector;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Judge\JudgeBuilder;
use Vizra\Evals\Run\Gate;

abstract class Evaluation
{
    use Assertions\Concerns\AssertsContent;
    use Assertions\Concerns\AssertsSafety;
    use Assertions\Concerns\AssertsStructure;
    use Assertions\Concerns\AssertsToolUse;
    use Assertions\Concerns\AssertsUsageAndCost;
    use Assertions\Concerns\AssertsValues;

    /**
     * How many times each row is run. Evals are probabilistic — a single
     * sample of a nondeterministic system proves nothing.
     */
    public int $samples = 1;

    /**
     * What gets invoked: a Laravel\Ai agent class-string, an agent
     * instance, or a Closure(Row $row): AgentResponse.
     */
    abstract public function target(): mixed;

    abstract public function dataset(): Dataset;

    abstract public function evaluate(Row $row, AgentResponse $response): void;

    /**
     * Optional provider/model matrix. Each combo becomes a distinct series
     * in the results, e.g. [['provider' => Lab::Anthropic, 'model' => 'claude-sonnet-5']].
     */
    public function across(): array
    {
        return [];
    }

    /**
     * Rewrite a row before it is sent to the target. Use $row->withInput()
     * and friends so the row keeps its identity hash across runs.
     */
    public function transform(Row $row): Row
    {
        return $row;
    }

    public function name(): string
    {
        return class_basename(static::class);
    }

    /**
     * The identity results are recorded under (and baselines joined on).
     * Class-based evaluations use their FQCN; inline Pest evals override
     * this with the test's name.
     */
    public function suite(): string
    {
        return static::class;
    }

    public function description(): string
    {
        return '';
    }

    /**
     * Run-level pass/fail policy. Null falls back to config('evals.gate')
     * and CLI flags.
     */
    public function gatePolicy(): ?Gate
    {
        return null;
    }

    // ------------------------------------------------------------------
    // Ambient sample state — managed by the Runner. During evaluate(), the
    // current response and row are ambient, which is why assertion helpers
    // take no $response parameter.
    // ------------------------------------------------------------------

    private ?AgentResponse $currentResponse = null;

    private ?Row $currentRow = null;

    private ?SampleCollector $collector = null;

    /** @internal */
    public function startSample(Row $row, AgentResponse $response): SampleCollector
    {
        $this->currentRow = $row;
        $this->currentResponse = $response;

        return $this->collector = new SampleCollector;
    }

    /** @internal */
    public function endSample(): void
    {
        $this->currentRow = null;
        $this->currentResponse = null;
        $this->collector = null;
    }

    /**
     * Run a custom assertion against the current sample and record it.
     */
    protected function assertWith(Assertion $assertion, ?string $name = null): AssertionResult
    {
        try {
            $result = $assertion($this->response(), $this->row());
        } catch (Throwable $e) {
            $result = AssertionResult::error($name ?? class_basename($assertion), $e->getMessage());
        }

        if ($name !== null) {
            $result->name = $name;
        }

        return $this->record($result);
    }

    /**
     * Record an already-built result (used by scalar helpers like assertTrue).
     */
    protected function record(AssertionResult $result): AssertionResult
    {
        return $this->collectorOrFail()->record($result);
    }

    /**
     * Register a deferred LLM-judge assertion. Judges execute after all
     * deterministic assertions, and are skipped when a gate has failed
     * (cheap checks first, expensive checks last).
     */
    protected function judge(AgentResponse|string|null $response = null): JudgeBuilder
    {
        $subject = match (true) {
            $response instanceof AgentResponse => $response->text,
            is_string($response) => $response,
            default => $this->response()->text,
        };

        return $this->collectorOrFail()->deferJudge(new JudgeBuilder($subject, $this->row()));
    }

    /**
     * @internal Passthrough for the Pest AssertionProxy, which lives outside
     * this class but drives the same protected helpers.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function runAssertion(string $helper, array $arguments): AssertionResult
    {
        return $this->{$helper}(...$arguments);
    }

    protected function response(): AgentResponse
    {
        return $this->currentResponse
            ?? throw new LogicException('Assertions may only run inside evaluate() — there is no current sample.');
    }

    protected function row(): Row
    {
        return $this->currentRow
            ?? throw new LogicException('Assertions may only run inside evaluate() — there is no current sample.');
    }

    private function collectorOrFail(): SampleCollector
    {
        return $this->collector
            ?? throw new LogicException('Assertions may only run inside evaluate() — there is no current sample.');
    }
}
