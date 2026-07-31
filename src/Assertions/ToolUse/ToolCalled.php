<?php

namespace Vizra\Evals\Assertions\ToolUse;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class ToolCalled implements Assertion
{
    public function __construct(private readonly string $name) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $called = $response->toolCalls->pluck('name')->all();

        return AssertionResult::bool(
            'tool_called',
            in_array($this->name, $called, true),
            $this->name,
            $called === [] ? 'no tool calls' : implode(', ', $called),
            "Tool \"{$this->name}\" was not called.",
        );
    }
}
