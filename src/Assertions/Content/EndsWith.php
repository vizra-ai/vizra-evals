<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class EndsWith implements Assertion
{
    public function __construct(private readonly string $suffix) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        return AssertionResult::bool(
            'ends_with',
            str_ends_with(rtrim($response->text), $this->suffix),
            $this->suffix,
            $response->text,
            "Response does not end with \"{$this->suffix}\".",
        );
    }
}
