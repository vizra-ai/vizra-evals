<?php

namespace Vizra\Evals\Assertions\UsageAndCost;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

/**
 * Hit rate = cache-read tokens / (prompt tokens + cache-read tokens).
 */
class CacheHitRateAbove implements Assertion
{
    public function __construct(private readonly float $rate) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $usage = $response->usage;
        $denominator = $usage->promptTokens + $usage->cacheReadInputTokens;

        $actual = $denominator > 0 ? $usage->cacheReadInputTokens / $denominator : 0.0;

        return AssertionResult::bool(
            'cache_hit_rate_above',
            $actual > $this->rate,
            sprintf('> %.2f', $this->rate),
            sprintf('%.4f', $actual),
            sprintf('Cache hit rate %.4f is not above %.2f.', $actual, $this->rate),
        );
    }
}
