<?php

use Laravel\Ai\Ai;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;
use Vizra\Evals\Judge\JudgeAgent;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Pest\Plugin;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

beforeEach(function () {
    Plugin::setEvalMode(true);
});

afterEach(function () {
    Plugin::setEvalMode(false);
});

function goodAgentFake(): void
{
    Ai::fakeAgent(SupportAgent::class, fn () => 'Refunds are accepted within 30 days.');
}

it('skips when not running in eval mode', function () {
    Plugin::setEvalMode(false);

    try {
        expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval->dataset(['q']));
        $this->fail('Expected the eval to be skipped.');
    } catch (SkippedWithMessageException $e) {
        expect($e->getMessage())->toContain('--evals');
    }
});

it('runs, records, and passes a healthy eval — and auto-baselines the first run', function () {
    goodAgentFake();

    expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
        ->dataset([
            ['input' => 'What is your refund policy?', 'expected' => '30 days'],
            ['input' => 'Can I return an opened item?', 'expected' => '30 days'],
        ])
        ->samples(2)
        ->concurrency(1)
        ->assert(fn ($a, $row) => $a
            ->notEmpty()->gate()
            ->contains($row->expected()))
        ->gate(minScore: 0.8)
    );

    $run = EvalRun::first();

    expect($run->suite)->toStartWith('pest: ')
        ->and($run->suite)->toContain('auto baselines the first run')
        ->and($run->status)->toBe(EvalRun::STATUS_COMPLETED)
        ->and($run->total_samples)->toBe(4)
        ->and($run->pass_rate)->toEqualWithDelta(1.0, 0.0001)
        ->and($run->fresh()->is_baseline)->toBeTrue();
});

it('fails the test with failing rows when the gate fails', function () {
    Ai::fakeAgent(SupportAgent::class, fn () => 'I cannot help with that.');

    try {
        expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
            ->dataset([['input' => 'Refunds?', 'expected' => '30 days']])
            ->concurrency(1)
            ->assert(fn ($a, $row) => $a->contains($row->expected()))
            ->gate(minScore: 0.8)
        );
        $this->fail('Expected the eval gate to fail the test.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('Gate failed')
            ->toContain('score')
            ->toContain('failing row: "Refunds?"');
    }

    // The failed run is still recorded — failure data is the valuable kind.
    expect(EvalRun::count())->toBe(1)
        ->and(EvalRun::first()->is_baseline)->toBeFalse();
});

it('detects regressions against the auto-set baseline', function () {
    $config = fn ($eval) => $eval
        ->dataset([['input' => 'What is your refund policy?', 'expected' => '30 days']])
        ->samples(2)
        ->concurrency(1)
        ->suite('support-regression-check')
        ->assert(fn ($a, $row) => $a->contains($row->expected()))
        ->gate(maxRegressions: 0);

    goodAgentFake();
    expect(SupportAgent::class)->toPassEval($config);

    expect(EvalRun::baselineFor('support-regression-check'))->not->toBeNull();

    Ai::fakeAgent(SupportAgent::class, fn () => 'No idea, sorry.');

    try {
        expect(SupportAgent::class)->toPassEval($config);
        $this->fail('Expected the regression to fail the test.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('regressed')
            ->toContain('What is your refund policy?');
    }
});

it('runs judges configured through the fluent judge()', function () {
    goodAgentFake();
    Ai::fakeAgent(JudgeAgent::class, fn () => ['score' => 9, 'reasoning' => 'On policy.']);

    expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
        ->dataset([['input' => 'Refunds?']])
        ->concurrency(1)
        ->judge('Answers using only documented policy.', min: 7)
    );

    $run = EvalRun::first();
    $judge = $run->rowResults()->first()->assertionResults()->where('type', 'judge')->first();

    expect($judge->status)->toBe('passed')
        ->and($judge->judge_reasoning)->toBe('On policy.');
});

it('can run a full Evaluation class via using()', function () {
    Ai::fakeAgent(SupportAgent::class, function (string $prompt) {
        return str_contains($prompt, 'refund')
            ? 'Refunds are accepted within 30 days.'
            : 'Yes, we ship to France.';
    });

    expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
        ->using(SupportQuality::class)
        ->concurrency(1)
    );

    expect(EvalRun::first()->suite)->toBe(SupportQuality::class);
});

it('rejects an eval with nothing to evaluate', function () {
    expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval->dataset(['q']));
})->throws(InvalidArgumentException::class, 'nothing is being evaluated');

it('fails loudly when every sample errors (e.g. an unknown assertion name)', function () {
    goodAgentFake();

    try {
        expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
            ->dataset(['q'])
            ->concurrency(1)
            ->assert(fn ($a) => $a->definitelyNotAnAssertion())
        );
        $this->fail('Expected the all-errors run to fail the test.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('errored on every sample')
            ->toContain('Unknown eval assertion [definitelyNotAnAssertion]');
    }
});
