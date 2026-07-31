<?php

namespace Vizra\Evals\Run;

/**
 * The diff between a run and a reference (usually baseline) run. Entries in
 * regressed/improved/newlyFailing are ['key', 'row_hash', 'combo_key',
 * 'input_preview', 'before' => {...}, 'after' => {...}].
 */
final class Comparison
{
    public function __construct(
        public readonly string $baselineRunId,
        public readonly array $regressed,
        public readonly array $improved,
        public readonly array $newlyFailing,
        public readonly array $newRows,
        public readonly array $removedRows,
        public readonly ?float $scoreDelta,
        public readonly ?float $passRateDelta,
    ) {}

    public function toArray(): array
    {
        return [
            'baseline_run_id' => $this->baselineRunId,
            'score_delta' => $this->scoreDelta,
            'pass_rate_delta' => $this->passRateDelta,
            'regressed' => $this->regressed,
            'improved' => $this->improved,
            'newly_failing' => $this->newlyFailing,
            'new_rows' => $this->newRows,
            'removed_rows' => $this->removedRows,
        ];
    }
}
