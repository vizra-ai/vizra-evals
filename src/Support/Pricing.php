<?php

namespace Vizra\Evals\Support;

use Illuminate\Support\Carbon;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Estimates USD cost from a Usage object.
 *
 * Prices come from three places, in this order:
 *
 *  1. `config('evals.pricing_overrides')` — yours, and always final. For a
 *     negotiated rate, or a model nobody else prices.
 *  2. `config('evals-pricing.models')` — written by `evals:sync-pricing` from
 *     vizra.ai, which tracks published prices daily. Commit it and CI gets the
 *     same numbers you do, with no network call during a run.
 *  3. `config('evals.pricing')` — a small table shipped with the package, so a
 *     fresh install with no network still produces a number instead of a
 *     shrug. It ages, which is why it loses to anything above it.
 *
 * An unknown provider/model yields null (and one console warning from the
 * Runner), never an error. A cost estimate is not worth failing a run over.
 */
class Pricing
{
    /** @var array<string, bool> */
    private static array $warned = [];

    public static function cost(Usage $usage, ?string $provider, ?string $model): ?float
    {
        $prices = self::pricesFor($provider, $model);

        if ($prices === null) {
            return null;
        }

        $input = $prices['input'] ?? null;
        $output = $prices['output'] ?? null;

        if ($input === null || $output === null) {
            return null;
        }

        // A provider that publishes no cache tier bills cached tokens at the
        // ordinary input rate, so falling back to it is the correct estimate
        // rather than a guess.
        $cacheRead = $prices['cache_read'] ?? $input;
        $cacheWrite = $prices['cache_write'] ?? $input;

        // promptTokens excludes cached tokens on providers that report them
        // separately; reasoning tokens bill at the output rate.
        return ($usage->promptTokens * $input
            + ($usage->completionTokens + $usage->reasoningTokens) * $output
            + $usage->cacheReadInputTokens * $cacheRead
            + $usage->cacheWriteInputTokens * $cacheWrite) / 1_000_000;
    }

    /**
     * Whether this provider/model has already produced an unknown-pricing
     * warning during this process.
     */
    public static function shouldWarn(?string $provider, ?string $model): bool
    {
        if ($provider === null || $model === null) {
            return false;
        }

        $key = "{$provider}/{$model}";

        if (isset(self::$warned[$key]) || self::pricesFor($provider, $model) !== null) {
            return false;
        }

        self::$warned[$key] = true;

        return true;
    }

    /** How old a synced table may get before it is worth mentioning. */
    private const STALE_AFTER_DAYS = 60;

    private static bool $staleReported = false;

    /**
     * One line, once per process, when the synced table has gone stale.
     *
     * A price table nobody re-syncs is the failure mode this whole mechanism
     * has — it does not break, it just quietly keeps answering with last
     * year's numbers, and a confident wrong cost is worse than a null one.
     * Silent while a table is current, and silent when there is none at all,
     * because that case already warns per model.
     */
    public static function staleWarning(): ?string
    {
        if (self::$staleReported) {
            return null;
        }

        $published = config('evals-pricing.published_at');

        if (! is_string($published) || $published === '' || $published === 'unknown') {
            return null;
        }

        try {
            $age = (int) now()->diffInDays(Carbon::parse($published), absolute: true);
        } catch (\Throwable) {
            return null;
        }

        if ($age < self::STALE_AFTER_DAYS) {
            return null;
        }

        self::$staleReported = true;

        return "Model prices are {$age} days old — run `php artisan evals:sync-pricing` to refresh them.";
    }

    /**
     * Where a given model's prices came from: `overrides`, `synced`, `bundled`
     * or null. Reported by `evals:sync-pricing` so the answer to "which number
     * is it using?" does not require reading this class.
     */
    public static function sourceFor(?string $provider, ?string $model): ?string
    {
        if ($model === null) {
            return null;
        }

        foreach (['overrides', 'synced', 'bundled'] as $layer) {
            if (self::lookup($layer, $provider, $model) !== null) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * @return array<string, float>|null
     */
    private static function pricesFor(?string $provider, ?string $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return self::lookup('overrides', $provider, $model)
            ?? self::lookup('synced', $provider, $model)
            ?? self::lookup('bundled', $provider, $model);
    }

    /**
     * @return array<string, float>|null
     */
    private static function lookup(string $layer, ?string $provider, string $model): ?array
    {
        $models = match ($layer) {
            // The synced table is flat, keyed by the model id itself. The SDK
            // and the pricing feed do not always agree on a provider's name —
            // one says `gemini` where the other says `google` — and a model id
            // is unambiguous on its own, so provider is not part of the key.
            'synced' => config('evals-pricing.models', []),

            // These two are nested per provider, which is how someone writing
            // prices by hand wants to read them.
            'overrides' => $provider === null ? [] : config("evals.pricing_overrides.{$provider}", []),
            'bundled' => $provider === null ? [] : config("evals.pricing.{$provider}", []),
        };

        if (! is_array($models) || $models === []) {
            return null;
        }

        if (isset($models[$model]) && is_array($models[$model])) {
            return $models[$model];
        }

        /*
         * Providers report dated model ids — `gpt-4o-2024-08-06`,
         * `claude-haiku-4-5-20251001` — while a price table lists the family.
         * Fall back to the longest configured key the reported model starts
         * with, so a pin resolves to its family rather than to nothing.
         */
        $best = null;

        foreach (array_keys($models) as $key) {
            if (str_starts_with($model, $key.'-') && ($best === null || strlen($key) > strlen($best))) {
                $best = $key;
            }
        }

        return $best === null || ! is_array($models[$best]) ? null : $models[$best];
    }
}
