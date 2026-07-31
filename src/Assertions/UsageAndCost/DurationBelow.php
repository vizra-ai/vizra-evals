<?php

namespace Vizra\Evals\Assertions\UsageAndCost;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

/**
 * Asserts on wall-clock duration of the target invocation, which the Runner
 * injects into the row meta as `_duration_ms` before evaluate() runs.
 */
class DurationBelow implements Assertion
{
    public function __construct(private readonly float $maxMs) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $duration = $row->meta('_duration_ms');

        if ($duration === null) {
            return AssertionResult::error('duration_below', 'No duration was recorded for this sample.');
        }

        return AssertionResult::bool(
            'duration_below',
            $duration < $this->maxMs,
            sprintf('< %.0f ms', $this->maxMs),
            sprintf('%.0f ms', $duration),
            sprintf('Sample took %.0f ms (limit %.0f ms).', $duration, $this->maxMs),
        );
    }
}
