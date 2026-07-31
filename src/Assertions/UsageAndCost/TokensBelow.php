<?php

namespace Vizra\Evals\Assertions\UsageAndCost;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class TokensBelow implements Assertion
{
    public function __construct(private readonly int $max) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $usage = $response->usage;

        $total = $usage->promptTokens
            + $usage->completionTokens
            + $usage->cacheWriteInputTokens
            + $usage->cacheReadInputTokens
            + $usage->reasoningTokens;

        return AssertionResult::bool(
            'tokens_below',
            $total < $this->max,
            "fewer than {$this->max} tokens",
            "{$total} tokens",
            "Sample used {$total} tokens (limit {$this->max}).",
        );
    }
}
