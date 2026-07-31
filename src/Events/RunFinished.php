<?php

namespace Vizra\Evals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Run\RunResult;

class RunFinished
{
    use Dispatchable;

    public function __construct(
        public readonly EvalRun $run,
        public readonly RunResult $result,
    ) {}
}
