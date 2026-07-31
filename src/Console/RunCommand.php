<?php

namespace Vizra\Evals\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Vizra\Evals\Evaluation;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Run\Comparator;
use Vizra\Evals\Run\Comparison;
use Vizra\Evals\Run\Discovery;
use Vizra\Evals\Run\Gate;
use Vizra\Evals\Run\Runner;
use Vizra\Evals\Run\RunResult;

class RunCommand extends Command
{
    /**
     * Exit codes: 0 = success, 1 = gate/regression failure, 2 = harness failure.
     */
    public const EXIT_GATE_FAILED = 1;

    public const EXIT_HARNESS_FAILURE = 2;

    protected $signature = 'evals:run
        {suite? : Evaluation class name (e.g. SupportQuality) or FQCN}
        {--all : Run every discovered evaluation}
        {--filter= : Filter discovered evaluations by name substring}
        {--samples= : Override the number of samples per row}
        {--concurrency= : Number of concurrent target invocations (1 = sequential)}
        {--compare= : Compare against a run id, "baseline", or "latest"}
        {--baseline : Mark this run as the suite baseline if the gate passes}
        {--dry-run : Execute with faked agents (no network, zero tokens)}
        {--output=table : Output format: table or json}
        {--min-score= : Run-level minimum aggregate score (0-1)}
        {--min-pass-rate= : Run-level minimum pass rate (0-1)}
        {--max-regressions= : Maximum allowed regressions when comparing}';

    protected $description = 'Run one or more agent evaluations';

    public function handle(): int
    {
        $classes = $this->resolveClasses();

        if ($classes === null) {
            return self::EXIT_HARNESS_FAILURE;
        }

        $exit = self::SUCCESS;

        foreach ($classes as $class) {
            $exit = max($exit, $this->runOne($class));
        }

        return $exit;
    }

    /**
     * @return array<int, class-string<Evaluation>>|null
     */
    private function resolveClasses(): ?array
    {
        $suite = $this->argument('suite');

        if ($suite !== null) {
            $class = Discovery::resolve($suite);

            if ($class === null) {
                $this->components->error("No evaluation named [{$suite}] was found.");

                return null;
            }

            return [$class];
        }

        $classes = Discovery::all($this->option('filter'));

        if ($classes === []) {
            $this->components->error('No evaluations found. Create one with `php artisan make:eval`.');

            return null;
        }

        return $classes;
    }

    private function runOne(string $class): int
    {
        $json = $this->option('output') === 'json';

        /** @var Evaluation $evaluation */
        $evaluation = $this->laravel->make($class);

        if (! $json) {
            $this->components->info(sprintf(
                '%s (%s)%s',
                $evaluation->name(),
                $class,
                $this->option('dry-run') ? ' — dry run' : '',
            ));
        }

        $runner = new Runner(
            samplesOverride: $this->intOption('samples'),
            concurrencyOverride: $this->intOption('concurrency'),
            dryRun: (bool) $this->option('dry-run'),
            onSample: $json ? null : function (EvalRowResult $sample) {
                $this->output->write(match ($sample->status) {
                    EvalRowResult::STATUS_PASSED => '<fg=green>.</>',
                    EvalRowResult::STATUS_FAILED => '<fg=red>F</>',
                    default => '<fg=yellow>E</>',
                });
            },
        );

        try {
            $result = $runner->run($evaluation);
        } catch (Throwable $e) {
            if (! $json) {
                $this->newLine();
            }

            $this->components->error("Run failed: {$e->getMessage()}");

            return self::EXIT_HARNESS_FAILURE;
        }

        if (! $json) {
            $this->newLine();
        }

        $run = $result->run->fresh();

        [$comparison, $comparisonError] = $this->compare($run);

        if ($comparisonError !== null) {
            $this->components->error($comparisonError);

            return self::EXIT_HARNESS_FAILURE;
        }

        $gate = $this->gateFor($evaluation);
        $gateOutcome = $gate->evaluate($result, $comparison);

        if ($this->option('baseline') && $gateOutcome['passed']) {
            $this->markBaseline($run);
        }

        foreach ($runner->warnings() as $warning) {
            $json ? fwrite(STDERR, "[warning] {$warning}\n") : $this->components->warn($warning);
        }

        if ($json) {
            $this->output->writeln(json_encode(
                $this->document($run, $result, $comparison, $gate, $gateOutcome),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->renderSummary($run, $result, $comparison, $gateOutcome);
        }

        return $gateOutcome['passed'] ? self::SUCCESS : self::EXIT_GATE_FAILED;
    }

    /**
     * @return array{0: ?Comparison, 1: ?string}
     */
    private function compare(EvalRun $run): array
    {
        $reference = $this->option('compare');

        if ($reference === null) {
            return [null, null];
        }

        $baseline = Comparator::resolveReference($reference, $run->suite, $run->id);

        if ($baseline === null) {
            return [null, "No reference run found for --compare={$reference}."];
        }

        return [Comparator::compare($run, $baseline), null];
    }

    private function gateFor(Evaluation $evaluation): Gate
    {
        $gate = $evaluation->gatePolicy() ?? Gate::fromConfig();

        return $gate->with(
            minScore: $this->floatOption('min-score'),
            minPassRate: $this->floatOption('min-pass-rate'),
            maxRegressions: $this->intOption('max-regressions'),
        );
    }

    private function markBaseline(EvalRun $run): void
    {
        DB::transaction(function () use ($run) {
            EvalRun::where('suite', $run->suite)->where('is_baseline', true)->update(['is_baseline' => false]);
            $run->update(['is_baseline' => true]);
        });
    }

    private function renderSummary(EvalRun $run, RunResult $result, ?Comparison $comparison, array $gateOutcome): void
    {
        $summary = $result->summary;

        $perRow = collect($summary['per_row']);
        $multipleCombos = $perRow->pluck('combo_key')->unique()->count() > 1;

        $headers = array_values(array_filter([
            'Row',
            $multipleCombos ? 'Model' : null,
            'Result',
            'Pass',
            'Score',
            'Cost',
        ]));

        $rows = $perRow->map(function (array $row) use ($multipleCombos) {
            return array_values(array_filter([
                Str::limit($row['input_preview'], $multipleCombos ? 34 : 48),
                $multipleCombos ? $row['combo_key'] : null,
                match (true) {
                    ($row['errors'] ?? 0) > 0 => '<fg=yellow;options=bold>! error</>',
                    ($row['pass_rate'] ?? 0) >= 1.0 => '<fg=green;options=bold>✓ pass</>',
                    default => '<fg=red;options=bold>✗ fail</>',
                },
                "{$row['passed']}/{$row['samples']}",
                self::scoreCell($row['score_mean'], $row['score_stddev'], $row['samples']),
                $row['cost'] === null ? '—' : sprintf('$%.4f', $row['cost']),
            ], fn ($cell) => $cell !== null));
        })->all();

        $this->newLine();
        $this->table($headers, $rows, 'box');

        $scoreLine = self::scoreCell($summary['score_mean'], $summary['score_stddev'], $summary['samples']);
        $passLine = self::pct($summary['pass_rate']);

        $this->components->twoColumnDetail('<options=bold>Overall</>', "{$scoreLine} score, {$passLine} pass rate");
        $this->components->twoColumnDetail('Samples', "{$summary['samples']} across {$summary['rows']} rows"
            .($summary['errors'] > 0 ? ", <fg=yellow>{$summary['errors']} errors</>" : ''));
        $this->components->twoColumnDetail('Cost', $summary['total_cost'] === null
            ? 'unknown (add your models to config/evals.php pricing)'
            : sprintf(
                '$%.4f <fg=gray>($%.4f agent + $%.4f judge)</>',
                $summary['total_cost'],
                $summary['total_cost'] - ($summary['judge_cost'] ?? 0),
                $summary['judge_cost'] ?? 0,
            ));
        $this->components->twoColumnDetail('Duration', sprintf('%.1fs', $summary['duration_ms'] / 1000));
        $this->components->twoColumnDetail('Run', $run->id.($run->is_baseline ? ' <fg=cyan>(baseline)</>' : ''));

        if ($comparison !== null) {
            $this->renderComparison($comparison);
        }

        $this->newLine();

        if ($gateOutcome['passed']) {
            $this->components->info('Gate passed.');
        } else {
            $this->components->error('Gate failed: '.implode('; ', $gateOutcome['failures']));
        }
    }

    private function renderComparison(Comparison $comparison): void
    {
        $this->newLine();
        $this->components->twoColumnDetail(
            '<options=bold>vs baseline</> <fg=gray>'.$comparison->baselineRunId.'</>',
            sprintf(
                'score %s, pass rate %s',
                self::signedColored($comparison->scoreDelta),
                self::signedColored($comparison->passRateDelta),
            ),
        );

        $changes = [
            ...array_map(fn (array $row) => ['<fg=red>↓ regressed</>', $row], $comparison->regressed),
            ...array_map(fn (array $row) => ['<fg=green>↑ improved</>', $row], $comparison->improved),
        ];

        if ($changes !== []) {
            $this->table(
                ['Change', 'Row', 'Baseline', 'Current'],
                array_map(fn (array $change) => [
                    $change[0],
                    Str::limit($change[1]['input_preview'], 44),
                    self::pct($change[1]['before']['score_mean']).' <fg=gray>('.self::pct($change[1]['before']['pass_rate']).' pass)</>',
                    self::pct($change[1]['after']['score_mean']).' <fg=gray>('.self::pct($change[1]['after']['pass_rate']).' pass)</>',
                ], array_slice($changes, 0, 20)),
                'box',
            );
        }

        foreach ([['new', $comparison->newRows], ['removed', $comparison->removedRows]] as [$label, $set]) {
            if ($set !== []) {
                $this->components->twoColumnDetail(ucfirst($label).' rows', (string) count($set));
            }
        }
    }

    private static function scoreCell(?float $mean, ?float $stddev, int $samples): string
    {
        if ($mean === null) {
            return '—';
        }

        $color = match (true) {
            $mean >= 0.9 => 'green',
            $mean >= 0.7 => 'yellow',
            default => 'red',
        };

        $spread = ($stddev !== null && $samples > 1) ? ' <fg=gray>± '.self::pct($stddev).'</>' : '';

        return "<fg={$color}>".self::pct($mean).'</>'.$spread;
    }

    private static function signedColored(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        $formatted = sprintf('%+.1f%%', $value * 100);

        return match (true) {
            $value > 0 => "<fg=green>{$formatted}</>",
            $value < 0 => "<fg=red>{$formatted}</>",
            default => "<fg=gray>{$formatted}</>",
        };
    }

    private function document(EvalRun $run, RunResult $result, ?Comparison $comparison, Gate $gate, array $gateOutcome): array
    {
        $rows = [];

        foreach (collect($result->summary['per_row']) as $key => $row) {
            $rows[] = [
                ...$row,
                'key' => $key,
                'samples' => EvalRowResult::with('assertionResults')
                    ->where('run_id', $run->id)
                    ->where('row_hash', $row['row_hash'])
                    ->where('combo_key', $row['combo_key'])
                    ->orderBy('sample_index')
                    ->get()
                    ->map(fn (EvalRowResult $sample) => [
                        'sample_index' => $sample->sample_index,
                        'status' => $sample->status,
                        'score' => $sample->score,
                        'duration_ms' => $sample->duration_ms,
                        'cost' => $sample->cost,
                        'finish_reason' => $sample->finish_reason,
                        'error' => $sample->error,
                        'assertions' => $sample->assertionResults->map(fn ($assertion) => [
                            'name' => $assertion->name,
                            'type' => $assertion->type,
                            'status' => $assertion->status,
                            'score' => $assertion->score,
                            'weight' => $assertion->weight,
                            'is_gate' => $assertion->is_gate,
                            'expected' => $assertion->expected,
                            'actual' => $assertion->actual,
                            'message' => $assertion->message,
                            'judge_reasoning' => $assertion->judge_reasoning,
                        ])->all(),
                    ])->all(),
            ];
        }

        return [
            'version' => 1,
            'run' => [
                'id' => $run->id,
                'suite' => $run->suite,
                'name' => $run->name,
                'status' => $run->status,
                'git' => ['sha' => $run->git_sha, 'branch' => $run->git_branch, 'dirty' => $run->git_dirty],
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'config' => $run->config,
                'is_baseline' => $run->is_baseline,
            ],
            'summary' => collect($result->summary)->except('per_row')->all(),
            'rows' => $rows,
            'comparison' => $comparison?->toArray(),
            'gate' => [
                'policy' => $gate->toArray(),
                'passed' => $gateOutcome['passed'],
                'failures' => $gateOutcome['failures'],
            ],
        ];
    }

    private static function pct(?float $value): string
    {
        return $value === null ? '—' : sprintf('%.1f%%', $value * 100);
    }

    private static function signed(?float $value): string
    {
        return $value === null ? '—' : sprintf('%+.4f', $value);
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null ? null : (int) $value;
    }

    private function floatOption(string $name): ?float
    {
        $value = $this->option($name);

        return $value === null ? null : (float) $value;
    }
}
