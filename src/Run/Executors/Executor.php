<?php

namespace Vizra\Evals\Run\Executors;

use Closure;
use Vizra\Evals\Run\SampleOutcome;

interface Executor
{
    /**
     * Run the given tasks and return their outcomes, keyed like the input.
     *
     * @param  array<int, Closure(): SampleOutcome>  $tasks
     * @return array<int, SampleOutcome>
     */
    public function run(array $tasks): array;
}
