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
