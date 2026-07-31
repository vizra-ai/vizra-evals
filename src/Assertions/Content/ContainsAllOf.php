<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class ContainsAllOf implements Assertion
{
    /** @param array<int, string> $needles */
    public function __construct(
        private readonly array $needles,
        private readonly bool $ignoreCase = true,
    ) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $haystack = $this->ignoreCase ? mb_strtolower($response->text) : $response->text;

        $missing = array_values(array_filter(
            $this->needles,
            fn (string $needle) => ! str_contains($haystack, $this->ignoreCase ? mb_strtolower($needle) : $needle)
        ));

        return AssertionResult::bool(
            'contains_all_of',
            $missing === [],
            $this->needles,
            $response->text,
            $missing === [] ? '' : 'Response is missing: '.implode(', ', $missing),
        );
    }
}
