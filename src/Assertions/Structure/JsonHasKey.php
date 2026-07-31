<?php

namespace Vizra\Evals\Assertions\Structure;

use Illuminate\Support\Arr;
use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

class JsonHasKey implements Assertion
{
    public function __construct(private readonly string $key) {}

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $decoded = json_decode($response->text, true);

        if (! is_array($decoded)) {
            return AssertionResult::fail('json_has_key', $this->key, $response->text, 'Response is not a JSON object.');
        }

        return AssertionResult::bool(
            'json_has_key',
            Arr::has($decoded, $this->key),
            $this->key,
            $response->text,
            "JSON response has no \"{$this->key}\" key.",
        );
    }
}
