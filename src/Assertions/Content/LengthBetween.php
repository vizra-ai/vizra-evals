<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class LengthBetween implements Assertion
{
    public function __construct(
        private readonly int $min,
        private readonly int $max,
    ) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $length = mb_strlen($response->text);

        return AssertionResult::bool(
            'length_between',
            $length >= $this->min && $length <= $this->max,
            "{$this->min}–{$this->max} characters",
            "{$length} characters",
            "Response length {$length} is outside {$this->min}–{$this->max}.",
        );
    }
}
