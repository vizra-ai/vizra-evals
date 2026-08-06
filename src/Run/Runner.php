<?php

namespace Vizra\Evals\Run;

use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Evaluation;
use Vizra\Evals\Events\RowEvaluated;
use Vizra\Evals\Events\RunFinished;
use Vizra\Evals\Events\RunStarted;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Run\Executors\ConcurrentExecutor;
use Vizra\Evals\Run\Executors\Executor;
use Vizra\Evals\Run\Executors\SequentialExecutor;
use Vizra\Evals\Support\Combo;
use Vizra\Evals\Support\DryRun;
use Vizra\Evals\Support\Git;
use Vizra\Evals\Support\Pricing;
use Vizra\Evals\Target;

class Runner
{
    /** @var array<int, string> */
    private array $warnings = [];

    public function __construct(
        private readonly ?int $samplesOverride = null,
        private readonly ?int $concurrencyOverride = null,
        private readonly bool $dryRun = false,
        private readonly ?Closure $onSample = null,
        /**
         * Run these rows instead of the ones the evaluation declares.
         *
         * Lets a suite be run against a variant of its dataset — a version
         * edited in Vizra Cloud, or a subset a developer is iterating on —
         * without touching the eval class. Everything else is unchanged: the
         * same assertions, the same judge, the same gate.
         */
        private readonly ?Dataset $datasetOverride = null,
    ) {}

    public function run(Evaluation $evaluation): RunResult
    {
        $target = Target::from($evaluation->target());

        if ($this->dryRun) {
            DryRun::fake($evaluation, $target);
        }

        $combos = Combo::matrix($evaluation->across());
        $samples = max(1, $this->samplesOverride ?? $evaluation->samples);
        $concurrency = $this->concurrency();

        $rows = ($this->datasetOverride ?? $evaluation->dataset())->rows()
            ->map(function (Row $raw) use ($evaluation) {
                $row = $evaluation->transform($raw);

                // Identity is the raw row's — a transform must not change
                // which logical row this is.
                return $row->hash === $raw->hash ? $row : $row->withHash($raw->hash);
            })
            ->values()
            ->all();

        $run = EvalRun::create([
            'suite' => $evaluation->suite(),
            'name' => $evaluation->name(),
            'status' => EvalRun::STATUS_RUNNING,
            ...collect(Git::capture())->mapWithKeys(fn ($value, $key) => ['git_'.$key => $value])->all(),
            'config' => [
                'samples' => $samples,
                'concurrency' => $concurrency,
                'planned_rows' => count($rows),
                'planned_samples' => count($rows) * count($combos) * $samples,
                'across' => array_map(fn (Combo $combo) => $combo->toArray(), $combos),
                'dry_run' => $this->dryRun,
                'gate' => ($evaluation->gatePolicy() ?? Gate::fromConfig())->toArray(),
                'judge' => [
                    'agent' => config('evals.judge.agent'),
                    'provider' => config('evals.judge.provider'),
                    'model' => config('evals.judge.model'),
                ],
            ],
            'started_at' => now(),
        ]);

        event(new RunStarted($run));

        try {
            $this->execute($evaluation, $run, $target, $rows, $combos, $samples, $concurrency);
        } catch (Throwable $e) {
            $run->update([
                'status' => EvalRun::STATUS_FAILED,
                'summary' => ['failure' => $e::class.': '.$e->getMessage()],
                'finished_at' => now(),
            ]);

            throw $e;
        }

        $result = RunResult::aggregate($run);

        event(new RunFinished($run, $result));

        return $result;
    }

    /**
     * @param  array<int, Row>  $rows
     * @param  array<int, Combo>  $combos
     */
    private function execute(
        Evaluation $evaluation,
        EvalRun $run,
        Target $target,
        array $rows,
        array $combos,
        int $samples,
        int $concurrency,
    ): void {
        $jobs = [];

        foreach ($rows as $row) {
            foreach ($combos as $combo) {
                for ($sampleIndex = 0; $sampleIndex < $samples; $sampleIndex++) {
                    $jobs[] = [$row, $combo, $sampleIndex];
                }
            }
        }

        $executor = $this->makeExecutor($concurrency, $target);

        foreach (array_chunk($jobs, max(1, $concurrency)) as $wave) {
            $tasks = array_map(
                fn (array $job) => self::invocationTask($evaluation->target(), $job[0], $job[1]),
                $wave,
            );

            $outcomes = $executor->run($tasks);

            // Assertions, judges, and persistence always happen in the
            // parent process, sequentially, from the returned outcomes.
            foreach ($outcomes as $i => $outcome) {
                [$row, $combo, $sampleIndex] = $wave[$i];

                $rowResult = $this->processOutcome($evaluation, $run, $row, $combo, $sampleIndex, $outcome);

                event(new RowEvaluated($run, $rowResult));

                if ($this->onSample !== null) {
                    ($this->onSample)($rowResult);
                }
            }
        }
    }

    /**
     * Build a self-contained task closure: it re-resolves the Target from
     * the raw target value so it can run in a separate process.
     *
     * @return Closure(): SampleOutcome
     */
    private static function invocationTask(mixed $rawTarget, Row $row, Combo $combo): Closure
    {
        return function () use ($rawTarget, $row, $combo): SampleOutcome {
            $start = hrtime(true);

            try {
                $response = Target::from($rawTarget)->invoke($row, $combo);

                return SampleOutcome::success($response, (hrtime(true) - $start) / 1e6);
            } catch (Throwable $e) {
                return SampleOutcome::error($e::class, $e->getMessage(), (hrtime(true) - $start) / 1e6);
            }
        };
    }

    private function processOutcome(
        Evaluation $evaluation,
        EvalRun $run,
        Row $row,
        Combo $combo,
        int $sampleIndex,
        SampleOutcome $outcome,
    ): EvalRowResult {
        $base = [
            'run_id' => $run->id,
            'row_hash' => $row->hash,
            'row_index' => $row->index,
            'sample_index' => $sampleIndex,
            'combo' => $combo->toArray(),
            'combo_key' => $combo->key(),
            'input' => $row->isMultiTurn()
                ? json_encode([...$row->messages, ['role' => 'user', 'content' => $row->input]], JSON_UNESCAPED_UNICODE)
                : $row->input,
            // Kept apart from `input` above, which merges the whole exchange
            // for reading. A row's identity is hashed over input, messages and
            // expected as three separate values, so only the split form can
            // reconstruct the row — which is what lets a multi-turn row be
            // edited and exported rather than shown as an unusable blob.
            'messages' => $row->messages === [] ? null : $row->messages,
            'expected' => $row->expected === null
                ? null
                : (is_string($row->expected) ? $row->expected : json_encode($row->expected, JSON_UNESCAPED_UNICODE)),
            'meta' => $row->meta === [] ? null : $row->meta,
            'duration_ms' => (int) round($outcome->durationMs),
            'created_at' => now(),
        ];

        if ($outcome->failed()) {
            return EvalRowResult::create([
                ...$base,
                'status' => EvalRowResult::STATUS_ERROR,
                'error' => "{$outcome->errorClass}: {$outcome->error}",
            ]);
        }

        $response = $outcome->response;
        $collector = $evaluation->startSample($row->withMeta(['_duration_ms' => $outcome->durationMs]), $response);

        try {
            $evaluationError = null;

            try {
                $evaluation->evaluate($row, $response);
            } catch (Throwable $e) {
                $evaluationError = $e;
            }

            $assertions = $collector->assertions();
            $skipJudges = $evaluationError !== null
                || ($collector->gateFailed() && config('evals.judge.skip_on_gate_failure', true));

            foreach ($collector->judges() as $builder) {
                $assertions[] = $skipJudges ? $builder->skipped() : $builder->execute();
            }
        } finally {
            $evaluation->endSample();
        }

        if ($evaluationError !== null) {
            $verdict = ['status' => EvalRowResult::STATUS_ERROR, 'score' => null];
            $error = $evaluationError::class.': '.$evaluationError->getMessage();
        } else {
            $verdict = Scoring::sample($assertions);
            $error = null;
        }

        // With no across() combo, record the provider/model that actually
        // served the response so cross-run joins stay meaningful.
        if ($combo->isNone() && ($response->meta->provider !== null || $response->meta->model !== null)) {
            $base['combo_key'] = ($response->meta->provider ?? '?').'/'.($response->meta->model ?? '?');
        }

        if (Pricing::shouldWarn($response->meta->provider, $response->meta->model)) {
            $this->warnings[] = 'No pricing configured for '
                .$response->meta->provider.'/'.$response->meta->model
                .' — costs will be null. Add it to config/evals.php.';
        }

        return DB::transaction(function () use ($base, $verdict, $error, $response, $assertions) {
            $rowResult = EvalRowResult::create([
                ...$base,
                'status' => $verdict['status'],
                'score' => $verdict['score'],
                'error' => $error,
                'response_text' => $response->text,
                'structured_output' => $response instanceof StructuredAgentResponse ? $response->structured : null,
                'tool_calls' => $response->toolCalls->map(fn ($call) => $call->toArray())->all() ?: null,
                'usage' => $response->usage->toArray(),
                'finish_reason' => $response->steps->last()?->finishReason?->value,
                'cost' => Pricing::cost($response->usage, $response->meta->provider, $response->meta->model),
            ]);

            foreach ($assertions as $assertion) {
                $rowResult->assertionResults()->create([
                    ...$assertion->toArray(),
                    'created_at' => now(),
                ]);
            }

            return $rowResult;
        });
    }

    private function concurrency(): int
    {
        if ($this->dryRun) {
            // Agent fakes live in this process's memory — child processes
            // would hit the real network. Dry runs are always sequential.
            return 1;
        }

        return max(1, $this->concurrencyOverride ?? (int) config('evals.concurrency', 5));
    }

    private function makeExecutor(int $concurrency, Target $target): Executor
    {
        if ($concurrency <= 1) {
            return new SequentialExecutor;
        }

        return new ConcurrentExecutor($concurrency, fallback: function (string $reason): void {
            $this->warnings[] = 'Falling back to sequential execution: '.$reason;
        });
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }
}
