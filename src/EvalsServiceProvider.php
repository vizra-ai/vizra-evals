<?php

namespace Vizra\Evals;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Vizra\Evals\Console\BaselineCommand;
use Vizra\Evals\Console\CalibrateCommand;
use Vizra\Evals\Console\MakeCommand;
use Vizra\Evals\Console\RunCommand;
use Vizra\Evals\Console\RunnerCommand;

class EvalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/evals.php', 'evals');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/evals.php' => config_path('evals.php'),
            ], 'evals-config');

            /*
             * Migrations are loaded from the package and deliberately NOT
             * publishable.
             *
             * Publishing copies them into the app under a fresh timestamp
             * while loadMigrationsFrom above keeps serving the originals, so
             * `migrate` runs both and the second fails on a table that already
             * exists — a broken install caused by following an instruction the
             * package itself offered.
             *
             * The reason anyone wanted to edit them is the table names, and
             * EVALS_TABLE_PREFIX already covers that: every migration here
             * reads config('evals.table_prefix').
             */
            $this->publishes([
                __DIR__.'/../stubs/evaluation.stub' => base_path('stubs/vizra-evals/evaluation.stub'),
            ], 'evals-stubs');

            $this->commands([
                RunCommand::class,
                MakeCommand::class,
                BaselineCommand::class,
                CalibrateCommand::class,
                RunnerCommand::class,
            ]);
        }

        $this->scheduleRunner();
    }

    /**
     * Register the cloud runner check on the app's own scheduler.
     *
     * Saves the customer a line in routes/console.php, which is one more
     * chance to skip a step and then wonder why the Run button does nothing.
     * The cron that already runs schedule:run picks this up with no changes.
     *
     * Only when a cloud key exists. A package must not add a task that makes
     * an outbound network call every minute to an app that never asked for
     * one — with no key configured, nothing is registered and nothing runs.
     *
     * withoutOverlapping because an eval takes minutes and the schedule fires
     * every minute; without it, a long run would accumulate a new process on
     * top of itself every sixty seconds.
     */
    private function scheduleRunner(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Decided here rather than at boot so it reflects configuration
            // as it stands when the scheduler actually runs, not whatever was
            // loaded first.
            if (! config('evals.cloud.auto_schedule', true) || ! config('evals.cloud.key')) {
                return;
            }

            $schedule->command(RunnerCommand::class)
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();
        });
    }
}
