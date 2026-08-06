<?php

namespace Vizra\Evals\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Throwable;
use Vizra\Evals\Cloud\Reporter;
use Vizra\Evals\Events\RowEvaluated;
use Vizra\Evals\Models\EvalRun;

/**
 * Picks up runs asked for from the Vizra Cloud dashboard.
 *
 * The cloud holds no model keys, no database credentials and no code, so it
 * cannot run an eval itself. Instead a click there queues a request, and this
 * command — running inside the app being evaluated — collects it and runs the
 * suite against this environment.
 *
 * Every call it makes is outbound, so nothing has to be exposed: no route, no
 * public hostname, no firewall rule. It works from a laptop, from staging
 * behind a VPN, or from inside a container.
 *
 * Add one line to routes/console.php and the cron that already runs
 * schedule:run does the rest:
 *
 *     Schedule::command('evals:runner')->everyMinute();
 */
class RunnerCommand extends Command
{
    protected $signature = 'evals:runner';

    protected $description = 'Run any evals requested from the Vizra Cloud dashboard';

    /**
     * When this runner last told the cloud it was still working.
     *
     * Null until the first sample completes, so the first piece of real
     * progress always reports rather than waiting out an interval.
     */
    private ?Carbon $lastBeat = null;

    /** How long the cloud will wait between heartbeats before reclaiming. */
    private const DEFAULT_HEARTBEAT_SECONDS = 300;

    public function handle(): int
    {
        $reporter = new Reporter;

        if (! $reporter->configured()) {
            $this->components->error('Vizra Cloud is not configured. Set VIZRA_CLOUD_KEY.');

            return self::FAILURE;
        }

        $request = $this->claim();

        if ($request === null) {
            // The overwhelmingly common case, once a minute, forever. Silent
            // on purpose: this runs from cron, and a line of output every
            // minute is a log nobody can read.
            return self::SUCCESS;
        }

        $this->components->info("Running {$request['suite']} for Vizra Cloud.");

        return $this->runRequested($request, $reporter);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claim(): ?array
    {
        try {
            $response = $this->http()->get($this->endpoint('next'), ['runner' => $this->identity()]);
        } catch (Throwable $e) {
            // A scheduled check that cannot reach the cloud is not an error
            // worth failing the whole schedule:run over — the next minute
            // will try again.
            $this->components->warn('Could not reach Vizra Cloud: '.$e->getMessage());

            return null;
        }

        if ($response->status() === 204) {
            return null;
        }

        if (! $response->successful()) {
            $this->components->warn("Vizra Cloud returned HTTP {$response->status()}.");

            return null;
        }

        return $response->json('request');
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function runRequested(array $request, Reporter $reporter): int
    {
        $arguments = ['suite' => $request['suite'], '--no-report' => true];

        if (($request['samples'] ?? null) !== null) {
            $arguments['--samples'] = $request['samples'];
        }

        // A variant: run these rows rather than the ones the eval declares.
        // Written to a file because that is the contract evals:run already
        // has, which keeps this to one code path a developer can reproduce
        // by hand with --dataset.
        $datasetFile = $this->writeDataset($request['dataset'] ?? null);

        if ($datasetFile !== null) {
            $arguments['--dataset'] = $datasetFile;
        }

        $this->beatWhileWorking($request);

        try {
            // --no-report above, then reported by hand below, so the run can
            // be tied back to the request that asked for it. Reporting twice
            // would be harmless (ingest is idempotent) but the second call
            // would be pure waste.
            $exit = $this->call('evals:run', $arguments);
        } catch (Throwable $e) {
            $this->reportOutcome($request, ['status' => 'failed', 'error' => $e->getMessage()]);
            $this->components->error('The eval failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($datasetFile !== null) {
                @unlink($datasetFile);
            }
        }

        // A failing gate is a successful run: the eval executed and scored
        // below the threshold, which is exactly the answer someone clicked
        // Run to get. Only a harness failure — a suite that cannot be
        // resolved, a dataset that cannot be read — means nothing ran.
        if ($exit === RunCommand::EXIT_HARNESS_FAILURE) {
            $error = "Could not run {$request['suite']}. Check the class exists in this "
                .'environment and that its dataset is readable.';

            $this->reportOutcome($request, ['status' => 'failed', 'error' => $error]);
            $this->components->error($error);

            return self::FAILURE;
        }

        $run = EvalRun::latest('id')->first();

        if ($run === null) {
            $this->reportOutcome($request, [
                'status' => 'failed',
                'error' => 'The eval finished but recorded no run.',
            ]);

            return self::FAILURE;
        }

        $outcome = $reporter->report($run);

        $this->reportOutcome($request, $outcome['ok']
            ? ['status' => 'completed', 'run_id' => $run->id]
            : ['status' => 'failed', 'error' => $outcome['message']]);

        if ($outcome['url'] !== null) {
            $this->components->info($outcome['url']);
        }

        return self::SUCCESS;
    }

    /**
     * Tell the cloud we are still working, as the run makes progress.
     *
     * A claim comes with a lease, and a lease that is not renewed is handed to
     * somebody else — which for an eval means running it, and the customer's
     * tokens, twice for one click. A large suite against a slow model
     * legitimately outlives the lease, so without this a long run is
     * guaranteed to lapse.
     *
     * Driven by RowEvaluated rather than a wall clock on purpose. The event
     * fires in this process, once per completed sample, so a heartbeat here
     * means the run actually moved. A timer would keep renewing the lease of a
     * run that had wedged against an unresponsive provider, which is the exact
     * case the lease exists to catch.
     *
     * @param  array<string, mixed>  $request
     */
    private function beatWhileWorking(array $request): void
    {
        $interval = (int) ($request['heartbeat_seconds'] ?? self::DEFAULT_HEARTBEAT_SECONDS);

        Event::listen(RowEvaluated::class, function () use ($request, $interval) {
            if ($this->lastBeat !== null && $this->lastBeat->diffInSeconds(now()) < $interval) {
                return;
            }

            $this->lastBeat = now();

            // Best effort, always. A dropped heartbeat costs at most a
            // reclaimed lease; an exception here would kill a run that is
            // otherwise going fine.
            rescue(fn () => $this->http()->post(
                $this->endpoint("{$request['id']}/heartbeat"),
                // The cloud checks this against whoever holds the claim, so a
                // runner that lost its lease cannot keep renewing one that
                // somebody else is now executing.
                ['runner' => $this->identity()],
            ), report: false);
        });
    }

    /**
     * Write a handed-down dataset to a temporary JSONL file.
     *
     * Each row keeps the `hash` the cloud assigned it. The package honours an
     * explicit hash rather than recomputing, which is what lets a row that
     * cannot be reproduced from content alone — a multi-turn row, whose prior
     * context never left this app — keep the identity it has always had and
     * still line up against the baseline.
     *
     * @param  array<int, array<string, mixed>>|null  $rows
     */
    private function writeDataset(?array $rows): ?string
    {
        if ($rows === null || $rows === []) {
            return null;
        }

        // No extension appended: tempnam() creates the file it names, and
        // writing to name+'.jsonl' instead would leave the original behind
        // on every variant run. JsonlReader reads the path it is given.
        $path = tempnam(sys_get_temp_dir(), 'vizra-dataset-');
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        fclose($handle);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $body
     */
    private function reportOutcome(array $request, array $body): void
    {
        rescue(
            fn () => $this->http()->post($this->endpoint("{$request['id']}/complete"), $body),
            report: false,
        );
    }

    private function http(): PendingRequest
    {
        return Http::withToken(config('evals.cloud.key'))
            ->timeout((int) config('evals.cloud.timeout', 15))
            ->acceptJson();
    }

    /**
     * Which machine picked the run up. Only ever read by a human working out
     * where a run actually executed.
     */
    private function identity(): string
    {
        return gethostname() ?: 'runner';
    }

    /**
     * Derived from the ingest endpoint so there is only ever one URL to
     * configure. Someone pointing VIZRA_CLOUD_ENDPOINT at a self-hosted
     * instance gets the runner endpoints on the same host for free.
     */
    private function endpoint(string $path): string
    {
        $ingest = (string) config('evals.cloud.endpoint');

        return preg_replace('#/runs$#', '/runner/'.$path, $ingest) ?? $ingest;
    }
}
