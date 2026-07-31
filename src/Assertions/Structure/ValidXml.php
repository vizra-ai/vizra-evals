<?php

namespace Vizra\Evals\Assertions\Structure;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class ValidXml implements Assertion
{
    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $valid = simplexml_load_string($response->text) !== false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return AssertionResult::bool(
            'valid_xml',
            $valid,
            'valid XML',
            $response->text,
            'Response is not valid XML.',
        );
    }
}
