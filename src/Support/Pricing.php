<?php

namespace Vizra\Evals\Support;

use Laravel\Ai\Responses\Data\Usage;

/**
 * Estimates USD cost from a Usage object against the user-maintained price
 * table in config('evals.pricing'). Unknown provider/model combinations
 * yield null (and a single console warning from the Runner), never an error.
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

    private static function pricesFor(?string $provider, ?string $model): ?array
    {
        if ($provider === null || $model === null) {
            return null;
        }

        $models = config("evals.pricing.{$provider}", []);

        if (isset($models[$model])) {
            return $models[$model];
        }

        // Providers often report dated model ids (e.g. gpt-5-mini-2025-08-07)
        // while the price table lists the family (gpt-5-mini). Fall back to
        // the longest configured key the reported model starts with.
        $bestKey = null;

        foreach (array_keys($models) as $key) {
            if (str_starts_with($model, $key.'-') && ($bestKey === null || strlen($key) > strlen($bestKey))) {
                $bestKey = $key;
            }
        }

        return $bestKey === null ? null : $models[$bestKey];
    }
}
