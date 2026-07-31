<?php

namespace Vizra\Evals\Assertions;

use Vizra\Evals\Judge\JudgeBuilder;

/**
 * Per-sample store for assertion results and deferred judge builders.
 * A fresh collector is created for every sample, so assertion state can
 * never leak across samples (the manual-reset footgun in the old ADK).
 */
final class SampleCollector
{
    /** @var array<int, AssertionResult> */
    private array $assertions = [];

    /** @var array<int, JudgeBuilder> */
    private array $judges = [];

    public function record(AssertionResult $result): AssertionResult
    {
        $this->assertions[] = $result;

        return $result;
    }

    public function deferJudge(JudgeBuilder $builder): JudgeBuilder
    {
        $this->judges[] = $builder;

        return $builder;
    }

    /**
     * @return array<int, AssertionResult>
     */
    public function assertions(): array
    {
        return $this->assertions;
    }

    /**
     * @return array<int, JudgeBuilder>
     */
    public function judges(): array
    {
        return $this->judges;
    }

    public function gateFailed(): bool
    {
        foreach ($this->assertions as $assertion) {
            if ($assertion->isGate && in_array($assertion->status, [AssertionResult::FAILED, AssertionResult::ERROR], true)) {
                return true;
            }
        }

        return false;
    }
}
