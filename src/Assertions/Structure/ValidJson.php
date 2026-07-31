<?php

namespace Vizra\Evals\Assertions\Structure;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class ValidJson implements Assertion
{
    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        json_decode($response->text);

        return AssertionResult::bool(
            'valid_json',
            json_last_error() === JSON_ERROR_NONE,
            'valid JSON',
            $response->text,
            'Response is not valid JSON: '.json_last_error_msg(),
        );
    }
}
