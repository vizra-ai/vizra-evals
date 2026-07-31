<?php

namespace Vizra\Evals\Console;

use Illuminate\Console\Command;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Throwable;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Exceptions\DatasetException;
use Vizra\Evals\Judge\JudgeBuilder;
use Vizra\Evals\Models\EvalRun;

/**
 * Measures judge/human agreement over a labelled dataset. Rows need an
 * `output` field (the response being graded) and a `human_score` (1-10)
 * or `human_verdict` ("pass"/"fail"). Reported: exact and ±1 agreement
 * (accuracy for verdicts), mean absolute error, and the worst
 * disagreements with the judge's reasoning — enough to decide whether a
 * judge can be trusted, without kappa statistics.
 */
class CalibrateCommand extends Command
{
    protected $signature = 'evals:calibrate
        {dataset : Path to a CSV or JSONL dataset with output and human_score/human_verdict fields}
        {--judge= : Judge agent class (defaults to config evals.judge.agent)}
        {--criteria= : Criteria the judge scores against}';

    protected $description = 'Measure agreement between an LLM judge and human scores';

    public function handle(): int
    {
        $path = $this->argument('dataset');

        try {
            // Materialize eagerly so malformed datasets fail here, not mid-loop.
            $rows = $this->readDataset($path)->all();
        } catch (DatasetException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $run = EvalRun::create([
            'suite' => 'calibration:'.($this->option('judge') ?? config('evals.judge.agent')),
            'name' => 'Judge calibration',
            'status' => EvalRun::STATUS_RUNNING,
            'config' => ['dataset' => $path, 'criteria' => $this->option('criteria')],
            'started_at' => now(),
        ]);

        $results = [];

        foreach ($rows as $row) {
            $output = $row->meta('output');
            $humanScore = $row->meta('human_score');
            $humanVerdict = $row->meta('human_verdict');

            if ($output === null || ($humanScore === null && $humanVerdict === null)) {
                $this->components->warn("Row {$row->index} is missing output or human_score/human_verdict — skipped.");

                continue;
            }

            $builder = new JudgeBuilder((string) $output, $row);
            $builder->criteria($this->option('criteria') ?? 'Overall quality, correctness, and helpfulness.');

            if ($this->option('judge') !== null) {
                $builder->using($this->option('judge'));
            }

            try {
                $judged = $builder->execute();
            } catch (Throwable $e) {
                $this->components->error("Judge failed on row {$row->index}: {$e->getMessage()}");

                continue;
            }

            if ($judged->score === null) {
                $this->components->warn("Row {$row->index}: {$judged->message}");

                continue;
            }

            $judgeScore = (int) round($judged->score * 10);

            $results[] = [
                'row' => $row,
                'judge_score' => $judgeScore,
                'judge_passed' => $judged->passed(),
                'human_score' => $humanScore !== null ? (int) $humanScore : null,
                'human_passed' => $humanVerdict !== null ? in_array(strtolower((string) $humanVerdict), ['pass', 'passed', 'true', '1', 'yes'], true) : null,
                'reasoning' => $judged->judgeReasoning,
            ];

            $sample = $run->rowResults()->create([
                'row_hash' => $row->hash,
                'row_index' => $row->index,
                'sample_index' => 0,
                'input' => $row->input,
                'status' => 'passed',
                'response_text' => (string) $output,
                'meta' => ['human_score' => $humanScore, 'human_verdict' => $humanVerdict],
                'score' => $judged->score,
                'created_at' => now(),
            ]);

            $sample->assertionResults()->create([...$judged->toArray(), 'created_at' => now()]);
        }

        if ($results === []) {
            $run->update(['status' => EvalRun::STATUS_FAILED, 'finished_at' => now()]);
            $this->components->error('No rows could be judged.');

            return self::FAILURE;
        }

        $this->report($results);

        $run->update([
            'status' => EvalRun::STATUS_COMPLETED,
            'total_rows' => count($results),
            'total_samples' => count($results),
            'finished_at' => now(),
        ]);

        $this->components->info("Calibration persisted as run [{$run->id}].");

        return self::SUCCESS;
    }

    /**
     * @return LazyCollection<int, Row>
     */
    private function readDataset(string $path): LazyCollection
    {
        if (str_ends_with($path, '.csv')) {
            return Dataset::fromCsv($path, inputColumn: 'input')->rows();
        }

        return Dataset::fromJsonl($path)->rows();
    }

    private function report(array $results): void
    {
        $scored = array_values(array_filter($results, fn (array $r) => $r['human_score'] !== null));
        $verdicts = array_values(array_filter($results, fn (array $r) => $r['human_passed'] !== null));

        if ($scored !== []) {
            $deltas = array_map(fn (array $r) => abs($r['judge_score'] - $r['human_score']), $scored);
            $exact = count(array_filter($deltas, fn (int $d) => $d === 0));
            $within = count(array_filter($deltas, fn (int $d) => $d <= 1));

            $this->components->twoColumnDetail('Rows with human scores', (string) count($scored));
            $this->components->twoColumnDetail('Exact agreement', sprintf('%.1f%%', $exact / count($scored) * 100));
            $this->components->twoColumnDetail('Agreement within ±1', sprintf('%.1f%%', $within / count($scored) * 100));
            $this->components->twoColumnDetail('Mean absolute error', sprintf('%.2f points', array_sum($deltas) / count($deltas)));
        }

        if ($verdicts !== []) {
            $correct = count(array_filter($verdicts, fn (array $r) => $r['judge_passed'] === $r['human_passed']));

            $this->components->twoColumnDetail('Rows with human verdicts', (string) count($verdicts));
            $this->components->twoColumnDetail('Verdict accuracy', sprintf('%.1f%%', $correct / count($verdicts) * 100));
        }

        $disagreements = collect($scored)
            ->sortByDesc(fn (array $r) => abs($r['judge_score'] - $r['human_score']))
            ->filter(fn (array $r) => $r['judge_score'] !== $r['human_score'])
            ->take(10);

        if ($disagreements->isNotEmpty()) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=yellow>Worst disagreements</>', 'judge vs human');

            foreach ($disagreements as $r) {
                $this->components->twoColumnDetail(
                    '  '.Str::limit($r['row']->input, 60),
                    "{$r['judge_score']} vs {$r['human_score']}",
                );

                if ($r['reasoning'] !== null) {
                    $this->line('    <fg=gray>'.Str::limit($r['reasoning'], 140).'</>');
                }
            }
        }
    }
}
