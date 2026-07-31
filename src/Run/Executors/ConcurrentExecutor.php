<?php

namespace Vizra\Evals\Run\Executors;

use Closure;
use Illuminate\Support\Facades\Concurrency;
use Throwable;

/**
 * Runs a wave of target invocations in parallel via Laravel's Concurrency
 * facade (process driver): each task executes in a fresh child process and
 * returns a serialized SampleOutcome. If the tasks can't be serialized
 * (e.g. a Closure target capturing unserializable state), we warn once and
 * fall back to sequential execution.
 */
class ConcurrentExecutor implements Executor
{
    private bool $degraded = false;

    /** @param Closure(string): void $fallback */
    public function __construct(
        private readonly int $concurrency,
        private readonly Closure $fallback,
    ) {}

    public function run(array $tasks): array
    {
        if ($this->degraded || count($tasks) <= 1) {
            return (new SequentialExecutor)->run($tasks);
        }

        try {
            return Concurrency::run($tasks);
        } catch (Throwable $e) {
            $this->degraded = true;
            ($this->fallback)($e->getMessage());

            return (new SequentialExecutor)->run($tasks);
        }
    }
}
