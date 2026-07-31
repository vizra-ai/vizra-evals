<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Vizra\Evals\Events\RowEvaluated;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Run\Runner;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

it('runs an evaluation end-to-end against a faked agent and persists everything', function () {
    Ai::fakeAgent(SupportAgent::class, function (string $prompt) {
        $text = str_contains($prompt, 'refund')
            ? 'Refunds are accepted within 30 days of purchase.'
            : 'Yes, we ship to France and most of Europe.';

        return new TextResponse($text, new Usage(promptTokens: 100, completionTokens: 20), new Meta('openai', 'gpt-5'));
    });

    $result = (new Runner(concurrencyOverride: 1))->run(new SupportQuality);

    $run = $result->run->fresh();

    expect($run->status)->toBe(EvalRun::STATUS_COMPLETED)
        ->and($run->suite)->toBe(SupportQuality::class)
        ->and($run->total_rows)->toBe(2)
        ->and($run->total_samples)->toBe(2)
        ->and($run->error_count)->toBe(0)
        ->and($run->score)->toEqualWithDelta(1.0, 0.0001)
        ->and($run->pass_rate)->toEqualWithDelta(1.0, 0.0001)
        ->and($run->total_cost)->toBeGreaterThan(0)
        ->and($run->finished_at)->not->toBeNull();

    $samples = EvalRowResult::where('run_id', $run->id)->orderBy('row_index')->get();

    expect($samples)->toHaveCount(2)
        ->and($samples[0]->status)->toBe(EvalRowResult::STATUS_PASSED)
        ->and($samples[0]->response_text)->toContain('30 days')
        ->and($samples[0]->usage['prompt_tokens'])->toBe(100)
        ->and($samples[0]->combo_key)->toBe('openai/gpt-5')
        ->and($samples[0]->cost)->toBeGreaterThan(0)
        ->and($samples[0]->row_hash)->toHaveLength(64);

    $assertions = $samples[0]->assertionResults;

    expect($assertions)->toHaveCount(2)
        ->and($assertions->firstWhere('name', 'not_empty')->is_gate)->toBeTrue()
        ->and($assertions->firstWhere('name', 'contains')->status)->toBe('passed');
});

it('fails samples whose assertions fail and scores them accordingly', function () {
    Ai::fakeAgent(SupportAgent::class, ['Sorry, I cannot help with that.', 'Sorry, I cannot help with that.']);

    $result = (new Runner(concurrencyOverride: 1))->run(new SupportQuality);

    expect($result->run->fresh()->pass_rate)->toEqualWithDelta(0.0, 0.0001)
        ->and($result->run->fresh()->score)->toEqualWithDelta(0.0, 0.0001)
        ->and(EvalRowResult::where('status', EvalRowResult::STATUS_FAILED)->count())->toBe(2);
});

it('isolates target invocation errors per sample', function () {
    $calls = 0;

    Ai::fakeAgent(SupportAgent::class, function (string $prompt) use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('provider exploded');
        }

        return 'Yes, we ship to France within 30 days.';
    });

    $result = (new Runner(concurrencyOverride: 1))->run(new SupportQuality);
    $run = $result->run->fresh();

    expect($run->status)->toBe(EvalRun::STATUS_COMPLETED)
        ->and($run->error_count)->toBe(1)
        ->and($run->total_samples)->toBe(2);

    $errored = EvalRowResult::where('status', EvalRowResult::STATUS_ERROR)->first();

    expect($errored->error)->toContain('provider exploded')
        ->and($errored->score)->toBeNull();
});

it('runs multiple samples per row', function () {
    Ai::fakeAgent(SupportAgent::class, fn () => 'We accept returns within 30 days. And yes to France.');

    $evaluation = new class extends SupportQuality
    {
        public int $samples = 3;
    };

    $result = (new Runner(concurrencyOverride: 1))->run($evaluation);
    $run = $result->run->fresh();

    expect($run->total_samples)->toBe(6)
        ->and($run->total_rows)->toBe(2)
        ->and(EvalRowResult::where('sample_index', 2)->count())->toBe(2);
});

it('records planned totals in the run config before executing', function () {
    Ai::fakeAgent(SupportAgent::class, fn () => 'ok 30 days yes');

    $result = (new Runner(samplesOverride: 3, concurrencyOverride: 1))
        ->run(new SupportQuality);

    expect($result->run->fresh()->config['planned_rows'])->toBe(2)
        ->and($result->run->fresh()->config['planned_samples'])->toBe(6);
});

it('marks the run failed with the failure message when execution dies mid-run', function () {
    Ai::fakeAgent(SupportAgent::class, fn () => 'ok');

    // Simulate a fatal mid-run failure (e.g. the DB going away) via a
    // listener that explodes after the first sample persists.
    Event::listen(RowEvaluated::class, function (): void {
        throw new RuntimeException('worker exploded mid-run');
    });

    try {
        (new Runner(concurrencyOverride: 1))
            ->run(new SupportQuality);
        $this->fail('Runner should have rethrown');
    } catch (RuntimeException) {
        // expected
    }

    $run = EvalRun::latest('id')->first();

    expect($run->status)->toBe(EvalRun::STATUS_FAILED)
        ->and($run->summary['failure'])->toContain('worker exploded mid-run')
        ->and($run->finished_at)->not->toBeNull();
});
