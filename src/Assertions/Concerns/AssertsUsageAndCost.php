<?php

namespace Vizra\Evals\Assertions\Concerns;

use Laravel\Ai\Enums\Lab;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Assertions\UsageAndCost\CacheHitRateAbove;
use Vizra\Evals\Assertions\UsageAndCost\CostBelow;
use Vizra\Evals\Assertions\UsageAndCost\DurationBelow;
use Vizra\Evals\Assertions\UsageAndCost\ModelUsed;
use Vizra\Evals\Assertions\UsageAndCost\ProviderUsed;
use Vizra\Evals\Assertions\UsageAndCost\TokensBelow;

trait AssertsUsageAndCost
{
    protected function assertCostBelow(float $maxUsd): AssertionResult
    {
        return $this->assertWith(new CostBelow($maxUsd));
    }

    protected function assertTokensBelow(int $max): AssertionResult
    {
        return $this->assertWith(new TokensBelow($max));
    }

    protected function assertCacheHitRateAbove(float $rate): AssertionResult
    {
        return $this->assertWith(new CacheHitRateAbove($rate));
    }

    protected function assertDurationBelow(float $maxMs): AssertionResult
    {
        return $this->assertWith(new DurationBelow($maxMs));
    }

    protected function assertModelUsed(string $model): AssertionResult
    {
        return $this->assertWith(new ModelUsed($model));
    }

    protected function assertProviderUsed(Lab|string $provider): AssertionResult
    {
        return $this->assertWith(new ProviderUsed($provider));
    }
}
