<?php

use Laravel\Ai\Ai;
use Vizra\Evals\Judge\JudgeAgent;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

describe('dry run', function () {
    it('executes the whole suite with faked agents and zero network calls', function () {
        // Deliberately no fakes registered by the test: --dry-run must
        // register them itself. A stray real prompt would attempt HTTP and
        // fail loudly in the test environment.
        $this->artisan('evals:run', [
            'suite' => SupportQuality::class,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $run = EvalRun::first();

        expect($run->status)->toBe(EvalRun::STATUS_COMPLETED)
            ->and($run->config['dry_run'])->toBeTrue()
            ->and($run->config['concurrency'])->toBe(1) // fakes force sequential
            ->and($run->total_samples)->toBe(2);
    });
});

describe('calibrate', function () {
    it('reports judge/human agreement and worst disagreements', function () {
        $dataset = tempnam(sys_get_temp_dir(), 'calibrate').'.jsonl';

        file_put_contents($dataset, implode("\n", [
            json_encode(['input' => 'Q1', 'output' => 'A perfect answer', 'human_score' => 9]),
            json_encode(['input' => 'Q2', 'output' => 'A decent answer', 'human_score' => 7]),
            json_encode(['input' => 'Q3', 'output' => 'A bad answer', 'human_score' => 2]),
        ]));

        // Judge agrees on Q1 (9), is off by one on Q2 (8), and wildly
        // disagrees on Q3 (9 vs 2).
        Ai::fakeAgent(JudgeAgent::class, [
            ['score' => 9, 'reasoning' => 'Excellent.'],
            ['score' => 8, 'reasoning' => 'Good.'],
            ['score' => 9, 'reasoning' => 'I liked it.'],
        ]);

        $this->artisan('evals:calibrate', ['dataset' => $dataset, '--criteria' => 'Correctness'])
            ->expectsOutputToContain('33.3%')  // exact agreement 1/3
            ->expectsOutputToContain('66.7%')  // within ±1 2/3
            ->expectsOutputToContain('9 vs 2') // worst disagreement
            ->assertExitCode(0);

        $run = EvalRun::where('suite', 'like', 'calibration:%')->first();

        expect($run)->not->toBeNull()
            ->and($run->status)->toBe(EvalRun::STATUS_COMPLETED)
            ->and($run->total_rows)->toBe(3)
            ->and($run->rowResults()->count())->toBe(3);

        unlink($dataset);
    });

    it('computes verdict accuracy for pass/fail labels', function () {
        $dataset = tempnam(sys_get_temp_dir(), 'calibrate').'.jsonl';

        file_put_contents($dataset, implode("\n", [
            json_encode(['input' => 'Q1', 'output' => 'Good', 'human_verdict' => 'pass']),
            json_encode(['input' => 'Q2', 'output' => 'Bad', 'human_verdict' => 'fail']),
        ]));

        // min_score defaults to 7: 9 → pass (agrees), 8 → pass (human said fail).
        Ai::fakeAgent(JudgeAgent::class, [
            ['score' => 9, 'reasoning' => 'Fine.'],
            ['score' => 8, 'reasoning' => 'Fine.'],
        ]);

        $this->artisan('evals:calibrate', ['dataset' => $dataset])
            ->expectsOutputToContain('50.0%')
            ->assertExitCode(0);

        unlink($dataset);
    });

    it('fails for an unreadable dataset', function () {
        $this->artisan('evals:calibrate', ['dataset' => '/nope/missing.jsonl'])->assertExitCode(1);
    });
});
