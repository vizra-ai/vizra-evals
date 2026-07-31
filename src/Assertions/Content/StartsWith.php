<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class StartsWith implements Assertion
{
    public function __construct(private readonly string $prefix) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        return AssertionResult::bool(
            'starts_with',
            str_starts_with($response->text, $this->prefix),
            $this->prefix,
            $response->text,
            "Response does not start with \"{$this->prefix}\".",
        );
    }
}
