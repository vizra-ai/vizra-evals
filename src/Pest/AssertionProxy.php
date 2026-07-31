<?php

namespace Vizra\Evals\Pest;

use BadMethodCallException;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Evaluation;

/**
 * The `$a` handed to toPassEval's ->assert() closure. Method names are the
 * Evaluation helpers without the assert prefix — contains(), toolCalled(),
 * costBelow(), finishReason(), … — chainable, with gate()/weight() applying
 * to the most recent assertion:
 *
 *     $a->notEmpty()->gate()
 *       ->toolCalled('lookup_order')
 *       ->costBelow(0.01);
 */
final class AssertionProxy
{
    private ?AssertionResult $last = null;

    public function __construct(private readonly Evaluation $evaluation) {}

    public function gate(): self
    {
        $this->last?->gate();

        return $this;
    }

    public function weight(float $weight): self
    {
        $this->last?->weight($weight);

        return $this;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): self
    {
        $helper = 'assert'.ucfirst($method);

        if (! method_exists($this->evaluation, $helper)) {
            throw new BadMethodCallException(
                "Unknown eval assertion [{$method}] — expected an Evaluation helper like contains, toolCalled, costBelow (assert prefix omitted)."
            );
        }

        $this->last = $this->evaluation->runAssertion($helper, $arguments);

        return $this;
    }
}
