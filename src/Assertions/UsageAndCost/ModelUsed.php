<?php

namespace Vizra\Evals\Assertions\UsageAndCost;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class ModelUsed implements Assertion
{
    public function __construct(private readonly string $model) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        return AssertionResult::bool(
            'model_used',
            $response->meta->model === $this->model,
            $this->model,
            $response->meta->model ?? 'unknown',
            'Response was produced by "'.($response->meta->model ?? 'unknown')."\", expected \"{$this->model}\".",
        );
    }
}
