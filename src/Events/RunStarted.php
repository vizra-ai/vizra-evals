<?php

namespace Vizra\Evals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vizra\Evals\Models\EvalRun;

class RunStarted
{
    use Dispatchable;

    public function __construct(
        public readonly EvalRun $run,
    ) {}
}
