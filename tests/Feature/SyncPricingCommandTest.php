<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Usage;
use Vizra\Evals\Support\Pricing;

/**
 * `evals:sync-pricing` — the thing that stops a cost estimate being a guess
 * from install day.
 *
 * The file it writes is meant to be committed, so the bar is higher than
 * "produces valid PHP": it has to be readable in a diff, it has to leave a
 * working table alone when the source misbehaves, and what it writes has to
 * be what Pricing then reads.
 */
beforeEach(function () {
    $this->path = config_path('evals-pricing.php');

    if (is_file($this->path)) {
        unlink($this->path);
    }
});

afterEach(function () {
    if (is_file($this->path)) {
        unlink($this->path);
    }
});

function pricingSource(array $models, string $lastUpdated = '2026-08-08 02:00:00'): void
{
    Http::fake([
        '*' => Http::response([
            'data' => ['models' => $models],
            'meta' => ['last_updated' => $lastUpdated],
        ]),
    ]);
}

it('writes a file the config loader and Pricing can both read', function () {
    pricingSource([
        'claude-sonnet-5' => [
            'input_price_per_million' => 2.0,
            'output_price_per_million' => 10.0,
            'cache_read_price_per_million' => 0.2,
            'cache_write_price_per_million' => 2.5,
            'provider' => 'anthropic',
        ],
    ]);

    $this->artisan('evals:sync-pricing')->assertSuccessful();

    expect($this->path)->toBeFile();

    $loaded = require $this->path;

    expect($loaded['models']['claude-sonnet-5'])->toBe([
        'input' => 2.0,
        'output' => 10.0,
        'cache_read' => 0.2,
        'cache_write' => 2.5,
    ]);

    // The provider rides along as a comment, not as a key — Pricing looks up
    // by model id and a stray key would land in the price array.
    expect($loaded['models']['claude-sonnet-5'])->not->toHaveKey('provider');
});

it('asks only for canonical ids unless told otherwise', function () {
    pricingSource(['gpt-5' => ['input_price_per_million' => 1.25, 'output_price_per_million' => 10.0]]);

    $this->artisan('evals:sync-pricing')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'canonical=1'));
});

it('asks for every alias with --all', function () {
    pricingSource(['gpt-5' => ['input_price_per_million' => 1.25, 'output_price_per_million' => 10.0]]);

    $this->artisan('evals:sync-pricing --all')->assertSuccessful();

    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'canonical'));
});

it('writes prices as decimals rather than scientific notation', function () {
    // $0.000025 per million renders as 2.5E-5 under PHP's default float
    // formatting, which is unreadable in the diff this file exists to produce.
    pricingSource([
        'cheap-model' => [
            'input_price_per_million' => 0.000025,
            'output_price_per_million' => 0.0001,
        ],
    ]);

    $this->artisan('evals:sync-pricing')->assertSuccessful();

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('0.000025')
        ->and($contents)->not->toContain('E-');
});

it('leaves an existing table alone when the source is unreachable', function () {
    file_put_contents($this->path, "<?php return ['models' => ['gpt-5' => ['input' => 1.25, 'output' => 10.0]]];");

    Http::fake(['*' => Http::response('', 503)]);

    $this->artisan('evals:sync-pricing')->assertFailed();

    $loaded = require $this->path;

    expect($loaded['models']['gpt-5']['input'])->toBe(1.25);
});

it('refuses to overwrite a table with an empty answer', function () {
    file_put_contents($this->path, "<?php return ['models' => ['gpt-5' => ['input' => 1.25, 'output' => 10.0]]];");

    pricingSource([]);

    // An empty write would turn every cost into "unknown" on the next run,
    // silently and everywhere at once.
    $this->artisan('evals:sync-pricing')->assertFailed();

    $loaded = require $this->path;

    expect($loaded['models'])->toHaveKey('gpt-5');
});

it('reports what moved rather than changing prices quietly', function () {
    file_put_contents($this->path, "<?php return ['models' => [
        'claude-sonnet-5' => ['input' => 3.0, 'output' => 15.0],
        'retired-model' => ['input' => 1.0, 'output' => 2.0],
    ]];");

    pricingSource([
        'claude-sonnet-5' => ['input_price_per_million' => 2.0, 'output_price_per_million' => 10.0],
        'brand-new-model' => ['input_price_per_million' => 0.5, 'output_price_per_million' => 1.5],
    ]);

    $this->artisan('evals:sync-pricing')
        ->expectsOutputToContain('claude-sonnet-5  in 3 → 2, out 15 → 10')
        ->expectsOutputToContain('+ brand-new-model')
        ->expectsOutputToContain('- retired-model')
        ->assertSuccessful();
});

it('changes nothing on a dry run', function () {
    pricingSource(['gpt-5' => ['input_price_per_million' => 1.25, 'output_price_per_million' => 10.0]]);

    $this->artisan('evals:sync-pricing --dry-run')->assertSuccessful();

    expect(is_file($this->path))->toBeFalse();
});

it('skips a malformed entry instead of writing a broken table', function () {
    pricingSource([
        'good-model' => ['input_price_per_million' => 1.0, 'output_price_per_million' => 2.0],
        'no-output' => ['input_price_per_million' => 1.0],
        'not-an-array' => 'nonsense',
    ]);

    $this->artisan('evals:sync-pricing')->assertSuccessful();

    expect(array_keys((require $this->path)['models']))->toBe(['good-model']);
});

it('produces a table that resolves through Pricing end to end', function () {
    pricingSource([
        'gpt-4o' => [
            'input_price_per_million' => 2.5,
            'output_price_per_million' => 10.0,
            'cache_read_price_per_million' => 1.25,
        ],
    ]);

    $this->artisan('evals:sync-pricing')->assertSuccessful();

    config()->set('evals-pricing', require $this->path);
    config()->set('evals.pricing', []);

    $usage = new Usage(promptTokens: 1_000_000);

    expect(Pricing::cost($usage, 'openai', 'gpt-4o'))->toEqualWithDelta(2.5, 0.0001)
        ->and(Pricing::sourceFor('openai', 'gpt-4o'))->toBe('synced');
});
