<?php

namespace Vizra\Evals\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Vizra\Evals\Models\EvalRun;

class BaselineCommand extends Command
{
    protected $signature = 'evals:baseline {run : The run id to mark as its suite\'s baseline}';

    protected $description = 'Mark an evaluation run as the baseline for its suite';

    public function handle(): int
    {
        $run = EvalRun::find($this->argument('run'));

        if ($run === null) {
            $this->components->error('No run found with id ['.$this->argument('run').'].');

            return self::FAILURE;
        }

        if ($run->status !== EvalRun::STATUS_COMPLETED) {
            $this->components->error("Run [{$run->id}] has status [{$run->status}]; only completed runs can be baselines.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($run) {
            EvalRun::where('suite', $run->suite)->where('is_baseline', true)->update(['is_baseline' => false]);
            $run->update(['is_baseline' => true]);
        });

        $this->components->info("Run [{$run->id}] is now the baseline for [{$run->suite}].");

        return self::SUCCESS;
    }
}
