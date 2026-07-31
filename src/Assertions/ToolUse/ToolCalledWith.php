<?php

namespace Vizra\Evals\Assertions\ToolUse;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

/**
 * Passes when the named tool was called at least once with arguments that
 * contain the given subset (extra argument keys are allowed).
 */
class ToolCalledWith implements Assertion
{
    public function __construct(
        private readonly string $name,
        private readonly array $argumentsSubset,
    ) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $calls = $response->toolCalls->where('name', $this->name);

        if ($calls->isEmpty()) {
            return AssertionResult::fail(
                'tool_called_with',
                ['name' => $this->name, 'arguments' => $this->argumentsSubset],
                'tool was never called',
                "Tool \"{$this->name}\" was not called.",
            );
        }

        foreach ($calls as $call) {
            if ($this->matchesSubset($call->arguments, $this->argumentsSubset)) {
                return AssertionResult::pass(
                    'tool_called_with',
                    ['name' => $this->name, 'arguments' => $this->argumentsSubset],
                    $call->arguments,
                );
            }
        }

        return AssertionResult::fail(
            'tool_called_with',
            ['name' => $this->name, 'arguments' => $this->argumentsSubset],
            $calls->map(fn ($call) => $call->arguments)->all(),
            "Tool \"{$this->name}\" was called, but never with the expected arguments.",
        );
    }

    private function matchesSubset(array $actual, array $subset): bool
    {
        foreach ($subset as $key => $value) {
            if (! array_key_exists($key, $actual)) {
                return false;
            }

            if (is_array($value)) {
                if (! is_array($actual[$key]) || ! $this->matchesSubset($actual[$key], $value)) {
                    return false;
                }
            } elseif ($actual[$key] != $value) {
                return false;
            }
        }

        return true;
    }
}
