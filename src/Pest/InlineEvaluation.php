<?php

namespace Vizra\Evals\Pest;

use Closure;
use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Evaluation;
use Vizra\Evals\Judge\JudgeBuilder;

/**
 * The Evaluation the toPassEval expectation assembles from its fluent
 * configuration — inline closures instead of a userland subclass.
 */
final class InlineEvaluation extends Evaluation
{
    /**
     * @param  array<int, Closure(AssertionProxy, Row, AgentResponse): mixed>  $assertions
     * @param  array<int, Closure(Evaluation): mixed>  $judges
     */
    public function __construct(
        private readonly mixed $evalTarget,
        private readonly Dataset $evalDataset,
        private readonly string $suiteName,
        private readonly array $assertions,
        private readonly array $judges,
        private readonly array $acrossMatrix,
        int $samples,
    ) {
        $this->samples = $samples;
    }

    public function target(): mixed
    {
        return $this->evalTarget;
    }

    public function dataset(): Dataset
    {
        return $this->evalDataset;
    }

    public function across(): array
    {
        return $this->acrossMatrix;
    }

    public function name(): string
    {
        return $this->suiteName;
    }

    public function suite(): string
    {
        return $this->suiteName;
    }

    public function evaluate(Row $row, AgentResponse $response): void
    {
        $proxy = new AssertionProxy($this);

        foreach ($this->assertions as $assert) {
            $assert($proxy, $row, $response);
        }

        foreach ($this->judges as $configure) {
            $configure($this);
        }
    }

    /** @internal used by the judge configurators */
    public function configureJudge(): JudgeBuilder
    {
        return $this->judge();
    }
}
