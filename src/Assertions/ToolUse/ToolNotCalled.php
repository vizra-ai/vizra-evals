<?php

namespace Vizra\Evals\Assertions\ToolUse;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class ToolNotCalled implements Assertion
{
    public function __construct(private readonly string $name) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $called = $response->toolCalls->pluck('name')->all();

        return AssertionResult::bool(
            'tool_not_called',
            ! in_array($this->name, $called, true),
            "not {$this->name}",
            $called === [] ? 'no tool calls' : implode(', ', $called),
            "Tool \"{$this->name}\" was called but should not have been.",
        );
    }
}
