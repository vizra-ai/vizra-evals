<?php

namespace Vizra\Evals\Assertions;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Dataset\Row;

/**
 * A response-inspecting assertion. Built-ins are small invokable classes
 * implementing this contract; userland assertions plug in the same way via
 * $this->assertWith(new MyAssertion(...)).
 */
interface Assertion
{
    public function __invoke(AgentResponse $response, Row $row): AssertionResult;
}
