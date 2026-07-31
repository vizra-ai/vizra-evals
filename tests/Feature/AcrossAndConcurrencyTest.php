<?php

use Illuminate\Support\Facades\Concurrency;
use Laravel\Ai\Ai;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Run\Executors\ConcurrentExecutor;
use Vizra\Evals\Run\Runner;
use Vizra\Evals\Run\SampleOutcome;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

it('runs every across() combo as a distinct series', function () {
    Ai::fakeAgent(SupportAgent::class, fn () => 'Refunds within 30 days, and yes to France.');

    $evaluation = new class extends SupportQuality
    {
        public function dataset(): Dataset
        {
            return Dataset::fromArray([['input' => 'Refunds?', 'expected' => '30 days']]);
        }

        public function across(): array
        {
            return [
                ['provider' => 'openai', 'model' => 'gpt-5'],
                ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
            ];
        }
    };

    $result = (new Runner(concurrencyOverride: 1))->run($evaluation);
    $run = $result->run->fresh();

    expect($run->total_samples)->toBe(2)
        ->and($run->total_rows)->toBe(2) // one logical row × two combos = two series
        ->and(EvalRowResult::pluck('combo_key')->sort()->values()->all())
        ->toBe(['anthropic/claude-sonnet-5', 'openai/gpt-5'])
        ->and(collect($run->summary['per_combo'])->pluck('combo_key')->sort()->values()->all())
        ->toBe(['anthropic/claude-sonnet-5', 'openai/gpt-5']);
});

it('partitions concurrent work into waves and preserves outcome order', function () {
    Concurrency::shouldReceive('run')
        ->twice()
        ->andReturnUsing(fn (array $tasks) => array_map(fn ($task) => $task(), $tasks));

    $executor = new ConcurrentExecutor(3, fallback: fn () => null);

    $make = fn (string $id) => fn () => SampleOutcome::error('X', $id, 0.0);

    $first = $executor->run([$make('a'), $make('b'), $make('c')]);
    $second = $executor->run([$make('d'), $make('e')]);

    expect(array_map(fn (SampleOutcome $outcome) => $outcome->error, $first))->toBe(['a', 'b', 'c'])
        ->and(array_map(fn (SampleOutcome $outcome) => $outcome->error, $second))->toBe(['d', 'e']);
});

it('degrades to sequential execution when concurrency fails, warning once', function () {
    Concurrency::shouldReceive('run')->once()->andThrow(new RuntimeException('cannot serialize'));

    $warnings = [];
    $executor = new ConcurrentExecutor(3, fallback: function (string $reason) use (&$warnings) {
        $warnings[] = $reason;
    });

    $make = fn (string $id) => fn () => SampleOutcome::error('X', $id, 0.0);

    $first = $executor->run([$make('a'), $make('b')]);
    $second = $executor->run([$make('c'), $make('d')]); // no further Concurrency::run calls

    expect(array_map(fn ($o) => $o->error, $first))->toBe(['a', 'b'])
        ->and(array_map(fn ($o) => $o->error, $second))->toBe(['c', 'd'])
        ->and($warnings)->toBe(['cannot serialize']);
});

it('single-task waves skip the concurrency machinery', function () {
    Concurrency::shouldReceive('run')->never();

    $executor = new ConcurrentExecutor(3, fallback: fn () => null);

    $outcomes = $executor->run([fn () => SampleOutcome::error('X', 'only', 0.0)]);

    expect($outcomes[0]->error)->toBe('only');
});
