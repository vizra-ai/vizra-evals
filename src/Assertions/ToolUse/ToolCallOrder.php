<?php

namespace Vizra\Evals\Assertions\ToolUse;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

/**
 * Passes when the given tool names appear in the response's tool calls in
 * the given relative order (other calls may be interleaved).
 */
class ToolCallOrder implements Assertion
{
    /** @param array<int, string> $names */
    public function __construct(private readonly array $names) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $called = $response->toolCalls->pluck('name')->all();
        $position = 0;

        foreach ($called as $name) {
            if ($position < count($this->names) && $name === $this->names[$position]) {
                $position++;
            }
        }

        return AssertionResult::bool(
            'tool_call_order',
            $position === count($this->names),
            implode(' → ', $this->names),
            $called === [] ? 'no tool calls' : implode(' → ', $called),
            'Tool calls did not occur in the expected order.',
        );
    }
}
