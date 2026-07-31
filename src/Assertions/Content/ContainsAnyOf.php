<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class ContainsAnyOf implements Assertion
{
    /** @param array<int, string> $needles */
    public function __construct(
        private readonly array $needles,
        private readonly bool $ignoreCase = true,
    ) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $haystack = $this->ignoreCase ? mb_strtolower($response->text) : $response->text;

        foreach ($this->needles as $needle) {
            if (str_contains($haystack, $this->ignoreCase ? mb_strtolower($needle) : $needle)) {
                return AssertionResult::pass('contains_any_of', $this->needles, $response->text);
            }
        }

        return AssertionResult::fail(
            'contains_any_of',
            $this->needles,
            $response->text,
            'Response contains none of: '.implode(', ', $this->needles),
        );
    }
}
