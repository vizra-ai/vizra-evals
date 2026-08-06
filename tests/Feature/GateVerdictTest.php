<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Pest\Plugin;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

/**
 * The gate's verdict has to outlive the process that computed it.
 *
 * Before this, whether a run passed existed only as an exit code and a line of
 * console output — so both dashboards could show what a run scored but never
 * whether that was good enough, which is the question the score was being read
 * to answer.
 */
beforeEach(function () {
    Ai::fakeAgent(SupportAgent::class, fn (string $prompt) => str_contains($prompt, 'refund')
        ? 'Refunds are accepted within 30 days.'
        : 'Yes, we ship to France.');
});

it('records a passing gate on the run', function () {
    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--no-report' => true,
    ])->assertExitCode(0);

    expect(EvalRun::sole()->gate)->toBe(['passed' => true, 'failures' => []]);
});

it('records why a gate failed, in the words the console used', function () {
    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--no-report' => true,
        // Unreachable: the fixture's agent answers correctly, so the run
        // scores 1.0 and only an impossible threshold can fail it.
        '--min-score' => 1.1,
    ])->assertExitCode(1);

    $gate = EvalRun::sole()->gate;

    expect($gate['passed'])->toBeFalse()
        ->and($gate['failures'])->toHaveCount(1)
        ->and($gate['failures'][0])->toContain('below the minimum');
});

it('sends the verdict to the cloud with the run', function () {
    config([
        'evals.cloud.endpoint' => 'https://vizra.test/api/v1/runs',
        'evals.cloud.key' => 'vz_test_key',
    ]);

    Http::fake(['vizra.test/*' => Http::response(['status' => 'recorded', 'run' => []], 201)]);

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--min-score' => 1.1,
    ])->assertExitCode(1);

    // Reported even though the gate failed, and reported *with* the failure.
    // A run that just went red is the one most worth having in the dashboard.
    Http::assertSent(fn ($request) => $request['gate']['passed'] === false
        && str_contains($request['gate']['failures'][0], 'below the minimum'));
});

it('records the verdict from the pest expectation too', function () {
    Plugin::setEvalMode(true);

    try {
        expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
            ->dataset([['input' => 'What is your refund policy?', 'expected' => '30 days']])
            ->concurrency(1)
            ->assert(fn ($a) => $a->contains('30 days'))
            ->report(false));
    } finally {
        Plugin::setEvalMode(false);
    }

    // The gate is evaluated in two places — the command and this expectation —
    // and a run recorded through one that says nothing about its gate is a gap
    // the dashboards cannot tell apart from a run that had no gate at all.
    expect(EvalRun::sole()->gate)->toBe(['passed' => true, 'failures' => []]);
});
