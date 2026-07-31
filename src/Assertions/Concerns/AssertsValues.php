<?php

namespace Vizra\Evals\Assertions\Concerns;

use Vizra\Evals\Assertions\AssertionResult;

/**
 * Scalar comparisons. These don't inspect the response, so they bypass the
 * Assertion contract and record a result directly.
 */
trait AssertsValues
{
    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): AssertionResult
    {
        return $this->record(AssertionResult::bool(
            'equals',
            $expected == $actual,
            $expected,
            $actual,
            $message !== '' ? $message : 'Values are not equal.',
        ));
    }

    protected function assertTrue(bool $condition, string $message = ''): AssertionResult
    {
        return $this->record(AssertionResult::bool(
            'true', $condition, true, $condition, $message !== '' ? $message : 'Condition is not true.',
        ));
    }

    protected function assertFalse(bool $condition, string $message = ''): AssertionResult
    {
        return $this->record(AssertionResult::bool(
            'false', ! $condition, false, $condition, $message !== '' ? $message : 'Condition is not false.',
        ));
    }

    protected function assertGreaterThan(int|float $threshold, int|float $actual, string $message = ''): AssertionResult
    {
        return $this->record(AssertionResult::bool(
            'greater_than',
            $actual > $threshold,
            "> {$threshold}",
            $actual,
            $message !== '' ? $message : "{$actual} is not greater than {$threshold}.",
        ));
    }

    protected function assertLessThan(int|float $threshold, int|float $actual, string $message = ''): AssertionResult
    {
        return $this->record(AssertionResult::bool(
            'less_than',
            $actual < $threshold,
            "< {$threshold}",
            $actual,
            $message !== '' ? $message : "{$actual} is not less than {$threshold}.",
        ));
    }
}
