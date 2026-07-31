<?php

use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Run\Comparator;

function runWithSummary(string $id, array $perRow, ?float $scoreMean = null, ?float $passRate = null): EvalRun
{
    $run = new EvalRun;
    $run->id = $id;
    $run->summary = [
        'score_mean' => $scoreMean,
        'pass_rate' => $passRate,
        'per_row' => $perRow,
    ];

    return $run;
}

function perRowEntry(string $hash, ?float $scoreMean, ?float $passRate): array
{
    return [
        'row_hash' => $hash,
        'combo_key' => '-',
        'row_index' => 0,
        'input_preview' => "input {$hash}",
        'samples' => 3,
        'passed' => (int) round(($passRate ?? 0) * 3),
        'errors' => 0,
        'pass_rate' => $passRate,
        'score_mean' => $scoreMean,
        'score_stddev' => 0.0,
    ];
}

it('classifies regressed, improved, newly failing, new, and removed rows', function () {
    $baseline = runWithSummary('base', [
        'a|-' => perRowEntry('a', 0.9, 1.0),   // will regress on score
        'b|-' => perRowEntry('b', 0.9, 1.0),   // will regress on pass rate -> newly failing
        'c|-' => perRowEntry('c', 0.5, 0.66),  // will improve
        'd|-' => perRowEntry('d', 0.9, 1.0),   // unchanged
        'e|-' => perRowEntry('e', 0.9, 1.0),   // removed in current
    ], scoreMean: 0.82, passRate: 0.93);

    $current = runWithSummary('cur', [
        'a|-' => perRowEntry('a', 0.6, 1.0),
        'b|-' => perRowEntry('b', 0.9, 0.66),
        'c|-' => perRowEntry('c', 0.8, 1.0),
        'd|-' => perRowEntry('d', 0.92, 1.0),  // within epsilon
        'f|-' => perRowEntry('f', 1.0, 1.0),   // new row
    ], scoreMean: 0.84, passRate: 0.93);

    $comparison = Comparator::compare($current, $baseline);

    expect(array_column($comparison->regressed, 'row_hash'))->toBe(['a', 'b'])
        ->and(array_column($comparison->newlyFailing, 'row_hash'))->toBe(['b'])
        ->and(array_column($comparison->improved, 'row_hash'))->toBe(['c'])
        ->and($comparison->newRows)->toHaveCount(1)
        ->and($comparison->newRows[0]['key'])->toBe('f|-')
        ->and($comparison->removedRows[0]['key'])->toBe('e|-')
        ->and($comparison->scoreDelta)->toEqualWithDelta(0.02, 0.0001)
        ->and($comparison->baselineRunId)->toBe('base');
});

it('ignores score drops within epsilon but never ignores pass-rate drops', function () {
    $baseline = runWithSummary('base', ['a|-' => perRowEntry('a', 0.90, 1.0)]);

    // Score drop of 0.03 < epsilon 0.05 → not a regression.
    $current = runWithSummary('cur', ['a|-' => perRowEntry('a', 0.87, 1.0)]);
    expect(Comparator::compare($current, $baseline)->regressed)->toBeEmpty();

    // Any pass-rate drop is a regression.
    $current = runWithSummary('cur', ['a|-' => perRowEntry('a', 0.90, 0.66)]);
    expect(Comparator::compare($current, $baseline)->regressed)->toHaveCount(1);
});

it('joins single-model runs on row hash even when reported model ids differ', function () {
    $entry = perRowEntry('a', 0.9, 1.0);

    $baseline = runWithSummary('base', [
        'a|openai/gpt-5-mini' => [...$entry, 'combo_key' => 'openai/gpt-5-mini'],
    ]);

    // Same logical rows, but the provider now reports a dated model id.
    $current = runWithSummary('cur', [
        'a|openai/gpt-5-mini-2025-08-07' => [...perRowEntry('a', 0.5, 0.33), 'combo_key' => 'openai/gpt-5-mini-2025-08-07'],
    ]);

    $comparison = Comparator::compare($current, $baseline);

    expect($comparison->regressed)->toHaveCount(1)
        ->and($comparison->newRows)->toBeEmpty()
        ->and($comparison->removedRows)->toBeEmpty();
});

it('keeps the composite key for multi-combo runs', function () {
    $rows = [
        'a|openai/gpt-5' => [...perRowEntry('a', 0.9, 1.0), 'combo_key' => 'openai/gpt-5'],
        'a|anthropic/claude-sonnet-5' => [...perRowEntry('a', 0.8, 1.0), 'combo_key' => 'anthropic/claude-sonnet-5'],
    ];

    $comparison = Comparator::compare(runWithSummary('cur', $rows), runWithSummary('base', $rows));

    expect($comparison->regressed)->toBeEmpty()
        ->and($comparison->newRows)->toBeEmpty()
        ->and($comparison->removedRows)->toBeEmpty();
});
