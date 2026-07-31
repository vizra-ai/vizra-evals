<?php

namespace Vizra\Evals\Assertions\Structure;

use Illuminate\Support\Arr;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class OutputHasKey implements Assertion
{
    public function __construct(private readonly string $key) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        if (! $response instanceof StructuredAgentResponse) {
            return AssertionResult::fail(
                'output_has_key',
                $this->key,
                $response->text,
                'Response is not a structured-output response.',
            );
        }

        return AssertionResult::bool(
            'output_has_key',
            Arr::has($response->structured, $this->key),
            $this->key,
            $response->structured,
            "Structured output has no \"{$this->key}\" key.",
        );
    }
}
