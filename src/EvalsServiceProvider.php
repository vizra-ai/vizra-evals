<?php

namespace Vizra\Evals;

use Illuminate\Support\ServiceProvider;
use Vizra\Evals\Console\BaselineCommand;
use Vizra\Evals\Console\CalibrateCommand;
use Vizra\Evals\Console\MakeCommand;
use Vizra\Evals\Console\RunCommand;

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

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'evals-migrations');

            $this->publishes([
                __DIR__.'/../stubs/evaluation.stub' => base_path('stubs/vizra-evals/evaluation.stub'),
            ], 'evals-stubs');

            $this->commands([
                RunCommand::class,
                MakeCommand::class,
                BaselineCommand::class,
                CalibrateCommand::class,
            ]);
        }
    }
}
