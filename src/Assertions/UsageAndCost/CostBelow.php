<?php

namespace Vizra\Evals\Assertions\UsageAndCost;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Support\Pricing;

class CostBelow implements Assertion
{
    public function __construct(private readonly float $maxUsd) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $cost = Pricing::cost($response->usage, $response->meta->provider, $response->meta->model);

        if ($cost === null) {
            return AssertionResult::error(
                'cost_below',
                'Cost is unknown: no pricing configured for '
                    .($response->meta->provider ?? '?').'/'.($response->meta->model ?? '?')
                    .' in config/evals.php.',
            );
        }

        return AssertionResult::bool(
            'cost_below',
            $cost < $this->maxUsd,
            sprintf('< $%.4f', $this->maxUsd),
            sprintf('$%.6f', $cost),
            sprintf('Sample cost $%.6f exceeds the $%.4f limit.', $cost, $this->maxUsd),
        );
    }
}
