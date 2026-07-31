<?php

use Vizra\Evals\Models\EvalAssertionResult;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Models\EvalRun;

it('boots the package and runs the migrations', function () {
    expect(config('evals.table_prefix'))->toBe('eval_');

    $run = EvalRun::create([
        'suite' => 'App\\Evals\\ExampleEvaluation',
        'name' => 'Example',
        'status' => EvalRun::STATUS_RUNNING,
        'config' => ['samples' => 3, 'across' => []],
        'started_at' => now(),
    ]);

    expect($run->id)->toBeString()->toHaveLength(26)
        ->and($run->refresh()->config)->toBe(['samples' => 3, 'across' => []])
        ->and($run->is_baseline)->toBeFalse();
});

it('round-trips row and assertion results with json casts', function () {
    $run = EvalRun::create([
        'suite' => 'App\\Evals\\ExampleEvaluation',
        'status' => EvalRun::STATUS_RUNNING,
        'config' => [],
    ]);

    $row = $run->rowResults()->create([
        'row_hash' => str_repeat('a', 64),
        'row_index' => 0,
        'sample_index' => 0,
        'combo_key' => 'openai/gpt-5',
        'combo' => ['provider' => 'openai', 'model' => 'gpt-5'],
        'input' => 'What is the refund policy?',
        'status' => EvalRowResult::STATUS_PASSED,
        'score' => 0.9,
        'response_text' => 'Our refund policy is...',
        'tool_calls' => [['id' => 'call_1', 'name' => 'lookup_order', 'arguments' => ['id' => 7]]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        'finish_reason' => 'stop',
        'duration_ms' => 812,
    ]);

    $assertion = $row->assertionResults()->create([
        'name' => 'contains',
        'type' => 'deterministic',
        'status' => 'passed',
        'score' => 1.0,
        'weight' => 1.0,
        'is_gate' => false,
        'expected' => 'refund',
        'actual' => 'Our refund policy is...',
    ]);

    $fresh = EvalRowResult::with('assertionResults')->find($row->id);

    expect($fresh->tool_calls[0]['name'])->toBe('lookup_order')
        ->and($fresh->usage['prompt_tokens'])->toBe(100)
        ->and($fresh->score)->toEqualWithDelta(0.9, 0.0001)
        ->and($fresh->assertionResults->first()->is_gate)->toBeFalse()
        ->and($fresh->assertionResults->first()->score)->toEqualWithDelta(1.0, 0.0001);

    $run->delete();

    expect(EvalRowResult::count())->toBe(0)
        ->and(EvalAssertionResult::count())->toBe(0);
});
