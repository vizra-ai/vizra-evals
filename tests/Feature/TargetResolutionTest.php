<?php

use Laravel\Ai\Ai;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Exceptions\InvalidTargetException;
use Vizra\Evals\Support\Combo;
use Vizra\Evals\Support\ConversationalDecorator;
use Vizra\Evals\Target;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;

it('invokes a class-string target directly for single-turn rows', function () {
    Ai::fakeAgent(SupportAgent::class, ['Hello from the agent.']);

    $response = Target::from(SupportAgent::class)->invoke(new Row('Hi'));

    expect($response)->toBeInstanceOf(AgentResponse::class)
        ->and($response->text)->toBe('Hello from the agent.');

    SupportAgent::assertPrompted('Hi');
});

it('invokes an agent instance target, cloning per invocation', function () {
    Ai::fakeAgent(SupportAgent::class, ['one', 'two']);

    $target = Target::from(new SupportAgent);

    expect($target->invoke(new Row('a'))->text)->toBe('one')
        ->and($target->invoke(new Row('b'))->text)->toBe('two')
        ->and($target->agentClass())->toBe(SupportAgent::class);
});

it('invokes a closure target with the row and combo', function () {
    $seen = [];

    $target = Target::from(function (Row $row, Combo $combo) use (&$seen): AgentResponse {
        $seen = [$row->input, $combo->key()];

        return new AgentResponse('inv-1', 'closure response', new Usage, new Meta);
    });

    $response = $target->invoke(new Row('from closure'), new Combo('openai', 'gpt-5'));

    expect($response->text)->toBe('closure response')
        ->and($seen)->toBe(['from closure', 'openai/gpt-5'])
        ->and($target->agentClass())->toBeNull();
});

it('rejects anything else', function () {
    Target::from(42);
})->throws(InvalidTargetException::class);

it('wraps multi-turn rows in a decorator that replays hydrated messages', function () {
    Ai::fakeAgent(ConversationalDecorator::class, ['Your order shipped yesterday.']);

    $row = Row::fromArray([
        'messages' => [
            ['role' => 'user', 'content' => 'Hi, I ordered a lamp'],
            ['role' => 'assistant', 'content' => 'How can I help with your order?'],
            ['role' => 'user', 'content' => 'Where is it?'],
        ],
    ]);

    $response = Target::from(SupportAgent::class)->invoke($row);

    expect($response->text)->toBe('Your order shipped yesterday.');

    ConversationalDecorator::assertPrompted(function (AgentPrompt $prompt) {
        $agent = $prompt->agent;

        if (! $agent instanceof ConversationalDecorator || $agent->inner::class !== SupportAgent::class) {
            return false;
        }

        $messages = collect($agent->messages());

        return $prompt->prompt === 'Where is it?'
            && $messages->count() === 2
            && $messages->every(fn ($message) => $message instanceof Message)
            && $messages->first()->content === 'Hi, I ordered a lamp'
            && (string) $agent->instructions() === (new SupportAgent)->instructions();
    });
});

it('routes multi-turn rows straight to a faked agent instead of the decorator', function () {
    // Only the agent is faked — before the bypass, the decorator would miss
    // this fake and hit the real provider.
    Ai::fakeAgent(SupportAgent::class, ['Your order shipped yesterday.']);

    $row = Row::fromArray([
        'messages' => [
            ['role' => 'user', 'content' => 'Hi, I ordered a lamp'],
            ['role' => 'assistant', 'content' => 'How can I help?'],
            ['role' => 'user', 'content' => 'Where is it?'],
        ],
    ]);

    $response = Target::from(SupportAgent::class)->invoke($row);

    expect($response->text)->toBe('Your order shipped yesterday.');

    // Prompts are recorded under the agent's own class, so the natural
    // test-side assertion works for multi-turn rows too.
    SupportAgent::assertPrompted('Where is it?');
});

it('passes across() combo provider and model through to the prompt', function () {
    Ai::fakeAgent(SupportAgent::class, function ($prompt, $attachments, $provider, $model) {
        return "provider={$provider} model={$model}";
    });

    $response = Target::from(SupportAgent::class)->invoke(new Row('Hi'), new Combo('openai', 'gpt-5'));

    expect($response->text)->toContain('model=gpt-5');
});
