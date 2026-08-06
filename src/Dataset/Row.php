<?php

namespace Vizra\Evals\Dataset;

use Illuminate\Support\Arr;
use Vizra\Evals\Exceptions\DatasetException;

/**
 * A single dataset row.
 *
 * `input` is the (final) user turn sent to the agent. `messages` holds prior
 * conversation turns as [['role' => 'user'|'assistant', 'content' => '...']].
 * The hash identifies the row across runs and file reorderings; it covers
 * input, messages, and expected — not meta — and is computed on the raw row
 * before any transform() hook runs.
 */
final class Row
{
    public readonly string $hash;

    public function __construct(
        public readonly string $input,
        public readonly array $messages = [],
        public readonly mixed $expected = null,
        public readonly array $meta = [],
        public readonly int $index = 0,
        ?string $hash = null,
    ) {
        $this->hash = $hash ?? self::computeHash($input, $messages, $expected);
    }

    /**
     * Normalize an array (or bare string) into a Row.
     *
     * Recognised keys: `input` (string) and/or `messages` (list of
     * {role, content}); `expected` (free-form). Everything else lands in meta.
     * When only `messages` is given, the trailing user turn is hoisted into
     * `input` and the remainder become prior context.
     *
     * An explicit `hash` is honoured rather than recomputed, so a dataset
     * that already knows its row identities keeps them.
     */
    public static function fromArray(array|string $data, int $index = 0): self
    {
        if (is_string($data)) {
            return new self(input: $data, index: $index);
        }

        $input = $data['input'] ?? null;
        $messages = $data['messages'] ?? [];
        $expected = $data['expected'] ?? null;
        // `hash` is identity, not data. A dataset that carries one has
        // already decided which logical rows these are — a variant assembled
        // elsewhere, say — and recomputing would silently rename any row we
        // cannot reproduce byte-for-byte from content alone.
        $hash = $data['hash'] ?? null;
        $meta = Arr::except($data, ['input', 'messages', 'expected', 'hash']);

        if ($hash !== null && (! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/', $hash))) {
            throw new DatasetException('Row "hash" must be a sha256 hex digest.');
        }

        if (! is_array($messages)) {
            throw new DatasetException('Row "messages" must be an array of {role, content} objects.');
        }

        foreach ($messages as $message) {
            if (! is_array($message) || ! isset($message['role'], $message['content'])) {
                throw new DatasetException('Each message must have "role" and "content" keys.');
            }
        }

        if ($input === null) {
            if ($messages === []) {
                throw new DatasetException('Row must define "input" or a non-empty "messages" array.');
            }

            $last = end($messages);

            if (($last['role'] ?? null) !== 'user') {
                throw new DatasetException('The final message of a row must have role "user" — it becomes the prompt.');
            }

            $input = $last['content'];
            $messages = array_slice($messages, 0, -1);
        }

        if (! is_string($input)) {
            throw new DatasetException('Row "input" must be a string.');
        }

        return new self($input, array_values($messages), $expected, $meta, $index, $hash);
    }

    public function isMultiTurn(): bool
    {
        return $this->messages !== [];
    }

    public function expected(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->expected;
        }

        return is_array($this->expected) ? Arr::get($this->expected, $key, $default) : $default;
    }

    public function meta(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->meta;
        }

        return Arr::get($this->meta, $key, $default);
    }

    /**
     * Copy with a changed input, keeping the original identity hash — use
     * this inside transform() so the row still lines up across runs.
     */
    public function withInput(string $input): self
    {
        return new self($input, $this->messages, $this->expected, $this->meta, $this->index, $this->hash);
    }

    public function withMeta(array $meta): self
    {
        return new self($this->input, $this->messages, $this->expected, array_merge($this->meta, $meta), $this->index, $this->hash);
    }

    public function withIndex(int $index): self
    {
        return new self($this->input, $this->messages, $this->expected, $this->meta, $index, $this->hash);
    }

    public function withHash(string $hash): self
    {
        return new self($this->input, $this->messages, $this->expected, $this->meta, $this->index, $hash);
    }

    public static function computeHash(string $input, array $messages, mixed $expected): string
    {
        return hash('sha256', json_encode(
            [$input, $messages, $expected],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }
}
