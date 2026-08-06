<?php

declare(strict_types=1);

namespace Vizra\Evals\Pest;

use Closure;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Pest\Expectation;
use PHPUnit\Framework\Assert;
use Vizra\Evals\Models\EvalRun;

// Loaded via composer autoload.files — inert everywhere except inside a
// Pest process (the expect() function only exists once Pest has booted).
if (! function_exists('Pest\version') && ! function_exists('expect')) {
    return;
}

if (! function_exists('expect')) {
    return;
}

/*
 * expect(MyAgent::class)->toPassEval(fn ($eval) => $eval
 *     ->dataset(base_path('evals/support.jsonl'))
 *     ->samples(3)
 *     ->assert(fn ($a) => $a->notEmpty()->gate()->toolCalled('lookup_order'))
 *     ->judge('Answers using only documented policy.', min: 7)
 *     ->gate(minScore: 0.8, maxRegressions: 0)
 * );
 *
 * Runs the full vizra/evals engine — sampling, judges, persistence,
 * baseline comparison — and fails the test when the gate fails. Skipped
 * unless Pest runs with --evals (or PEST_EVALS=1), so normal test runs
 * cost nothing.
 */
expect()->extend('toPassEval', function (Closure $configure) {
    /** @var Expectation<class-string|Agent|Closure> $this */
    if (! Plugin::isEvalMode()) {
        Assert::markTestSkipped('Eval skipped. Run with [--evals] to evaluate against a real model.');
    }

    $testName = (string) Str::of(test()->name())
        ->after('__pest_evaluable_')
        ->replace('_', ' ');

    $pending = new PendingEval($this->value, 'pest: '.$testName);

    $configure($pending);

    $outcome = $pending->run();

    // Reporting never changes the outcome of a test — but a push that failed
    // silently would leave someone staring at an empty dashboard with nothing
    // to go on, which is the exact problem reporting from Pest was added to
    // solve. stderr, so it never lands in the middle of Pest's own output.
    if (($outcome['report'] ?? null) !== null && ! $outcome['report']['ok']) {
        fwrite(STDERR, '[vizra-evals] '.$outcome['report']['message'].PHP_EOL);
    }

    $summary = $outcome['result']->summary;

    // A run where every sample errored proves nothing — never let it pass,
    // whatever the gate says.
    if ($summary['samples'] > 0 && $summary['errors'] === $summary['samples']) {
        $firstError = $outcome['run']->rowResults()->whereNotNull('error')->value('error');

        Assert::fail(sprintf(
            'Eval [%s] errored on every sample (run %s): %s',
            $pending->suiteName(),
            $outcome['run']->id,
            $firstError ?? 'unknown error',
        ));
    }

    $lines = [
        sprintf(
            'Eval [%s] — score %s, pass rate %s across %d samples (run %s).',
            $pending->suiteName(),
            $summary['score_mean'] !== null ? number_format($summary['score_mean'] * 100, 1).'%' : '—',
            $summary['pass_rate'] !== null ? number_format($summary['pass_rate'] * 100, 1).'%' : '—',
            $summary['samples'],
            $outcome['run']->id,
        ),
    ];

    if (! $outcome['gate']['passed']) {
        $lines[] = 'Gate failed: '.implode('; ', $outcome['gate']['failures']).'.';

        foreach (array_slice($outcome['comparison']?->regressed ?? [], 0, 5) as $row) {
            $lines[] = sprintf(
                '  ↓ regressed: "%s" %s → %s',
                Str::limit($row['input_preview'], 60),
                $row['before']['score_mean'] !== null ? number_format($row['before']['score_mean'] * 100, 1).'%' : '—',
                $row['after']['score_mean'] !== null ? number_format($row['after']['score_mean'] * 100, 1).'%' : '—',
            );
        }

        $worst = collect($summary['per_row'] ?? [])
            ->filter(fn (array $row) => ($row['pass_rate'] ?? 1.0) < 1.0)
            ->sortBy('score_mean')
            ->take(5);

        foreach ($worst as $row) {
            $lines[] = sprintf(
                '  ✗ failing row: "%s" (%d/%d samples passed)',
                Str::limit($row['input_preview'], 60),
                $row['passed'],
                $row['samples'],
            );
        }

        Assert::fail(implode(PHP_EOL, $lines));
    }

    // Auto-baseline: the first gate-passing run for a suite becomes its
    // baseline, so regression comparison works from the second run onward.
    if (EvalRun::baselineFor($outcome['run']->suite) === null) {
        $outcome['run']->update(['is_baseline' => true]);
    }

    Assert::assertTrue(true); // record the passing expectation

    return $this;
});
