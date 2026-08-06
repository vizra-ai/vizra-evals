<?php

namespace Vizra\Evals\Cloud;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;
use Vizra\Evals\Models\EvalRun;

/**
 * Pushes a finished run to Vizra Cloud.
 *
 * Reporting must never change the outcome of an eval. A run that passed its
 * gate has passed it whether or not the network was up, so every failure here
 * is returned as a message for the caller to print rather than thrown — a
 * flaky uplink must not turn a green build red.
 */
class Reporter
{
    public function __construct(private ?HttpFactory $http = null) {}

    public function configured(): bool
    {
        return $this->key() !== null && $this->endpoint() !== null;
    }

    /**
     * @return array{ok: bool, message: string, url: ?string}
     */
    public function report(EvalRun $run): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Vizra Cloud is not configured.', 'url' => null];
        }

        try {
            $response = ($this->http ?? Http::getFacadeRoot())
                ->withToken($this->key())
                ->timeout((int) config('evals.cloud.timeout', 15))
                // One retry, because the common failure is a dropped
                // connection rather than a rejected payload — and a 4xx is
                // never retried, since sending the same invalid document
                // again cannot start working.
                ->retry(2, 250, fn (Throwable $e) => ! $e instanceof RequestException, false)
                ->acceptJson()
                ->post($this->endpoint(), Payload::for($run, $this->sendsSamples()));
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Vizra Cloud: '.$e->getMessage(), 'url' => null];
        }

        if ($response->status() === 422) {
            $errors = $response->json('errors') ?? [];
            $first = is_array($errors) && $errors !== [] ? (array) reset($errors) : [];

            return [
                'ok' => false,
                'message' => 'Vizra Cloud rejected the run: '.($first[0] ?? $response->json('message') ?? 'unknown reason'),
                'url' => null,
            ];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'message' => 'Vizra Cloud rejected the API key.', 'url' => null];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => "Vizra Cloud returned HTTP {$response->status()}.", 'url' => null];
        }

        return [
            'ok' => true,
            'message' => $response->json('status') === 'already_recorded'
                ? 'Already reported to Vizra Cloud.'
                : 'Reported to Vizra Cloud.',
            'url' => $response->json('run.url'),
        ];
    }

    /**
     * Sample detail is opt-out, not opt-in: someone who has configured a cloud
     * key wants the drill-down, and a hosted dashboard that cannot show why a
     * row failed is worse than the local one they already had. Teams that
     * cannot let model output leave their network set this to false and keep
     * everything except the drill-down.
     */
    private function sendsSamples(): bool
    {
        return (bool) config('evals.cloud.samples', true);
    }

    private function key(): ?string
    {
        $key = config('evals.cloud.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function endpoint(): ?string
    {
        $endpoint = config('evals.cloud.endpoint');

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }
}
