<?php

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Assertions\Content;
use Vizra\Evals\Assertions\Safety;
use Vizra\Evals\Assertions\Structure;
use Vizra\Evals\Assertions\ToolUse;
use Vizra\Evals\Assertions\UsageAndCost;
use Vizra\Evals\Dataset\Row;

function makeResponse(
    string $text = '',
    array $toolCalls = [],
    ?FinishReason $finishReason = null,
    ?Usage $usage = null,
    ?Meta $meta = null,
    int $steps = 1,
): AgentResponse {
    $usage ??= new Usage;
    $meta ??= new Meta('openai', 'gpt-5');

    $response = new AgentResponse('inv-1', $text, $usage, $meta);

    $calls = array_map(
        fn (array $call) => new ToolCall($call['id'] ?? 'call', $call['name'], $call['arguments'] ?? []),
        $toolCalls,
    );

    $response->withToolCallsAndResults(new Collection($calls), new Collection);

    if ($finishReason !== null) {
        $stepList = [];

        for ($i = 0; $i < $steps; $i++) {
            $stepList[] = new Step($text, $calls, [], $finishReason, $usage, $meta);
        }

        $response->withSteps(new Collection($stepList));
    }

    return $response;
}

function runAssertion(Assertion $assertion, AgentResponse $response, ?Row $row = null): AssertionResult
{
    return $assertion($response, $row ?? new Row('input'));
}

describe('content assertions', function () {
    it('checks pass and fail cases', function (Assertion $assertion, string $text, bool $passes) {
        expect(runAssertion($assertion, makeResponse($text))->passed())->toBe($passes);
    })->with([
        'contains hit' => [new Content\Contains('Refund'), 'full refund offered', true],
        'contains miss' => [new Content\Contains('refund'), 'no returns', false],
        'contains case-sensitive' => [new Content\Contains('Refund', ignoreCase: false), 'refund', false],
        'not contains' => [new Content\NotContains('sorry'), 'happy to help', true],
        'not contains hit' => [new Content\NotContains('sorry'), 'sorry about that', false],
        'any of' => [new Content\ContainsAnyOf(['yes', 'sure']), 'sure thing', true],
        'any of miss' => [new Content\ContainsAnyOf(['yes', 'sure']), 'nope', false],
        'all of' => [new Content\ContainsAllOf(['a', 'b']), 'a and b', true],
        'all of partial' => [new Content\ContainsAllOf(['a', 'zebra']), 'only a here', false],
        'starts with' => [new Content\StartsWith('Hello'), 'Hello there', true],
        'ends with' => [new Content\EndsWith('bye.'), 'ok bye.', true],
        'regex' => [new Content\MatchesRegex('/\d{4}/'), 'year 2026', true],
        'regex miss' => [new Content\MatchesRegex('/\d{4}/'), 'no digits', false],
        'length' => [new Content\LengthBetween(1, 10), 'short', true],
        'length outside' => [new Content\LengthBetween(1, 3), 'too long', false],
        'words' => [new Content\WordCountBetween(2, 3), 'two words', true],
        'not empty' => [new Content\NotEmpty, 'x', true],
        'empty' => [new Content\NotEmpty, '   ', false],
        'british ok' => [new Content\BritishSpelling, 'the colour of the centre', true],
        'british violated' => [new Content\BritishSpelling, 'the color of the center', false],
        'american ok' => [new Content\AmericanSpelling, 'the color of the center', true],
        'american violated' => [new Content\AmericanSpelling, 'the colour of the centre', false],
    ]);

    it('errors on an invalid regex instead of throwing', function () {
        expect(runAssertion(new Content\MatchesRegex('[broken'), makeResponse('x'))->status)
            ->toBe(AssertionResult::ERROR);
    });
});

describe('structure assertions', function () {
    it('validates json and xml', function (Assertion $assertion, string $text, bool $passes) {
        expect(runAssertion($assertion, makeResponse($text))->passed())->toBe($passes);
    })->with([
        'valid json' => [new Structure\ValidJson, '{"a": 1}', true],
        'invalid json' => [new Structure\ValidJson, '{nope', false],
        'json key' => [new Structure\JsonHasKey('a.b'), '{"a": {"b": 2}}', true],
        'json key missing' => [new Structure\JsonHasKey('c'), '{"a": 1}', false],
        'valid xml' => [new Structure\ValidXml, '<root><a/></root>', true],
        'invalid xml' => [new Structure\ValidXml, '<root><a></root>', false],
        'xml tag' => [new Structure\XmlHasTag('a'), '<root><a>1</a></root>', true],
        'xml tag missing' => [new Structure\XmlHasTag('b'), '<root><a/></root>', false],
    ]);

    it('asserts on structured output keys', function () {
        $structured = new StructuredAgentResponse('inv-1', ['score' => 4, 'tags' => ['a']], '{"score":4}', new Usage, new Meta);

        expect(runAssertion(new Structure\OutputHasKey('score'), $structured)->passed())->toBeTrue()
            ->and(runAssertion(new Structure\OutputHasKey('missing'), $structured)->passed())->toBeFalse()
            ->and(runAssertion(new Structure\OutputKey('score', 4), $structured)->passed())->toBeTrue()
            ->and(runAssertion(new Structure\OutputKey('score', fn ($v) => $v > 3), $structured)->passed())->toBeTrue()
            ->and(runAssertion(new Structure\OutputKey('score', fn ($v) => $v > 5), $structured)->passed())->toBeFalse();
    });

    it('fails output assertions against plain text responses', function () {
        expect(runAssertion(new Structure\OutputHasKey('score'), makeResponse('plain'))->passed())->toBeFalse();
    });
});

describe('tool use assertions', function () {
    it('inspects tool calls, steps, and finish reasons', function () {
        $response = makeResponse(
            'done',
            toolCalls: [
                ['name' => 'lookup_order', 'arguments' => ['id' => 7, 'full' => true]],
                ['name' => 'send_reply', 'arguments' => ['channel' => 'email']],
            ],
            finishReason: FinishReason::Stop,
            steps: 2,
        );

        expect(runAssertion(new ToolUse\ToolCalled('lookup_order'), $response)->passed())->toBeTrue()
            ->and(runAssertion(new ToolUse\ToolCalled('escalate'), $response)->passed())->toBeFalse()
            ->and(runAssertion(new ToolUse\ToolNotCalled('escalate'), $response)->passed())->toBeTrue()
            ->and(runAssertion(new ToolUse\ToolCalledWith('lookup_order', ['id' => 7]), $response)->passed())->toBeTrue()
            ->and(runAssertion(new ToolUse\ToolCalledWith('lookup_order', ['id' => 8]), $response)->passed())->toBeFalse()
            ->and(runAssertion(new ToolUse\ToolCallOrder(['lookup_order', 'send_reply']), $response)->passed())->toBeTrue()
            ->and(runAssertion(new ToolUse\ToolCallOrder(['send_reply', 'lookup_order']), $response)->passed())->toBeFalse()
            ->and(runAssertion(new ToolUse\StepsBelow(3), $response)->passed())->toBeTrue()
            ->and(runAssertion(new ToolUse\StepsBelow(2), $response)->passed())->toBeFalse()
            ->and(runAssertion(new ToolUse\FinishReasonIs(FinishReason::Stop), $response)->passed())->toBeTrue()
            ->and(runAssertion(new ToolUse\NoPendingApprovals, $response)->passed())->toBeTrue();
    });

    it('flags truncation in the finish reason failure message', function () {
        $response = makeResponse('cut off', finishReason: FinishReason::Length);

        $result = runAssertion(new ToolUse\FinishReasonIs(FinishReason::Stop), $response);

        expect($result->passed())->toBeFalse()
            ->and($result->message)->toContain('truncated');
    });
});

describe('usage and cost assertions', function () {
    it('computes cost from the configured price table', function () {
        $response = makeResponse('x', usage: new Usage(promptTokens: 1_000_000, completionTokens: 0));

        // gpt-5 input is $1.25/M in the shipped table.
        expect(runAssertion(new UsageAndCost\CostBelow(2.00), $response)->passed())->toBeTrue()
            ->and(runAssertion(new UsageAndCost\CostBelow(1.00), $response)->passed())->toBeFalse();
    });

    it('errors on unknown pricing instead of guessing', function () {
        $response = makeResponse('x', meta: new Meta('openai', 'gpt-unknown'));

        expect(runAssertion(new UsageAndCost\CostBelow(1.00), $response)->status)->toBe(AssertionResult::ERROR);
    });

    it('checks token totals, cache hit rate, duration, model, and provider', function () {
        $response = makeResponse('x', usage: new Usage(promptTokens: 50, completionTokens: 30, cacheReadInputTokens: 150));
        $row = (new Row('q'))->withMeta(['_duration_ms' => 900.0]);

        expect(runAssertion(new UsageAndCost\TokensBelow(300), $response)->passed())->toBeTrue()
            ->and(runAssertion(new UsageAndCost\TokensBelow(100), $response)->passed())->toBeFalse()
            ->and(runAssertion(new UsageAndCost\CacheHitRateAbove(0.5), $response)->passed())->toBeTrue()
            ->and(runAssertion(new UsageAndCost\CacheHitRateAbove(0.9), $response)->passed())->toBeFalse()
            ->and(runAssertion(new UsageAndCost\DurationBelow(1000), $response, $row)->passed())->toBeTrue()
            ->and(runAssertion(new UsageAndCost\DurationBelow(500), $response, $row)->passed())->toBeFalse()
            ->and(runAssertion(new UsageAndCost\ModelUsed('gpt-5'), $response)->passed())->toBeTrue()
            ->and(runAssertion(new UsageAndCost\ProviderUsed('openai'), $response)->passed())->toBeTrue()
            ->and(runAssertion(new UsageAndCost\ProviderUsed('anthropic'), $response)->passed())->toBeFalse();
    });
});

describe('safety assertions', function () {
    it('flags blocked words and obvious PII', function () {
        expect(runAssertion(new Safety\ContainsNoBlockedWords, makeResponse('happy to help'))->passed())->toBeTrue()
            ->and(runAssertion(new Safety\ContainsNoBlockedWords, makeResponse('I will kill the process'))->passed())->toBeFalse()
            ->and(runAssertion(new Safety\ContainsNoBlockedWords(['forbidden']), makeResponse('a forbidden word'))->passed())->toBeFalse()
            ->and(runAssertion(new Safety\NoObviousPII, makeResponse('contact support'))->passed())->toBeTrue()
            ->and(runAssertion(new Safety\NoObviousPII, makeResponse('mail me at a@b.com'))->passed())->toBeFalse()
            ->and(runAssertion(new Safety\NoObviousPII, makeResponse('ssn 123-45-6789'))->passed())->toBeFalse();
    });
});

describe('fluent tail', function () {
    it('gate() and weight() mutate the recorded result', function () {
        $result = AssertionResult::pass('x');

        expect($result->gate()->weight(2.5))->toBe($result)
            ->and($result->isGate)->toBeTrue()
            ->and($result->weight)->toBe(2.5);
    });
});
