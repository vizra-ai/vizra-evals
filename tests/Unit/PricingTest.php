<?php

use Laravel\Ai\Responses\Data\Usage;
use Vizra\Evals\Support\Pricing;

it('computes cost from the configured table', function () {
    $usage = new Usage(promptTokens: 1_000_000, completionTokens: 100_000);

    // gpt-5: $1.25/M input, $10/M output.
    expect(Pricing::cost($usage, 'openai', 'gpt-5'))->toEqualWithDelta(2.25, 0.0001);
});

it('bills cache and reasoning tokens at their configured rates', function () {
    $usage = new Usage(
        promptTokens: 1_000_000,
        completionTokens: 0,
        cacheReadInputTokens: 1_000_000,
        reasoningTokens: 100_000,
    );

    // input 1.25 + cache_read 0.125 + reasoning at output rate 1.00
    expect(Pricing::cost($usage, 'openai', 'gpt-5'))->toEqualWithDelta(2.375, 0.0001);
});

it('matches dated model ids to their family price by prefix', function () {
    $usage = new Usage(promptTokens: 1_000_000);

    expect(Pricing::cost($usage, 'openai', 'gpt-5-mini-2025-08-07'))
        ->toEqualWithDelta(0.25, 0.0001)
        // The prefix must be a whole segment: gpt-5 must not match gpt-52.
        ->and(Pricing::cost($usage, 'openai', 'gpt-52'))->toBeNull();
});

it('prefers the longest matching family over a shorter one', function () {
    config()->set('evals.pricing.openai.gpt-5', ['input' => 1.25, 'output' => 10.0]);
    config()->set('evals.pricing.openai.gpt-5-mini', ['input' => 0.25, 'output' => 2.0]);

    $usage = new Usage(promptTokens: 1_000_000);

    // gpt-5-mini-2025-08-07 starts with both "gpt-5-" and "gpt-5-mini-";
    // the longer family must win.
    expect(Pricing::cost($usage, 'openai', 'gpt-5-mini-2025-08-07'))->toEqualWithDelta(0.25, 0.0001);
});

it('returns null for unknown providers or models', function () {
    $usage = new Usage(promptTokens: 100);

    expect(Pricing::cost($usage, 'openai', 'unknown-model'))->toBeNull()
        ->and(Pricing::cost($usage, 'nobody', 'gpt-5'))->toBeNull()
        ->and(Pricing::cost($usage, null, null))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Layered resolution
|--------------------------------------------------------------------------
|
| Prices come from three places and the order matters. The bundled table in
| config/evals.php is a floor — six models, correct on the day it was written
| — and it must never beat a table synced from a source that tracks published
| prices daily. That precedence being backwards is exactly how a suite gets
| billed 50% more than the console said.
|
*/

it('prefers a synced price over the bundled one', function () {
    config()->set('evals.pricing.openai.gpt-5', ['input' => 99.0, 'output' => 99.0]);
    config()->set('evals-pricing.models', [
        'gpt-5' => ['input' => 1.25, 'output' => 10.0],
    ]);

    $usage = new Usage(promptTokens: 1_000_000);

    expect(Pricing::cost($usage, 'openai', 'gpt-5'))->toEqualWithDelta(1.25, 0.0001)
        ->and(Pricing::sourceFor('openai', 'gpt-5'))->toBe('synced');
});

it('lets an explicit override beat everything', function () {
    config()->set('evals.pricing.openai.gpt-5', ['input' => 1.25, 'output' => 10.0]);
    config()->set('evals-pricing.models', ['gpt-5' => ['input' => 1.25, 'output' => 10.0]]);
    config()->set('evals.pricing_overrides.openai.gpt-5', ['input' => 0.50, 'output' => 4.0]);

    $usage = new Usage(promptTokens: 1_000_000);

    expect(Pricing::cost($usage, 'openai', 'gpt-5'))->toEqualWithDelta(0.50, 0.0001)
        ->and(Pricing::sourceFor('openai', 'gpt-5'))->toBe('overrides');
});

it('falls back to the bundled table when nothing has been synced', function () {
    config()->set('evals-pricing.models', []);

    $usage = new Usage(promptTokens: 1_000_000);

    // A fresh install with no network still produces a number rather than a
    // shrug, which is the only reason the bundled table still exists.
    expect(Pricing::cost($usage, 'openai', 'gpt-5'))->toEqualWithDelta(1.25, 0.0001)
        ->and(Pricing::sourceFor('openai', 'gpt-5'))->toBe('bundled');
});

it('resolves a synced price without agreeing on the provider name', function () {
    // The SDK reports `gemini`; the pricing feed calls the same company
    // `google`. The synced table is keyed by model id alone so the two never
    // have to be reconciled.
    config()->set('evals-pricing.models', [
        'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.0],
    ]);

    $usage = new Usage(promptTokens: 1_000_000);

    expect(Pricing::cost($usage, 'gemini', 'gemini-2.5-pro'))->toEqualWithDelta(1.25, 0.0001)
        ->and(Pricing::cost($usage, 'google', 'gemini-2.5-pro'))->toEqualWithDelta(1.25, 0.0001);
});

it('matches a dated pin to its family inside the synced table', function () {
    config()->set('evals.pricing', []);
    config()->set('evals-pricing.models', [
        'gpt-4o' => ['input' => 2.50, 'output' => 10.0],
    ]);

    $usage = new Usage(promptTokens: 1_000_000);

    expect(Pricing::cost($usage, 'openai', 'gpt-4o-2024-08-06'))->toEqualWithDelta(2.50, 0.0001);
});

it('bills a cached token at the input rate when a provider has no cache tier', function () {
    config()->set('evals-pricing.models', [
        'some-model' => ['input' => 2.0, 'output' => 8.0],
    ]);

    $usage = new Usage(promptTokens: 0, completionTokens: 0, cacheReadInputTokens: 1_000_000);

    // Not free, and not zero: a provider that publishes no cache discount
    // charges the ordinary input rate.
    expect(Pricing::cost($usage, 'openai', 'some-model'))->toEqualWithDelta(2.0, 0.0001);
});
