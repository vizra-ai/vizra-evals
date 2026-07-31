<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Judge\ComparisonJudgeAgent;
use Vizra\Evals\Judge\JudgeAgent;
use Vizra\Evals\Models\EvalAssertionResult;
use Vizra\Evals\Run\Runner;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

function judgeEvaluation(Closure $evaluate): SupportQuality
{
    return new class($evaluate) extends SupportQuality
    {
        public function __construct(private readonly Closure $evaluateWith) {}

        public function dataset(): Dataset
        {
            return Dataset::fromArray([
                ['input' => 'What is your refund policy?', 'expected' => '30 days'],
            ]);
        }

        public function evaluate(Row $row, AgentResponse $response): void
        {
            // Bind so the closure can call protected assertion helpers as $this.
            ($this->evaluateWith)->call($this, $row, $response);
        }
    };
}

beforeEach(function () {
    Ai::fakeAgent(SupportAgent::class, fn () => 'Refunds are accepted within 30 days.');
});

it('passes when the judge score meets minScore, persisting score and reasoning', function () {
    Ai::fakeAgent(JudgeAgent::class, [
        new StructuredTextResponse(
            ['score' => 8, 'reasoning' => 'Accurate and clearly worded.'],
            '{"score":8}',
            new Usage(promptTokens: 200, completionTokens: 40),
            new Meta('anthropic', 'claude-sonnet-5'),
        ),
    ]);

    $evaluation = judgeEvaluation(function () {
        $this->judge()->criteria('Answers without inventing policy.')->minScore(7);
    });

    $result = (new Runner(concurrencyOverride: 1))->run($evaluation);

    $judge = EvalAssertionResult::where('type', 'judge')->first();

    expect($judge->status)->toBe('passed')
        ->and($judge->score)->toEqualWithDelta(0.8, 0.0001)
        ->and($judge->judge_reasoning)->toBe('Accurate and clearly worded.')
        ->and($judge->name)->toStartWith('judge:')
        ->and($judge->meta['judge_cost'])->toBeGreaterThan(0)
        ->and($result->run->fresh()->judge_cost)->toBeGreaterThan(0)
        ->and($result->run->fresh()->score)->toEqualWithDelta(0.8, 0.0001);
});

it('fails when the judge score is below minScore', function () {
    Ai::fakeAgent(JudgeAgent::class, [['score' => 5, 'reasoning' => 'Vague.']]);

    $evaluation = judgeEvaluation(function () {
        $this->judge()->minScore(7);
    });

    (new Runner(concurrencyOverride: 1))->run($evaluation);

    $judge = EvalAssertionResult::where('type', 'judge')->first();

    expect($judge->status)->toBe('failed')
        ->and($judge->score)->toEqualWithDelta(0.5, 0.0001)
        ->and($judge->message)->toContain('minimum 7');
});

it('fails when a dimension is below its threshold even if the overall score passes', function () {
    Ai::fakeAgent(JudgeAgent::class, [[
        'score' => 9,
        'reasoning' => 'Good overall, weak accuracy.',
        'dimensions' => ['accuracy' => 6, 'tone' => 9],
    ]]);

    $evaluation = judgeEvaluation(function () {
        $this->judge()->minScore(7)->dimensions(['accuracy' => 7, 'tone' => 6]);
    });

    (new Runner(concurrencyOverride: 1))->run($evaluation);

    $judge = EvalAssertionResult::where('type', 'judge')->first();

    expect($judge->status)->toBe('failed')
        ->and($judge->message)->toContain('accuracy 6/7')
        ->and($judge->meta['dimensions'])->toBe(['accuracy' => 6, 'tone' => 9]);
});

it('supports pairwise comparison with prefer()', function () {
    Ai::fakeAgent(ComparisonJudgeAgent::class, [
        ['winner' => 'a', 'reasoning' => 'A is more specific.'],
        ['winner' => 'b', 'reasoning' => 'B is more specific.'],
    ]);

    $evaluation = judgeEvaluation(function () {
        $this->judge()->comparedTo('A reference answer.')->prefer('actual');
        $this->judge()->comparedTo('Another reference.')->prefer('actual');
    });

    (new Runner(concurrencyOverride: 1))->run($evaluation);

    $judges = EvalAssertionResult::where('type', 'judge')->orderBy('id')->get();

    expect($judges[0]->status)->toBe('passed')
        ->and($judges[0]->score)->toEqualWithDelta(1.0, 0.0001)
        ->and($judges[1]->status)->toBe('failed')
        ->and($judges[1]->score)->toEqualWithDelta(0.0, 0.0001)
        ->and($judges[1]->actual)->toBe('winner: reference');
});

it('skips judges without spending tokens when a gate fails', function () {
    Ai::fakeAgent(JudgeAgent::class, []);

    $evaluation = judgeEvaluation(function () {
        $this->assertContains('a string that will never appear')->gate();
        $this->judge()->criteria('quality')->minScore(7);
    });

    (new Runner(concurrencyOverride: 1))->run($evaluation);

    JudgeAgent::assertNeverPrompted();

    $judge = EvalAssertionResult::where('type', 'judge')->first();

    expect($judge->status)->toBe('skipped')
        ->and($judge->score)->toBeNull()
        ->and(EvalAssertionResult::where('name', 'contains')->first()->is_gate)->toBeTrue();
});

it('scores tie as 0.5 and failed when a preference is stated', function () {
    Ai::fakeAgent(ComparisonJudgeAgent::class, [['winner' => 'tie', 'reasoning' => 'Equivalent.']]);

    $evaluation = judgeEvaluation(function () {
        $this->judge()->comparedTo('ref')->prefer('actual');
    });

    (new Runner(concurrencyOverride: 1))->run($evaluation);

    $judge = EvalAssertionResult::where('type', 'judge')->first();

    expect($judge->status)->toBe('failed')
        ->and($judge->score)->toEqualWithDelta(0.5, 0.0001);
});
