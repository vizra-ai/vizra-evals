<?php

namespace Vizra\Evals\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Writes a current model price table into the application's config directory.
 *
 * Cost estimates were previously only as good as a six-model table copied into
 * `config/evals.php` at install time and never looked at again — which is a
 * fine way to be told a suite costs 50% more than it does, and no way at all
 * to price a model released last month.
 *
 * This fetches published prices from vizra.ai, which tracks them daily, and
 * writes them to `config/evals-pricing.php`. Three things follow from writing
 * a file rather than calling the API at run time:
 *
 *  - Runs stay offline. Nothing reaches the network while an eval executes,
 *    so CI behind a firewall behaves the same as a laptop.
 *  - Prices are reviewable. Commit the file and a change to what your evals
 *    cost arrives as a diff in a pull request, like any other change.
 *  - Prices are reproducible. Re-running last month's suite uses last month's
 *    numbers, because they are in the commit.
 *
 * The endpoint is public and anonymous. No key, no run data, nothing about
 * this application leaves it.
 */
class SyncPricingCommand extends Command
{
    protected $signature = 'evals:sync-pricing
        {--all : Include every alias the source publishes, not just canonical model ids}
        {--dry-run : Show what would change without writing the file}';

    protected $description = 'Fetch current model prices from vizra.ai into config/evals-pricing.php';

    public function handle(): int
    {
        $url = rtrim((string) config('evals.pricing_source', 'https://vizra.ai/api/v1/pricing/ai-models'), '/');
        $all = (bool) $this->option('all');

        $this->line("Fetching prices from <options=bold>{$url}</>...");

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->get($url, $all ? [] : ['canonical' => 1]);
        } catch (\Throwable $e) {
            $this->components->error('Could not reach the pricing source: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->components->error("Pricing source returned HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $models = $response->json('data.models');

        if (! is_array($models) || $models === []) {
            // Overwriting a working table with an empty one would silently
            // turn every cost into "unknown" on the next run.
            $this->components->error('Pricing source returned no models — leaving the existing file alone.');

            return self::FAILURE;
        }

        $table = $this->priceTable($models);
        $path = config_path('evals-pricing.php');
        $previous = $this->existing($path);

        $this->report($previous, $table);

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — '.count($table).' models fetched, nothing written.');

            return self::SUCCESS;
        }

        file_put_contents($path, $this->render($table, $url, (string) $response->json('meta.last_updated')));

        $this->components->info(sprintf(
            '%d models written to config/evals-pricing.php. Commit it so CI prices runs the same way.',
            count($table),
        ));

        /*
         * A cached config is read from bootstrap/cache, not from the file we
         * just wrote — which is every production deployment. Without this the
         * command reports success, changes the file, and changes nothing that
         * runs: the single most confusing way for this to fail.
         *
         * Rebuilt rather than warned about, because finishing the job is what
         * was asked for, and the cache is regenerated from the same
         * environment this process is already running in.
         */
        if ($this->laravel->configurationIsCached()) {
            $this->callSilently('config:cache');
            $this->components->info('Config cache rebuilt, so the new prices are live.');
        }

        return self::SUCCESS;
    }

    /**
     * The wire shape, reduced to what a cost calculation needs.
     *
     * `chars_per_token` is dropped: it exists to turn a word count into a
     * token estimate on a marketing page, and every number here comes from a
     * Usage object that reports real token counts.
     *
     * @param  array<string, mixed>  $models
     * @return array<string, array<string, float|string>>
     */
    private function priceTable(array $models): array
    {
        $table = [];

        foreach ($models as $id => $prices) {
            if (! is_array($prices) || ! isset($prices['input_price_per_million'], $prices['output_price_per_million'])) {
                continue;
            }

            $entry = [
                'input' => (float) $prices['input_price_per_million'],
                'output' => (float) $prices['output_price_per_million'],
            ];

            // Omitted rather than nulled where a provider has no cache tier.
            // Pricing falls back to the input rate, which is what those
            // providers actually charge for a cached token.
            foreach (['cache_read' => 'cache_read_price_per_million', 'cache_write' => 'cache_write_price_per_million'] as $key => $field) {
                if (isset($prices[$field]) && is_numeric($prices[$field])) {
                    $entry[$key] = (float) $prices[$field];
                }
            }

            if (isset($prices['provider']) && is_string($prices['provider'])) {
                $entry['provider'] = $prices['provider'];
            }

            $table[(string) $id] = $entry;
        }

        ksort($table);

        return $table;
    }

    /**
     * @return array<string, array<string, float|string>>
     */
    private function existing(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $loaded = require $path;

        return is_array($loaded) && is_array($loaded['models'] ?? null) ? $loaded['models'] : [];
    }

    /**
     * Say what moved. A price table that changes silently is the problem this
     * command exists to solve, so it does not change silently either.
     *
     * @param  array<string, array<string, float|string>>  $before
     * @param  array<string, array<string, float|string>>  $after
     */
    private function report(array $before, array $after): void
    {
        if ($before === []) {
            $this->components->info(count($after).' models fetched.');

            return;
        }

        $added = array_diff_key($after, $before);
        $removed = array_diff_key($before, $after);
        $changed = [];

        foreach (array_intersect_key($after, $before) as $id => $entry) {
            foreach (['input', 'output'] as $field) {
                if (($before[$id][$field] ?? null) !== ($entry[$field] ?? null)) {
                    $changed[$id] = sprintf(
                        '%s  in %s → %s, out %s → %s',
                        $id,
                        $before[$id]['input'] ?? '—', $entry['input'],
                        $before[$id]['output'] ?? '—', $entry['output'],
                    );
                    break;
                }
            }
        }

        foreach ($changed as $line) {
            $this->line('  <fg=yellow>~</> '.$line);
        }

        foreach (array_keys($added) as $id) {
            $this->line("  <fg=green>+</> {$id}");
        }

        foreach (array_keys($removed) as $id) {
            $this->line("  <fg=red>-</> {$id} (no longer published)");
        }

        if ($changed === [] && $added === [] && $removed === []) {
            $this->line('  <fg=gray>No changes.</>');
        }
    }

    /**
     * @param  array<string, array<string, float|string>>  $table
     */
    private function render(array $table, string $source, string $lastUpdated): string
    {
        $rows = '';

        foreach ($table as $id => $entry) {
            $provider = $entry['provider'] ?? null;
            unset($entry['provider']);

            $pairs = [];

            foreach ($entry as $key => $value) {
                $pairs[] = sprintf("'%s' => %s", $key, $this->number((float) $value));
            }

            $rows .= sprintf(
                "        %-46s => [%s],%s\n",
                var_export((string) $id, true),
                implode(', ', $pairs),
                $provider ? " // {$provider}" : '',
            );
        }

        $stamp = $lastUpdated !== '' ? $lastUpdated : 'unknown';

        return <<<PHP
        <?php

        /*
        |--------------------------------------------------------------------------
        | Model prices, USD per 1 million tokens
        |--------------------------------------------------------------------------
        |
        | Generated by `php artisan evals:sync-pricing`. Do not edit by hand —
        | the next sync overwrites it. To pin a price, put it in the
        | `pricing_overrides` key of config/evals.php instead, which wins over
        | everything here.
        |
        | Commit this file. It is what makes a cost estimate reproducible: CI
        | prices a run with the same numbers your laptop did, and a change to
        | what your evals cost shows up as a reviewable diff.
        |
        | Source:       {$source}
        | Published:    {$stamp}
        |
        */

        return [
            'source' => '{$source}',
            'published_at' => '{$stamp}',

            'models' => [
        {$rows}    ],
        ];

        PHP;
    }

    /**
     * Prices span six orders of magnitude, and PHP's default float rendering
     * turns the small end into scientific notation — `2.5E-5` in a config
     * file people are meant to read in a diff.
     */
    private function number(float $value): string
    {
        $formatted = rtrim(number_format($value, 6, '.', ''), '0');

        // Always leave a decimal place behind. Trimming $2.000000 all the way
        // to `2` makes PHP load that entry as an int while its neighbours are
        // floats, so a table of prices ends up mixed-type for no reason.
        return str_ends_with($formatted, '.') ? $formatted.'0' : $formatted;
    }
}
