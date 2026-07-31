<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class WordCountBetween implements Assertion
{
    public function __construct(
        private readonly int $min,
        private readonly int $max,
    ) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $count = count(preg_split('/\s+/u', trim($response->text), -1, PREG_SPLIT_NO_EMPTY));

        return AssertionResult::bool(
            'word_count_between',
            $count >= $this->min && $count <= $this->max,
            "{$this->min}–{$this->max} words",
            "{$count} words",
            "Response word count {$count} is outside {$this->min}–{$this->max}.",
        );
    }
}
