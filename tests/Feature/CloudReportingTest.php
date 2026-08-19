<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use PHPUnit\Framework\AssertionFailedError;
use Vizra\Evals\Cloud\Environment;
use Vizra\Evals\Cloud\Payload;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Pest\Plugin;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

beforeEach(function () {
    config([
        'evals.cloud.endpoint' => 'https://vizra.test/api/v1/runs',
        'evals.cloud.key' => 'vz_test_key',
        'evals.cloud.samples' => true,
    ]);

    Ai::fakeAgent(SupportAgent::class, fn (string $prompt) => str_contains($prompt, 'refund')
        ? 'Refunds are accepted within 30 days.'
        : 'Yes, we ship to France.');
});

function cloudAccepts(): void
{
    Http::fake(['vizra.test/*' => Http::response([
        'status' => 'recorded',
        'run' => ['id' => 'x', 'url' => 'https://vizra.test/cloud/projects/acme/runs/x'],
    ], 201)]);
}

it('reports a finished run without being asked', function () {
    cloudAccepts();

    $this->artisan('evals:run', ['suite' => SupportQuality::class, '--concurrency' => 1])
        ->assertExitCode(0);

    Http::assertSent(fn ($request) => $request->url() === 'https://vizra.test/api/v1/runs'
        && $request->hasHeader('Authorization', 'Bearer vz_test_key')
        && $request['run']['id'] === EvalRun::sole()->id);
});

it('sends nothing when no key is configured', function () {
    config(['evals.cloud.key' => null]);
    Http::fake();

    $this->artisan('evals:run', ['suite' => SupportQuality::class, '--concurrency' => 1])
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('does not report when --no-report is passed', function () {
    Http::fake();

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--no-report' => true,
    ])->assertExitCode(0);

    Http::assertNothingSent();
});

/**
 * Dry-run numbers come from faked agents. Filed in the cloud they would be
 * indistinguishable from real ones and would move the trend line.
 */
it('never reports a dry run', function () {
    Http::fake();

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--dry-run' => true,
    ]);

    Http::assertNothingSent();
});

/**
 * The whole point of the reporter is that it cannot break a build. A run that
 * passed its gate has passed it whether or not the uplink was up.
 */
it('keeps the gate exit code when the upload fails', function () {
    Http::fake(['vizra.test/*' => Http::response(['message' => 'nope'], 500)]);

    $this->artisan('evals:run', ['suite' => SupportQuality::class, '--concurrency' => 1])
        ->assertExitCode(0);
});

it('keeps the gate exit code when the network is down', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('evals:run', ['suite' => SupportQuality::class, '--concurrency' => 1])
        ->assertExitCode(0);
});

it('does not turn a failing gate green by reporting successfully', function () {
    Ai::fakeAgent(SupportAgent::class, fn () => 'I cannot help with that.');
    cloudAccepts();

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--min-score' => '0.9',
    ])->assertExitCode(1);

    Http::assertSentCount(1);
});

/**
 * Sample detail is verbatim model input and output. A team that switches it
 * off must get scores and nothing else — no responses, no judge reasoning.
 */
it('omits sample detail when samples are switched off', function () {
    config(['evals.cloud.samples' => false]);
    cloudAccepts();

    $this->artisan('evals:run', ['suite' => SupportQuality::class, '--concurrency' => 1]);

    Http::assertSent(function ($request) {
        expect($request['samples'])->toBe([])
            ->and($request['rows'])->not->toBeEmpty()
            ->and($request['summary']['score_mean'])->not->toBeNull();

        return true;
    });
});

it('carries response text, tool calls and assertions when samples are on', function () {
    cloudAccepts();

    $this->artisan('evals:run', ['suite' => SupportQuality::class, '--concurrency' => 1]);

    Http::assertSent(function ($request) {
        $sample = $request['samples'][0];

        expect($sample['response_text'])->toContain('30 days')
            ->and($sample['input'])->not->toBeEmpty()
            ->and($sample['assertions'])->not->toBeEmpty()
            ->and($sample['assertions'][0])->toHaveKeys(['name', 'status', 'score', 'judge_reasoning']);

        return true;
    });
});

/**
 * The cloud rejects a sample whose (row_hash, combo_key) no row declared,
 * because such a sample would be drawn nowhere. Every sample we send must
 * therefore point at a row in the same payload.
 */
it('only sends samples whose rows are in the same payload', function () {
    cloudAccepts();

    $this->artisan('evals:run', ['suite' => SupportQuality::class, '--concurrency' => 1]);

    Http::assertSent(function ($request) {
        $rows = collect($request['rows'])->map(fn ($r) => $r['row_hash'].'|'.$r['combo_key'])->all();

        foreach ($request['samples'] as $sample) {
            expect($rows)->toContain($sample['row_hash'].'|'.$sample['combo_key']);
        }

        return true;
    });
});

it('flattens the run so the wire format has no nested git object', function () {
    $run = EvalRun::create([
        'suite' => 'App\\Evals\\SupportQuality',
        'status' => EvalRun::STATUS_COMPLETED,
        'git_sha' => str_repeat('a', 40),
        'git_branch' => 'main',
        'config' => [],
        'summary' => ['per_row' => []],
    ]);

    $payload = Payload::for($run, withSamples: false);

    expect($payload['run'])->toHaveKey('git_sha')
        ->and($payload['run'])->not->toHaveKey('git')
        ->and($payload['run']['git_sha'])->toBe(str_repeat('a', 40));
});

/**
 * CI checks out a detached HEAD, so git itself reports the branch as "HEAD".
 * Filing every CI run under a branch called HEAD would collapse them all
 * into one history.
 */
it('prefers the CI branch over a detached HEAD', function () {
    putenv('GITHUB_ACTIONS=true');
    putenv('GITHUB_HEAD_REF=fix/refund-copy');
    putenv('GITHUB_REPOSITORY=vizra-ai/app');
    putenv('GITHUB_RUN_ID=99');
    putenv('GITHUB_REF=refs/pull/42/merge');

    $run = EvalRun::create([
        'suite' => 'App\\Evals\\SupportQuality',
        'status' => EvalRun::STATUS_COMPLETED,
        'git_branch' => 'HEAD',
        'config' => [],
        'summary' => ['per_row' => []],
    ]);

    $payload = Payload::for($run, withSamples: false);

    expect($payload['run']['git_branch'])->toBe('fix/refund-copy')
        ->and($payload['run']['pull_request'])->toBe(42)
        ->and($payload['run']['ci_provider'])->toBe('github')
        ->and($payload['run']['ci_build_url'])->toBe('https://github.com/vizra-ai/app/actions/runs/99')
        ->and($payload['run']['environment'])->toBe('ci');

    foreach (['GITHUB_ACTIONS', 'GITHUB_HEAD_REF', 'GITHUB_REPOSITORY', 'GITHUB_RUN_ID', 'GITHUB_REF'] as $var) {
        putenv($var);
    }
});

/**
 * Every CI variable is cleared for the duration, including the bare `CI` that
 * all of them set.
 *
 * This is the one test asserting the *absence* of CI, and it runs inside our
 * own CI, where GITHUB_ACTIONS is set by the runner — so it passed on every
 * laptop and failed on every push, which is the worst way for a badge to be
 * red. Restored afterwards rather than left unset: the process is shared with
 * every test that follows.
 */
it('files a local run under the app environment', function () {
    $saved = [];

    foreach (['CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'CIRCLECI', 'BUILDKITE'] as $var) {
        $saved[$var] = getenv($var);
        putenv($var);
    }

    try {
        expect(Environment::name())->toBe('testing');
    } finally {
        foreach ($saved as $var => $value) {
            if ($value !== false) {
                putenv("{$var}={$value}");
            }
        }
    }
});

/*
 * The Pest surface.
 *
 * Reporting lived only in the two console commands, so the authoring surface
 * the quickstart teaches never reached Cloud at all — someone could follow the
 * docs, subscribe, run their suite and be shown an empty dashboard.
 */
describe('from Pest', function () {
    beforeEach(function () {
        Plugin::setEvalMode(true);
    });

    afterEach(function () {
        Plugin::setEvalMode(false);
    });

    it('reports a passing eval', function () {
        cloudAccepts();

        expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
            ->dataset([['input' => 'Refunds?', 'expected' => '30 days']])
            ->concurrency(1)
            ->assert(fn ($a, $row) => $a->contains($row->expected()))
        );

        Http::assertSent(fn ($request) => $request->url() === 'https://vizra.test/api/v1/runs'
            && $request['run']['id'] === EvalRun::sole()->id);
    });

    /**
     * The case a naive implementation gets wrong: toPassEval throws on a
     * failed gate, so reporting after the assertion would send only the runs
     * that passed — and a run that got worse is the one you actually need in
     * the dashboard.
     */
    it('reports a run whose gate failed, before failing the test', function () {
        cloudAccepts();
        Ai::fakeAgent(SupportAgent::class, fn () => 'I cannot help with that.');

        try {
            expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
                ->dataset([['input' => 'Refunds?', 'expected' => '30 days']])
                ->concurrency(1)
                ->assert(fn ($a, $row) => $a->contains($row->expected()))
                ->gate(minScore: 0.8)
            );
            $this->fail('Expected the gate to fail the test.');
        } catch (AssertionFailedError) {
            // The point of the test is what was sent before this was thrown.
        }

        Http::assertSent(fn ($request) => $request['run']['id'] === EvalRun::sole()->id
            && $request['summary']['score_mean'] < 0.8);
    });

    it('sends nothing when the eval opts out with report(false)', function () {
        Http::fake();

        expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
            ->dataset([['input' => 'Refunds?', 'expected' => '30 days']])
            ->concurrency(1)
            ->report(false)
            ->assert(fn ($a, $row) => $a->contains($row->expected()))
        );

        Http::assertNothingSent();
    });

    it('sends nothing when no key is configured', function () {
        config(['evals.cloud.key' => null]);
        Http::fake();

        expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
            ->dataset([['input' => 'Refunds?', 'expected' => '30 days']])
            ->concurrency(1)
            ->assert(fn ($a, $row) => $a->contains($row->expected()))
        );

        Http::assertNothingSent();
    });

    /** A flaky uplink must never turn a green build red. */
    it('does not fail the test when the push fails', function () {
        Http::fake(fn () => throw new ConnectionException('network down'));

        expect(SupportAgent::class)->toPassEval(fn ($eval) => $eval
            ->dataset([['input' => 'Refunds?', 'expected' => '30 days']])
            ->concurrency(1)
            ->assert(fn ($a, $row) => $a->contains($row->expected()))
        );

        expect(EvalRun::sole()->status)->toBe(EvalRun::STATUS_COMPLETED);
    });
});
