<?php

namespace Vizra\Evals\Judge;

/**
 * A typed judge verdict. Reasoning is the debugging payload — it is always
 * persisted alongside the score.
 */
final class Judgment
{
    /**
     * @param  array<string, int|float>  $dimensions
     */
    public function __construct(
        public readonly int|float $score,
        public readonly string $reasoning,
        public readonly array $dimensions = [],
    ) {}
}
