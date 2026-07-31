<?php

namespace Vizra\Evals\Run;

use Illuminate\Support\Str;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Models\EvalRun;

/**
 * Aggregates a completed run's samples into row-level and run-level stats
 * and persists them on the EvalRun (summary json + denormalized columns).
 */
final class RunResult
{
    public function __construct(
        public readonly EvalRun $run,
        public readonly array $summary,
    ) {}

    public static function aggregate(EvalRun $run): self
    {
        $perRow = [];
        $totals = ['samples' => 0, 'errors' => 0, 'cost' => 0.0, 'judge_cost' => 0.0, 'duration_ms' => 0];
        $costKnown = false;

        /** @var EvalRowResult $sample */
        foreach ($run->rowResults()->with('assertionResults')->cursor() as $sample) {
            $key = $sample->row_hash.'|'.$sample->combo_key;

            $perRow[$key] ??= [
                'row_hash' => $sample->row_hash,
                'combo_key' => $sample->combo_key,
                'row_index' => $sample->row_index,
                'input_preview' => self::preview($sample->input),
                'samples' => 0,
                'passed' => 0,
                'errors' => 0,
                'cost' => null,
                'scores' => [],
            ];

            $entry = &$perRow[$key];
            $entry['samples']++;
            $totals['samples']++;
            $totals['duration_ms'] += $sample->duration_ms ?? 0;

            if ($sample->cost !== null) {
                $totals['cost'] += $sample->cost;
                $entry['cost'] = round(($entry['cost'] ?? 0.0) + $sample->cost, 6);
                $costKnown = true;
            }

            foreach ($sample->assertionResults as $assertion) {
                $judgeCost = $assertion->meta['judge_cost'] ?? null;

                if ($judgeCost !== null) {
                    $totals['judge_cost'] += $judgeCost;
                    $costKnown = true;
                }
            }

            if ($sample->status === EvalRowResult::STATUS_PASSED) {
                $entry['passed']++;
            } elseif ($sample->status === EvalRowResult::STATUS_ERROR) {
                $entry['errors']++;
                $totals['errors']++;
            }

            if ($sample->score !== null) {
                $entry['scores'][] = $sample->score;
            }

            unset($entry);
        }

        $perCombo = [];

        foreach ($perRow as $key => &$entry) {
            $entry['pass_rate'] = $entry['samples'] > 0 ? round($entry['passed'] / $entry['samples'], 4) : null;
            $entry['score_mean'] = self::round(Scoring::mean($entry['scores']));
            $entry['score_stddev'] = self::round(Scoring::stddev($entry['scores']));
            unset($entry['scores']);

            $combo = &$perCombo[$entry['combo_key']];
            $combo ??= ['combo_key' => $entry['combo_key'], 'rows' => 0, 'pass_rates' => [], 'score_means' => []];
            $combo['rows']++;

            if ($entry['pass_rate'] !== null) {
                $combo['pass_rates'][] = $entry['pass_rate'];
            }

            if ($entry['score_mean'] !== null) {
                $combo['score_means'][] = $entry['score_mean'];
            }

            unset($combo, $entry);
        }

        foreach ($perCombo as &$combo) {
            $combo['pass_rate'] = self::round(Scoring::mean($combo['pass_rates']));
            $combo['score_mean'] = self::round(Scoring::mean($combo['score_means']));
            $combo['score_stddev'] = self::round(Scoring::stddev($combo['score_means']));
            unset($combo['pass_rates'], $combo['score_means'], $combo);
        }

        $rowScoreMeans = array_values(array_filter(
            array_column($perRow, 'score_mean'),
            fn ($mean) => $mean !== null
        ));
        $rowPassRates = array_values(array_filter(
            array_column($perRow, 'pass_rate'),
            fn ($rate) => $rate !== null
        ));

        $summary = [
            'score_mean' => self::round(Scoring::mean($rowScoreMeans)),
            'score_stddev' => self::round(Scoring::stddev($rowScoreMeans)),
            'pass_rate' => self::round(Scoring::mean($rowPassRates)),
            'rows' => count($perRow),
            'samples' => $totals['samples'],
            'errors' => $totals['errors'],
            // total_cost is the grand total: target invocations plus judge calls.
            'total_cost' => $costKnown ? round($totals['cost'] + $totals['judge_cost'], 6) : null,
            'judge_cost' => $costKnown ? round($totals['judge_cost'], 6) : null,
            'duration_ms' => $totals['duration_ms'],
            'per_row' => $perRow,
            'per_combo' => array_values($perCombo),
        ];

        $run->update([
            'status' => EvalRun::STATUS_COMPLETED,
            'summary' => $summary,
            'score' => $summary['score_mean'],
            'pass_rate' => $summary['pass_rate'],
            'total_rows' => $summary['rows'],
            'total_samples' => $summary['samples'],
            'error_count' => $summary['errors'],
            'total_cost' => $summary['total_cost'],
            'judge_cost' => $summary['judge_cost'],
            'finished_at' => now(),
        ]);

        return new self($run, $summary);
    }

    public function score(): ?float
    {
        return $this->summary['score_mean'];
    }

    public function passRate(): ?float
    {
        return $this->summary['pass_rate'];
    }

    public function errors(): int
    {
        return $this->summary['errors'];
    }

    private static function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 4);
    }

    /**
     * Multi-turn inputs are persisted as a JSON transcript; preview the
     * final user turn (the actual question) rather than the raw JSON.
     */
    private static function preview(string $input): string
    {
        if (str_starts_with($input, '[')) {
            $decoded = json_decode($input, true);

            if (is_array($decoded) && $decoded !== []) {
                $last = end($decoded);

                if (is_array($last) && isset($last['content'])) {
                    $prior = count($decoded) - 1;

                    return Str::limit($last['content'], 160)." (after {$prior} prior turns)";
                }
            }
        }

        return Str::limit($input, 200);
    }
}
