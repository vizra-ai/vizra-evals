<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class NotEmpty implements Assertion
{
    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        return AssertionResult::bool(
            'not_empty',
            trim($response->text) !== '',
            'a non-empty response',
            $response->text,
            'Response text is empty.',
        );
    }
}
