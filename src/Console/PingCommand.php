<?php

namespace Vizra\Evals\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Proves the connection to Vizra Cloud before anyone writes an evaluation.
 *
 * Setting up is short; writing an evaluation is not. That made a first run
 * that never arrived hard to diagnose — a wrong key, a wrong endpoint, a proxy,
 * a suite that did not run, a gate that stopped it, and samples switched off
 * all fail the same silent way, and you were eliminating them one at a time
 * having already spent an afternoon on the eval.
 *
 * This answers the first of those questions on its own, in about two seconds,
 * and costs nothing: no model call, no suite, nothing on any trend.
 */
class PingCommand extends Command
{
    protected $signature = 'evals:ping';

    protected $description = 'Check that Vizra Cloud is reachable with the configured key';

    public function handle(): int
    {
        $key = config('evals.cloud.key');
        $endpoint = config('evals.cloud.endpoint');

        if (! is_string($key) || $key === '') {
            $this->components->error('No key configured. Set VIZRA_CLOUD_KEY in your .env.');

            return self::FAILURE;
        }

        // Derived from the ingest endpoint, exactly as the runner does, so
        // there is still only one URL anybody has to configure.
        $url = preg_replace('#/runs$#', '/ping', (string) $endpoint) ?? $endpoint;

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('evals.cloud.timeout', 15))
                ->acceptJson()
                ->get($url);
        } catch (Throwable $e) {
            // The failure people actually hit: a typo'd host, a proxy, or a
            // self-hosted instance that is not up. Say which URL was tried,
            // because that is usually the whole answer.
            $this->components->error("Could not reach {$url}");
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $this->components->error('Vizra Cloud rejected the key.');
            $this->line('  Check VIZRA_CLOUD_KEY, and that the key has not been revoked or expired.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error("Vizra Cloud returned HTTP {$response->status()} from {$url}");

            return self::FAILURE;
        }

        $project = $response->json('project');
        $keyName = $response->json('key');
        $runs = (int) $response->json('runs', 0);

        $this->components->info("Connected to {$project}".($keyName ? " with the {$keyName} key" : '').'.');

        /*
         * The next sentence is the point of the command. "Your key works and
         * nothing has ever arrived" and "your key works and you have 40 runs"
         * are different situations needing different next steps, and until now
         * an empty dashboard looked the same either way.
         */
        if ($runs === 0) {
            $this->line('  No runs reported yet. Run one with <options=bold>php artisan evals:run</> and it will appear.');
        } else {
            $this->line("  {$runs} ".str('run')->plural($runs).' reported so far.');
        }

        return self::SUCCESS;
    }
}
