<?php

use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Run\Scoring;

it('scores a sample as the weighted mean of non-gate assertions', function () {
    $verdict = Scoring::sample([
        AssertionResult::pass('a'),                    // 1.0 × 1
        AssertionResult::fail('b'),                    // 0.0 × 1
        AssertionResult::pass('c')->weight(2.0),       // 1.0 × 2
    ]);

    expect($verdict['score'])->toEqualWithDelta(0.75, 0.0001)
        ->and($verdict['status'])->toBe(EvalRowResult::STATUS_FAILED);
});

it('hard-fails the sample with score zero when a gate fails, excluding gates from the mean', function () {
    $verdict = Scoring::sample([
        AssertionResult::fail('gate')->gate(),
        AssertionResult::pass('other'),
    ]);

    expect($verdict)->toBe(['status' => EvalRowResult::STATUS_FAILED, 'score' => 0.0]);
});

it('treats an errored gate as a gate failure', function () {
    $verdict = Scoring::sample([
        AssertionResult::error('gate', 'boom')->gate(),
        AssertionResult::pass('other'),
    ]);

    expect($verdict['score'])->toBe(0.0);
});

it('excludes passing gates from the score mean', function () {
    $verdict = Scoring::sample([
        AssertionResult::pass('gate')->gate(),
        new AssertionResult('judge', AssertionResult::PASSED, 0.8, type: AssertionResult::TYPE_JUDGE),
    ]);

    expect($verdict['score'])->toEqualWithDelta(0.8, 0.0001)
        ->and($verdict['status'])->toBe(EvalRowResult::STATUS_PASSED);
});

it('scores 1.0 when only gates ran and all passed', function () {
    $verdict = Scoring::sample([AssertionResult::pass('gate')->gate()]);

    expect($verdict)->toBe(['status' => EvalRowResult::STATUS_PASSED, 'score' => 1.0]);
});

it('excludes skipped and errored assertions from the mean but errored ones fail the sample', function () {
    $skipped = new AssertionResult('judge', AssertionResult::SKIPPED, null, type: AssertionResult::TYPE_JUDGE);

    $verdict = Scoring::sample([AssertionResult::pass('a'), $skipped]);

    expect($verdict['score'])->toEqualWithDelta(1.0, 0.0001)
        ->and($verdict['status'])->toBe(EvalRowResult::STATUS_PASSED);

    $verdict = Scoring::sample([AssertionResult::pass('a'), AssertionResult::error('b', 'boom')]);

    expect($verdict['score'])->toEqualWithDelta(1.0, 0.0001)
        ->and($verdict['status'])->toBe(EvalRowResult::STATUS_FAILED);
});

it('computes mean and population stddev', function () {
    expect(Scoring::mean([1.0, 0.5, 0.0]))->toEqualWithDelta(0.5, 0.0001)
        ->and(Scoring::stddev([0.5, 0.5, 0.5]))->toEqualWithDelta(0.0, 0.0001)
        ->and(Scoring::stddev([1.0, 0.0]))->toEqualWithDelta(0.5, 0.0001)
        ->and(Scoring::mean([]))->toBeNull()
        ->and(Scoring::stddev([]))->toBeNull();
});
