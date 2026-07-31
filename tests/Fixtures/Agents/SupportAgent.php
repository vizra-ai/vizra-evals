<?php

namespace Vizra\Evals\Tests\Fixtures\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class SupportAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a helpful customer support agent for an online store.';
    }
}
