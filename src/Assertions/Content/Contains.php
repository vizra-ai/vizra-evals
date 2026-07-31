<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class Contains implements Assertion
{
    public function __construct(
        private readonly string $needle,
        private readonly bool $ignoreCase = true,
    ) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $found = $this->ignoreCase
            ? str_contains(mb_strtolower($response->text), mb_strtolower($this->needle))
            : str_contains($response->text, $this->needle);

        return AssertionResult::bool(
            'contains',
            $found,
            $this->needle,
            $response->text,
            $found ? '' : "Response does not contain \"{$this->needle}\".",
        );
    }
}
