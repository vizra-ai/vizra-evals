<?php

namespace Vizra\Evals\Assertions\Structure;

use Closure;
use Illuminate\Support\Arr;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

/**
 * Assert on a structured-output key: pass a literal value for equality, or
 * a Closure(mixed $value): bool for a custom check.
 */
class OutputKey implements Assertion
{
    public function __construct(
        private readonly string $key,
        private readonly mixed $expectation,
    ) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        if (! $response instanceof StructuredAgentResponse) {
            return AssertionResult::fail(
                'output_key',
                $this->key,
                $response->text,
                'Response is not a structured-output response.',
            );
        }

        if (! Arr::has($response->structured, $this->key)) {
            return AssertionResult::fail(
                'output_key',
                $this->key,
                $response->structured,
                "Structured output has no \"{$this->key}\" key.",
            );
        }

        $value = Arr::get($response->structured, $this->key);

        if ($this->expectation instanceof Closure) {
            return AssertionResult::bool(
                'output_key',
                (bool) ($this->expectation)($value),
                "callback on \"{$this->key}\"",
                $value,
                "Structured output key \"{$this->key}\" failed the callback check.",
            );
        }

        return AssertionResult::bool(
            'output_key',
            $value == $this->expectation,
            $this->expectation,
            $value,
            "Structured output key \"{$this->key}\" does not equal the expected value.",
        );
    }
}
