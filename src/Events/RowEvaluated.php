<?php

namespace Vizra\Evals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Models\EvalRun;

class RowEvaluated
{
    use Dispatchable;

    public function __construct(
        public readonly EvalRun $run,
        public readonly EvalRowResult $rowResult,
    ) {}
}
