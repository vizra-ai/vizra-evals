<?php

use Laravel\Ai\Enums\Lab;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Run\Comparison;
use Vizra\Evals\Run\Gate;
use Vizra\Evals\Run\RunResult;
use Vizra\Evals\Support\Combo;

describe('Combo', function () {
    it('expands an empty matrix to a single none combo', function () {
        $combos = Combo::matrix([]);

        expect($combos)->toHaveCount(1)
            ->and($combos[0]->isNone())->toBeTrue()
            ->and($combos[0]->key())->toBe('-')
            ->and($combos[0]->toArray())->toBeNull();
    });

    it('builds keys from Lab enums and strings', function () {
        $combos = Combo::matrix([
            ['provider' => Lab::Anthropic, 'model' => 'claude-sonnet-5'],
            ['provider' => 'openai', 'model' => 'gpt-5'],
        ]);

        expect($combos[0]->key())->toBe('anthropic/claude-sonnet-5')
            ->and($combos[0]->toArray())->toBe(['provider' => 'anthropic', 'model' => 'claude-sonnet-5'])
            ->and($combos[1]->key())->toBe('openai/gpt-5');
    });
});

describe('Gate', function () {
    function fakeResult(?float $score, ?float $passRate): RunResult
    {
        return new RunResult(new EvalRun, [
            'score_mean' => $score,
            'pass_rate' => $passRate,
            'errors' => 0,
        ]);
    }

    it('passes when no thresholds are configured', function () {
        expect((new Gate)->evaluate(fakeResult(0.1, 0.1))['passed'])->toBeTrue();
    });

    it('fails on score and pass-rate thresholds', function () {
        $gate = new Gate(minScore: 0.8, minPassRate: 0.9);

        $outcome = $gate->evaluate(fakeResult(0.7, 0.95));

        expect($outcome['passed'])->toBeFalse()
            ->and($outcome['failures'])->toHaveCount(1)
            ->and($outcome['failures'][0])->toContain('score');

        expect($gate->evaluate(fakeResult(0.85, 0.95))['passed'])->toBeTrue();
    });

    it('fails when regressions exceed the allowance', function () {
        $comparison = new Comparison(
            baselineRunId: 'ref',
            regressed: [['key' => 'a'], ['key' => 'b']],
            improved: [],
            newlyFailing: [],
            newRows: [],
            removedRows: [],
            scoreDelta: -0.1,
            passRateDelta: 0.0,
        );

        expect((new Gate(maxRegressions: 1))->evaluate(fakeResult(1.0, 1.0), $comparison)['passed'])->toBeFalse()
            ->and((new Gate(maxRegressions: 2))->evaluate(fakeResult(1.0, 1.0), $comparison)['passed'])->toBeTrue();
    });
});
