<?php

namespace Vizra\Evals\Assertions\ToolUse;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class FinishReasonIs implements Assertion
{
    public function __construct(private readonly FinishReason $expected) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        // The SDK reports finish reasons per step; the last step's reason is
        // how the response as a whole ended.
        $actual = $response->steps->last()?->finishReason;

        $note = match ($actual) {
            FinishReason::Length => ' The response was truncated (max tokens reached).',
            FinishReason::ContentFilter => ' The response was blocked by a content filter (refusal).',
            default => '',
        };

        return AssertionResult::bool(
            'finish_reason',
            $actual === $this->expected,
            $this->expected->value,
            $actual?->value ?? 'unknown (no steps recorded)',
            'Finish reason was "'.($actual?->value ?? 'unknown')."\", expected \"{$this->expected->value}\".".$note,
        );
    }
}
