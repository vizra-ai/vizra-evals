<?php

namespace Vizra\Evals\Tests\Fixtures\Evals;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Evaluation;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;

class SupportQuality extends Evaluation
{
    public int $samples = 1;

    public function target(): mixed
    {
        return SupportAgent::class;
    }

    public function dataset(): Dataset
    {
        return Dataset::fromArray([
            ['input' => 'What is your refund policy?', 'expected' => '30 days'],
            ['input' => 'Do you ship to France?', 'expected' => 'yes'],
        ]);
    }

    public function evaluate(Row $row, AgentResponse $response): void
    {
        $this->assertNotEmpty()->gate();
        $this->assertContains($row->expected());
    }
}
