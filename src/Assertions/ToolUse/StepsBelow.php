<?php

namespace Vizra\Evals\Assertions\ToolUse;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class StepsBelow implements Assertion
{
    public function __construct(private readonly int $max) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $steps = $response->steps->count();

        return AssertionResult::bool(
            'steps_below',
            $steps < $this->max,
            "fewer than {$this->max} steps",
            "{$steps} steps",
            "Agent took {$steps} steps (limit {$this->max}).",
        );
    }
}
