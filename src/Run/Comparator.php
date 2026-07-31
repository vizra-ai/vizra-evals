<?php

namespace Vizra\Evals\Run;

use Vizra\Evals\Models\EvalRun;

/**
 * Diffs two runs by joining rows on "{row_hash}|{combo_key}". A row counts
 * as regressed when its pass rate dropped, or its mean score dropped by
 * more than config('evals.compare.epsilon'); improvements are symmetric.
 * newly_failing ⊆ regressed: rows that were fully passing on the baseline.
 */
class Comparator
{
    public static function compare(EvalRun $current, EvalRun $baseline): Comparison
    {
        $epsilon = (float) config('evals.compare.epsilon', 0.05);

        $currentRows = self::joinable($current->summary['per_row'] ?? []);
        $baselineRows = self::joinable($baseline->summary['per_row'] ?? []);

        $regressed = $improved = $newlyFailing = [];

        foreach ($currentRows as $key => $after) {
            $before = $baselineRows[$key] ?? null;

            if ($before === null) {
                continue;
            }

            $entry = [
                'key' => $key,
                'row_hash' => $after['row_hash'],
                'combo_key' => $after['combo_key'],
                'input_preview' => $after['input_preview'],
                'before' => self::stats($before),
                'after' => self::stats($after),
            ];

            $passRateDropped = self::dropped($before['pass_rate'], $after['pass_rate'], 0.0);
            $passRateRose = self::dropped($after['pass_rate'], $before['pass_rate'], 0.0);
            $scoreDropped = self::dropped($before['score_mean'], $after['score_mean'], $epsilon);
            $scoreRose = self::dropped($after['score_mean'], $before['score_mean'], $epsilon);

            if ($passRateDropped || $scoreDropped) {
                $regressed[] = $entry;

                $wasFullyPassing = isset($before['pass_rate']) && (float) $before['pass_rate'] >= 1.0;
                $nowFailing = isset($after['pass_rate']) && (float) $after['pass_rate'] < 1.0;

                if ($wasFullyPassing && $nowFailing) {
                    $newlyFailing[] = $entry;
                }
            } elseif ($passRateRose || $scoreRose) {
                $improved[] = $entry;
            }
        }

        $newRows = array_values(array_map(
            fn (array $row) => ['key' => $row['row_hash'].'|'.$row['combo_key'], 'input_preview' => $row['input_preview']],
            array_diff_key($currentRows, $baselineRows),
        ));

        $removedRows = array_values(array_map(
            fn (array $row) => ['key' => $row['row_hash'].'|'.$row['combo_key'], 'input_preview' => $row['input_preview']],
            array_diff_key($baselineRows, $currentRows),
        ));

        return new Comparison(
            baselineRunId: $baseline->id,
            regressed: $regressed,
            improved: $improved,
            newlyFailing: $newlyFailing,
            newRows: $newRows,
            removedRows: $removedRows,
            scoreDelta: self::delta($baseline->summary['score_mean'] ?? null, $current->summary['score_mean'] ?? null),
            passRateDelta: self::delta($baseline->summary['pass_rate'] ?? null, $current->summary['pass_rate'] ?? null),
        );
    }

    /**
     * Resolve a --compare reference: a run id, "baseline", or "latest"
     * (the most recent completed run for the suite before this one).
     */
    public static function resolveReference(string $reference, string $suite, string $excludeRunId): ?EvalRun
    {
        return match ($reference) {
            'baseline' => EvalRun::baselineFor($suite),
            'latest' => EvalRun::query()
                ->where('suite', $suite)
                ->where('status', EvalRun::STATUS_COMPLETED)
                ->where('id', '!=', $excludeRunId)
                ->latest('created_at')
                ->orderByDesc('id')
                ->first(),
            default => EvalRun::find($reference),
        };
    }

    /**
     * Re-key per_row entries for joining. Runs without an across() matrix
     * carry the *reported* provider/model in combo_key (which drifts when
     * providers rotate dated model ids, and differs between dry and real
     * runs) — for single-model runs, rows join on content hash alone.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function joinable(array $rows): array
    {
        $combos = array_unique(array_column($rows, 'combo_key'));

        if (count($combos) > 1) {
            return $rows;
        }

        $rekeyed = [];

        foreach ($rows as $row) {
            $rekeyed[$row['row_hash']] = $row;
        }

        return $rekeyed;
    }

    private static function stats(array $row): array
    {
        return [
            'score_mean' => $row['score_mean'] ?? null,
            'score_stddev' => $row['score_stddev'] ?? null,
            'pass_rate' => $row['pass_rate'] ?? null,
        ];
    }

    private static function dropped(?float $before, ?float $after, float $tolerance): bool
    {
        if ($before === null || $after === null) {
            return false;
        }

        return ($before - $after) > $tolerance + 1e-9;
    }

    private static function delta(?float $before, ?float $after): ?float
    {
        if ($before === null || $after === null) {
            return null;
        }

        return round($after - $before, 4);
    }
}
