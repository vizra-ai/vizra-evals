<?php

use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;

beforeEach(function () {
    $migration = require __DIR__.'/../../vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php';
    $migration->up();
});

function seedConversation(string $id, string $agent, array $turns, string $updatedAt = '2026-07-01 00:00:00'): void
{
    Conversation::create([
        'id' => $id,
        'title' => 'Conversation '.$id,
        'created_at' => $updatedAt,
        'updated_at' => $updatedAt,
    ]);

    foreach ($turns as $i => [$role, $content]) {
        ConversationMessage::create([
            'id' => $id.'-msg-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'conversation_id' => $id,
            'agent' => $agent,
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ]);
    }
}

it('builds multi-turn rows from stored conversations, using the stored reply as expected', function () {
    seedConversation('conv-a', SupportAgent::class, [
        ['user', 'Hi, I ordered a lamp'],
        ['assistant', 'How can I help with your order?'],
        ['user', 'Where is it?'],
        ['assistant', 'It shipped yesterday.'],
    ]);

    seedConversation('conv-b', 'App\\Agents\\OtherAgent', [
        ['user', 'Unrelated'],
        ['assistant', 'Nope.'],
    ]);

    $rows = Dataset::fromConversations(SupportAgent::class)->rows()->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->input)->toBe('Where is it?')
        ->and($rows[0]->messages)->toHaveCount(2)
        ->and($rows[0]->messages[1]['role'])->toBe('assistant')
        ->and($rows[0]->expected())->toBe('It shipped yesterday.')
        ->and($rows[0]->meta('conversation_id'))->toBe('conv-a');
});

it('supports take, latest, and where passthrough', function () {
    seedConversation('conv-1', SupportAgent::class, [['user', 'first']], '2026-07-01 00:00:00');
    seedConversation('conv-2', SupportAgent::class, [['user', 'second']], '2026-07-02 00:00:00');
    seedConversation('conv-3', SupportAgent::class, [['user', 'third']], '2026-07-03 00:00:00');

    $rows = Dataset::fromConversations(SupportAgent::class)->latest()->take(2)->rows()->all();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->input)->toBe('third')
        ->and($rows[1]->input)->toBe('second');

    $rows = Dataset::fromConversations(SupportAgent::class)->where('title', 'Conversation conv-1')->rows()->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->input)->toBe('first');
});

it('skips conversations with no user turn and drops trailing assistant context correctly', function () {
    seedConversation('conv-x', SupportAgent::class, [
        ['assistant', 'Proactive greeting with no user reply'],
    ]);

    expect(Dataset::fromConversations(SupportAgent::class)->rows()->all())->toBeEmpty();
});
