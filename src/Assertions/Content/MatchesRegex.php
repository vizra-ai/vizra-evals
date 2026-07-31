<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class MatchesRegex implements Assertion
{
    public function __construct(private readonly string $pattern) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $result = @preg_match($this->pattern, $response->text);

        if ($result === false) {
            return AssertionResult::error('matches_regex', "Invalid regex pattern \"{$this->pattern}\".");
        }

        return AssertionResult::bool(
            'matches_regex',
            $result === 1,
            $this->pattern,
            $response->text,
            "Response does not match {$this->pattern}.",
        );
    }
}
