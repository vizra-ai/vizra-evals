<?php

namespace Vizra\Evals\Dataset;

use Vizra\Evals\Dataset\Readers\ConversationReader;

/**
 * Dataset over the SDK's stored conversations, with query passthrough:
 * ->take(n), ->latest(), and ->where(...) refine which conversations are
 * sampled before rows are built.
 */
class ConversationDataset extends Dataset
{
    public function __construct(private readonly ConversationReader $reader)
    {
        parent::__construct(fn () => $this->reader->rows());
    }

    public function latest(): static
    {
        $this->reader->query()->reorder()->latest('updated_at');

        return $this;
    }

    public function where(...$arguments): static
    {
        $this->reader->query()->where(...$arguments);

        return $this;
    }

    public function take(int $limit): static
    {
        $this->reader->query()->limit($limit);

        return $this;
    }
}
