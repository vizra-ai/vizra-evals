<?php

namespace Vizra\Evals\Pest;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Evaluation;
use Vizra\Evals\Exceptions\DatasetException;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Run\Comparator;
use Vizra\Evals\Run\Comparison;
use Vizra\Evals\Run\Gate;
use Vizra\Evals\Run\Runner;
use Vizra\Evals\Run\RunResult;

/**
 * The `$eval` handed to the toPassEval() closure: fluent configuration for
 * one recorded evaluation run.
 */
final class PendingEval
{
    private ?Dataset $dataset = null;

    private ?int $take = null;

    private bool $fromConversations = false;

    private ?int $samples = null;

    private ?int $concurrency = null;

    /** @var array<int, Closure> */
    private array $assertions = [];

    /** @var array<int, Closure(Evaluation): mixed> */
    private array $judges = [];

    private array $across = [];

    private ?Gate $gate = null;

    private ?string $suite = null;

    private ?string $evaluationClass = null;

    public function __construct(
        private readonly mixed $target,
        private readonly string $defaultSuite,
    ) {}

    /**
     * A JSONL/CSV file path, a Dataset instance, or an inline array of rows.
     */
    public function dataset(Dataset|array|string $dataset): self
    {
        $this->dataset = match (true) {
            $dataset instanceof Dataset => $dataset,
            is_array($dataset) => Dataset::fromArray($dataset),
            str_ends_with($dataset, '.csv') => Dataset::fromCsv($dataset),
            default => Dataset::fromJsonl($dataset),
        };

        return $this;
    }

    /**
     * Build the dataset from the agent's stored production conversations.
     */
    public function fromConversations(?int $take = null): self
    {
        $this->fromConversations = true;
        $this->take = $take;

        return $this;
    }

    public function samples(int $samples): self
    {
        $this->samples = $samples;

        return $this;
    }

    public function concurrency(int $concurrency): self
    {
        $this->concurrency = $concurrency;

        return $this;
    }

    /**
     * Deterministic checks, run per sample. The closure receives an
     * assertion proxy plus the row and response:
     *
     *     ->assert(fn ($a) => $a->notEmpty()->gate()->toolCalled('lookup_order'))
     */
    public function assert(Closure $assertions): self
    {
        $this->assertions[] = $assertions;

        return $this;
    }

    /**
     * An LLM-judged criterion (judges run after deterministic checks and
     * are skipped when a gated assertion fails).
     */
    public function judge(
        string $criteria,
        int|float|null $min = null,
        array $dimensions = [],
        Lab|string|null $provider = null,
        ?string $model = null,
        ?string $using = null,
    ): self {
        $this->judges[] = function (Evaluation $evaluation) use ($criteria, $min, $dimensions, $provider, $model, $using): void {
            /** @var InlineEvaluation $evaluation */
            $builder = $evaluation->configureJudge()->criteria($criteria);

            if ($min !== null) {
                $builder->minScore($min);
            }

            if ($dimensions !== []) {
                $builder->dimensions($dimensions);
            }

            if ($provider !== null) {
                $builder->provider($provider);
            }

            if ($model !== null) {
                $builder->model($model);
            }

            if ($using !== null) {
                $builder->using($using);
            }
        };

        return $this;
    }

    /**
     * Provider/model matrix — each combo runs the full dataset as its own
     * series, e.g. across([['provider' => 'openai', 'model' => 'gpt-5.4']]).
     */
    public function across(array $matrix): self
    {
        $this->across = $matrix;

        return $this;
    }

    /**
     * Run-level pass policy. maxRegressions is enforced against the suite's
     * stored baseline (when one exists).
     */
    public function gate(?float $minScore = null, ?float $minPassRate = null, int $maxRegressions = 0): self
    {
        $this->gate = new Gate($minScore, $minPassRate, $maxRegressions);

        return $this;
    }

    /**
     * Override the suite name results are recorded under (defaults to the
     * Pest test's description).
     */
    public function suite(string $suite): self
    {
        $this->suite = $suite;

        return $this;
    }

    /**
     * Run a full Evaluation class instead of inline configuration.
     */
    public function using(string $evaluationClass): self
    {
        if (! is_subclass_of($evaluationClass, Evaluation::class)) {
            throw new InvalidArgumentException("[{$evaluationClass}] is not an Evaluation class.");
        }

        $this->evaluationClass = $evaluationClass;

        return $this;
    }

    /**
     * @internal Executes the run and returns everything the expectation
     * needs to pass or fail the test.
     *
     * @return array{result: RunResult, comparison: ?Comparison, gate: array{passed: bool, failures: array<int, string>}, run: EvalRun}
     */
    public function run(): array
    {
        $evaluation = $this->buildEvaluation();

        $runner = new Runner(
            samplesOverride: $this->samples,
            concurrencyOverride: $this->concurrency,
        );

        $result = $runner->run($evaluation);
        $run = $result->run->fresh();

        $baseline = EvalRun::baselineFor($run->suite);
        $comparison = $baseline === null ? null : Comparator::compare($run, $baseline);

        $gate = ($this->gate ?? $evaluation->gatePolicy() ?? Gate::fromConfig())
            ->evaluate($result, $comparison);

        return ['result' => $result, 'comparison' => $comparison, 'gate' => $gate, 'run' => $run];
    }

    private function buildEvaluation(): Evaluation
    {
        if ($this->evaluationClass !== null) {
            return app($this->evaluationClass);
        }

        $dataset = $this->resolveDataset();

        if ($this->assertions === [] && $this->judges === []) {
            throw new InvalidArgumentException(
                'toPassEval() needs at least one ->assert(...) or ->judge(...) — otherwise nothing is being evaluated.'
            );
        }

        return new InlineEvaluation(
            evalTarget: $this->target,
            evalDataset: $dataset,
            suiteName: $this->suiteName(),
            assertions: $this->assertions,
            judges: $this->judges,
            acrossMatrix: $this->across,
            samples: $this->samples ?? 1,
        );
    }

    private function resolveDataset(): Dataset
    {
        if ($this->fromConversations) {
            $agentClass = is_object($this->target) ? $this->target::class : $this->target;

            if (! is_string($agentClass) || ! class_exists($agentClass)) {
                throw new InvalidArgumentException('fromConversations() requires an agent class target.');
            }

            $dataset = Dataset::fromConversations($agentClass)->latest();

            return $this->take === null ? $dataset : $dataset->take($this->take);
        }

        return $this->dataset
            ?? throw new DatasetException('toPassEval() needs a dataset — call ->dataset(...) or ->fromConversations().');
    }

    /** @internal */
    public function suiteName(): string
    {
        return $this->suite ?? $this->defaultSuite;
    }
}
