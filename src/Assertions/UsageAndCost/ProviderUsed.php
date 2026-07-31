<?php

namespace Vizra\Evals\Assertions\UsageAndCost;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class ProviderUsed implements Assertion
{
    public function __construct(private readonly Lab|string $provider) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $expected = $this->provider instanceof Lab ? $this->provider->value : $this->provider;

        return AssertionResult::bool(
            'provider_used',
            $response->meta->provider === $expected,
            $expected,
            $response->meta->provider ?? 'unknown',
            'Response was produced by "'.($response->meta->provider ?? 'unknown')."\", expected \"{$expected}\".",
        );
    }
}
