<?php

use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Ai;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

function fakeGoodAgent(): void
{
    Ai::fakeAgent(SupportAgent::class, function (string $prompt) {
        return str_contains($prompt, 'refund')
            ? 'Refunds are accepted within 30 days.'
            : 'Yes, we ship to France.';
    });
}

function fakeBadAgent(): void
{
    Ai::fakeAgent(SupportAgent::class, fn () => 'I cannot help with that.');
}

it('runs a suite by FQCN and exits 0 when the gate passes', function () {
    fakeGoodAgent();

    $this->artisan('evals:run', ['suite' => SupportQuality::class, '--concurrency' => 1])
        ->assertExitCode(0);

    expect(EvalRun::count())->toBe(1)
        ->and(EvalRun::first()->status)->toBe(EvalRun::STATUS_COMPLETED);
});

it('exits 1 when the run-level gate fails', function () {
    fakeBadAgent();

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--min-score' => '0.9',
    ])->assertExitCode(1);
});

it('exits 2 for an unknown suite', function () {
    $this->artisan('evals:run', ['suite' => 'DoesNotExist'])->assertExitCode(2);
});

it('emits a stable machine-readable json document', function () {
    fakeGoodAgent();

    $exit = Artisan::call('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--output' => 'json',
    ]);

    expect($exit)->toBe(0);

    $output = Artisan::output();
    $document = json_decode($output, true);

    expect($document)->toBeArray()
        ->and($document['version'])->toBe(1)
        ->and($document['run']['suite'])->toBe(SupportQuality::class)
        ->and($document['summary']['rows'])->toBe(2)
        ->and($document['rows'])->toHaveCount(2)
        ->and($document['rows'][0]['samples'][0]['assertions'])->toHaveCount(2)
        ->and($document['gate']['passed'])->toBeTrue()
        ->and($document['comparison'])->toBeNull();
});

it('marks a baseline and detects regressions against it, failing the gate', function () {
    fakeGoodAgent();

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--baseline' => true,
    ])->assertExitCode(0);

    $baseline = EvalRun::where('is_baseline', true)->first();
    expect($baseline)->not->toBeNull();

    fakeBadAgent();

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--compare' => 'baseline',
    ])->assertExitCode(1);

    fakeBadAgent();

    // Allowing enough regressions lets the same comparison pass.
    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--compare' => 'baseline',
        '--max-regressions' => '2',
    ])->assertExitCode(0);
});

it('exits 2 when the compare reference does not exist', function () {
    fakeGoodAgent();

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--compare' => 'baseline',
    ])->assertExitCode(2);
});

it('discovers evaluations from configured paths', function () {
    config()->set('evals.paths', [__DIR__.'/../Fixtures/Evals']);

    fakeGoodAgent();

    $this->artisan('evals:run', ['--concurrency' => 1])->assertExitCode(0);

    expect(EvalRun::where('suite', SupportQuality::class)->exists())->toBeTrue();
});
