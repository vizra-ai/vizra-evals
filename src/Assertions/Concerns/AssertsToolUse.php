<?php

namespace Vizra\Evals\Assertions\Concerns;

use Laravel\Ai\Responses\Data\FinishReason;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Assertions\ToolUse\FinishReasonIs;
use Vizra\Evals\Assertions\ToolUse\NoPendingApprovals;
use Vizra\Evals\Assertions\ToolUse\StepsBelow;
use Vizra\Evals\Assertions\ToolUse\ToolCalled;
use Vizra\Evals\Assertions\ToolUse\ToolCalledWith;
use Vizra\Evals\Assertions\ToolUse\ToolCallOrder;
use Vizra\Evals\Assertions\ToolUse\ToolNotCalled;

trait AssertsToolUse
{
    protected function assertToolCalled(string $name): AssertionResult
    {
        return $this->assertWith(new ToolCalled($name));
    }

    protected function assertToolNotCalled(string $name): AssertionResult
    {
        return $this->assertWith(new ToolNotCalled($name));
    }

    /**
     * The tool must have been called at least once with arguments containing
     * the given subset (extra keys are allowed).
     */
    protected function assertToolCalledWith(string $name, array $argumentsSubset): AssertionResult
    {
        return $this->assertWith(new ToolCalledWith($name, $argumentsSubset));
    }

    /**
     * The named tools must appear in this relative order (other calls may
     * be interleaved).
     */
    protected function assertToolCallOrder(array $names): AssertionResult
    {
        return $this->assertWith(new ToolCallOrder($names));
    }

    protected function assertStepsBelow(int $max): AssertionResult
    {
        return $this->assertWith(new StepsBelow($max));
    }

    protected function assertFinishReason(FinishReason $expected): AssertionResult
    {
        return $this->assertWith(new FinishReasonIs($expected));
    }

    protected function assertNoPendingApprovals(): AssertionResult
    {
        return $this->assertWith(new NoPendingApprovals);
    }
}
