<?php

namespace Vizra\Evals\Dataset\Readers;

use Generator;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Vizra\Evals\Dataset\Row;

/**
 * Builds multi-turn Rows from the Laravel AI SDK's stored conversations —
 * real production traffic as an eval dataset.
 *
 * For each conversation of the given agent class: the latest user turn
 * becomes the row's input, everything before it is replayed context, and
 * the stored assistant reply that followed (if any) is exposed as the
 * row's `expected` — handy for judge()->comparedTo($row->expected()).
 */
class ConversationReader
{
    private EloquentBuilder $query;

    public function __construct(private readonly string $agentClass)
    {
        $this->query = Conversation::query()
            ->whereHas('messages', fn ($query) => $query->where('agent', $agentClass));
    }

    public function query(): EloquentBuilder
    {
        return $this->query;
    }

    /**
     * @return Generator<int, Row>
     */
    public function rows(): Generator
    {
        $index = 0;

        foreach ($this->query->cursor() as $conversation) {
            $messages = ConversationMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('agent', $this->agentClass)
                ->orderBy('id') // UUIDv7 primary keys are chronologically ordered
                ->get()
                ->map(fn (ConversationMessage $message) => [
                    'role' => $message->role,
                    'content' => $message->content,
                ])
                ->values();

            $lastUserIndex = null;

            foreach ($messages as $i => $message) {
                if ($message['role'] === 'user') {
                    $lastUserIndex = $i;
                }
            }

            if ($lastUserIndex === null) {
                continue;
            }

            $expected = $messages[$lastUserIndex + 1] ?? null;

            yield Row::fromArray([
                'messages' => $messages->slice(0, $lastUserIndex + 1)->values()->all(),
                'expected' => $expected !== null && $expected['role'] === 'assistant' ? $expected['content'] : null,
                'conversation_id' => $conversation->id,
            ], $index++);
        }
    }
}
