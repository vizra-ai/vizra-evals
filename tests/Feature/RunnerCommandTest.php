<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Vizra\Evals\Models\EvalRun;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

beforeEach(function () {
    config([
        'evals.cloud.endpoint' => 'https://vizra.test/api/v1/runs',
        'evals.cloud.key' => 'vz_test_key',
    ]);

    Ai::fakeAgent(SupportAgent::class, fn () => 'Refunds are accepted within 30 days.');
});

/** The cloud handing out one queued run, then accepting the result. */
function cloudHasWork(array $request = []): void
{
    Http::fake([
        'vizra.test/api/v1/runner/next*' => Http::response(['request' => array_merge([
            'id' => 7,
            'suite' => SupportQuality::class,
            'samples' => null,
        ], $request)], 200),
        'vizra.test/api/v1/runner/7/complete' => Http::response(['status' => 'completed'], 200),
        'vizra.test/api/v1/runs' => Http::response(['status' => 'recorded', 'run' => ['url' => 'https://vizra.test/r/1']], 201),
    ]);
}

it('runs the suite the cloud asked for and reports it back', function () {
    cloudHasWork();

    $this->artisan('evals:runner')->assertExitCode(0);

    expect(EvalRun::count())->toBe(1);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/runner/7/complete')
        && $r['status'] === 'completed'
        && $r['run_id'] === EvalRun::sole()->id);
});

/**
 * The overwhelmingly common case: once a minute, forever, finding nothing.
 * It must not run anything and must not make noise.
 */
it('does nothing when the cloud has no work', function () {
    Http::fake(['vizra.test/*' => Http::response(null, 204)]);

    $this->artisan('evals:runner')->assertExitCode(0);

    expect(EvalRun::count())->toBe(0);
    Http::assertSentCount(1);
});

/**
 * This runs from cron inside someone else's app. An unreachable cloud must
 * not fail their whole schedule:run.
 */
it('survives the cloud being unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('evals:runner')->assertExitCode(0);

    expect(EvalRun::count())->toBe(0);
});

it('does nothing when no cloud key is configured', function () {
    config(['evals.cloud.key' => null]);
    Http::fake();

    $this->artisan('evals:runner')->assertExitCode(1);

    Http::assertNothingSent();
});

/**
 * The runner reports by hand so the run can be tied to the request that asked
 * for it. Reporting twice would be harmless but wasteful, so evals:run must
 * be told not to.
 */
it('reports the run exactly once', function () {
    cloudHasWork();

    $this->artisan('evals:runner');

    $ingests = 0;
    Http::assertSent(function ($r) use (&$ingests) {
        if (str_ends_with($r->url(), '/api/v1/runs')) {
            $ingests++;
        }

        return true;
    });

    expect($ingests)->toBe(1);
});

it('honours a sample override from the request', function () {
    cloudHasWork(['samples' => 2]);

    $this->artisan('evals:runner');

    // Two samples per row rather than the suite's declared one.
    expect(EvalRun::sole()->total_samples)->toBe(4);
});

/** A failure has to be reported, or the request sits until its lease lapses. */
it('tells the cloud when the upload fails', function () {
    Http::fake([
        'vizra.test/api/v1/runner/next*' => Http::response(['request' => [
            'id' => 7, 'suite' => SupportQuality::class, 'samples' => null,
        ]], 200),
        'vizra.test/api/v1/runs' => Http::response(['message' => 'nope'], 500),
        'vizra.test/api/v1/runner/7/complete' => Http::response(['status' => 'failed'], 200),
    ]);

    $this->artisan('evals:runner');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/runner/7/complete') && $r['status'] === 'failed');
});

/**
 * There is only one URL to configure, so the runner endpoints are derived
 * from the ingest one — a self-hosted instance gets them on its own host.
 */
it('derives the runner endpoints from the ingest endpoint', function () {
    config(['evals.cloud.endpoint' => 'http://localhost:8484/api/v1/runs']);
    Http::fake(['localhost:8484/*' => Http::response(null, 204)]);

    $this->artisan('evals:runner');

    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://localhost:8484/api/v1/runner/next'));
});

/**
 * The package adds the check to the app's scheduler itself, so nobody has to
 * remember a line in routes/console.php to make the Run button work.
 */
it('schedules itself when a cloud key is configured', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'evals:runner'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

/**
 * A package must not add an outbound network call every minute to an app that
 * never asked for one. No key, no schedule entry, no behaviour at all.
 */
it('schedules nothing when no cloud key is configured', function () {
    config(['evals.cloud.key' => null]);

    // Forgotten so the after-resolving callback runs again against the
    // config as it now stands.
    app()->forgetInstance(Schedule::class);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'evals:runner'));

    expect($events)->toBeEmpty();
});

/**
 * An eval takes minutes and the schedule fires every minute; without this a
 * long run would stack a new process on top of itself every sixty seconds.
 */
it('does not let runner checks overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'evals:runner'));

    expect($event->withoutOverlapping)->toBeTrue();
});

/**
 * A failing gate is a successful run: the eval executed and scored below the
 * threshold, which is the answer someone clicked Run to get. Reporting it as
 * a failure would hide the result and leave the dashboard saying the run
 * never happened.
 */
it('treats a failing gate as a completed run', function () {
    Ai::fakeAgent(SupportAgent::class, fn () => 'I cannot help with that.');
    cloudHasWork();

    $this->artisan('evals:runner');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/runner/7/complete')
        && $r['status'] === 'completed');
});

/** A suite that does not exist here means nothing ran, and should say so. */
it('reports a suite it cannot resolve as a failure', function () {
    cloudHasWork(['suite' => 'App\\Evals\\NotInThisCodebase']);

    $this->artisan('evals:runner')->assertExitCode(1);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/runner/7/complete')
        && $r['status'] === 'failed'
        && str_contains($r['error'], 'NotInThisCodebase'));
});

/**
 * A variant run: the cloud hands down rows and those are what execute,
 * instead of whatever the eval class declares.
 */
it('runs the dataset the cloud handed it', function () {
    cloudHasWork(['dataset' => [
        ['hash' => str_repeat('a', 64), 'input' => 'Only this row', 'expected' => 'only'],
    ]]);

    $this->artisan('evals:runner');

    $run = EvalRun::sole();

    expect($run->total_rows)->toBe(1)
        // The suite's own dataset has two rows and neither is this one.
        ->and($run->rowResults()->sole()->input)->toBe('Only this row');
});

/**
 * The identity rule. A row we cannot reproduce from content alone — a
 * multi-turn one, whose prior context never left the customer's app — must
 * keep the hash it has always had, or it stops joining against the baseline.
 */
it('honours the hash the cloud assigned rather than recomputing', function () {
    $hash = str_repeat('c', 64);

    cloudHasWork(['dataset' => [
        ['hash' => $hash, 'input' => 'A row whose identity was decided elsewhere', 'expected' => 'x'],
    ]]);

    $this->artisan('evals:runner');

    expect(EvalRun::sole()->rowResults()->sole()->row_hash)->toBe($hash);
});

/** No dataset means the eval's own, which is the ordinary case. */
it('falls back to the evaluation dataset when none is handed down', function () {
    cloudHasWork();

    $this->artisan('evals:runner');

    expect(EvalRun::sole()->total_rows)->toBe(2);
});

it('does not leave the temporary dataset file behind', function () {
    cloudHasWork(['dataset' => [
        ['hash' => str_repeat('a', 64), 'input' => 'One row', 'expected' => 'one'],
    ]]);

    $before = glob(sys_get_temp_dir().'/vizra-dataset-*');

    $this->artisan('evals:runner');

    expect(glob(sys_get_temp_dir().'/vizra-dataset-*'))->toBe($before);
});

/*
 * The lease.
 *
 * A claim is held for a fixed window and handed to somebody else when it
 * lapses — which for an eval means running it, and spending the customer's
 * tokens, twice for one click. A large suite against a slow model legitimately
 * outlives that window, so a runner that never says it is still working is a
 * runner whose long runs are guaranteed to be taken off it.
 */
it('tells the cloud it is still working as the run progresses', function () {
    cloudHasWork(['heartbeat_seconds' => 0]);

    $this->artisan('evals:runner')->assertExitCode(0);

    // The fixture is two rows at one sample each, and a zero interval means
    // every completed sample reports.
    Http::assertSentCount(5); // next + 2 heartbeats + ingest + complete

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/runner/7/heartbeat')
        // The cloud checks this against whoever holds the claim; without it
        // every heartbeat is rejected as coming from a stale runner.
        && $r['runner'] !== null);
});

/**
 * Throttled, or a thousand-sample run is a thousand HTTP calls to say
 * something the cloud only needs to hear every few minutes.
 */
it('does not beat more often than the cloud asked for', function () {
    cloudHasWork(['heartbeat_seconds' => 600]);

    $this->artisan('evals:runner')->assertExitCode(0);

    $beats = 0;

    Http::assertSent(function ($r) use (&$beats) {
        $beats += str_ends_with($r->url(), '/runner/7/heartbeat') ? 1 : 0;

        return true;
    });

    expect($beats)->toBe(1);
});

/**
 * An older cloud does not send an interval. The runner still has a lease to
 * keep alive, so it falls back rather than beating on every sample.
 */
it('falls back to a default interval when the cloud sends none', function () {
    cloudHasWork();

    $this->artisan('evals:runner')->assertExitCode(0);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/runner/7/heartbeat'));
});

/** A heartbeat that fails must not take the run down with it. */
it('finishes the run even when heartbeats are refused', function () {
    Http::fake([
        'vizra.test/api/v1/runner/next*' => Http::response(['request' => [
            'id' => 7,
            'suite' => SupportQuality::class,
            'samples' => null,
            'heartbeat_seconds' => 0,
        ]], 200),
        'vizra.test/api/v1/runner/7/heartbeat' => Http::response(['message' => 'no longer yours'], 409),
        'vizra.test/api/v1/runner/7/complete' => Http::response(['status' => 'completed'], 200),
        'vizra.test/api/v1/runs' => Http::response(['status' => 'recorded', 'run' => ['url' => null]], 201),
    ]);

    $this->artisan('evals:runner')->assertExitCode(0);

    expect(EvalRun::sole()->status)->toBe('completed');
});
