<?php

namespace Vizra\Evals\Dataset;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\LazyCollection;
use Vizra\Evals\Dataset\Readers\ConversationReader;
use Vizra\Evals\Dataset\Readers\CsvReader;
use Vizra\Evals\Dataset\Readers\JsonlReader;

class Dataset
{
    /** @param Closure(): iterable<int, Row> $source */
    protected function __construct(
        protected Closure $source,
        protected ?int $limit = null,
    ) {}

    /**
     * @param  iterable<int, Row|array|string>  $rows
     */
    public static function fromArray(iterable $rows): static
    {
        return new static(function () use ($rows) {
            $index = 0;

            foreach ($rows as $row) {
                yield $row instanceof Row
                    ? $row->withIndex($index++)
                    : Row::fromArray($row, $index++);
            }
        });
    }

    public static function fromCsv(string $path, string $inputColumn = 'prompt', string $expectedColumn = 'expected'): static
    {
        return new static(fn () => (new CsvReader($path, $inputColumn, $expectedColumn))->rows());
    }

    public static function fromJsonl(string $path): static
    {
        return new static(fn () => (new JsonlReader($path))->rows());
    }

    /**
     * @param  Closure(mixed, int): (Row|array|string)  $mapper
     */
    public static function fromEloquent(EloquentBuilder $query, Closure $mapper): static
    {
        return new static(function () use ($query, $mapper) {
            $index = 0;

            foreach ($query->cursor() as $model) {
                $row = $mapper($model, $index);

                yield $row instanceof Row ? $row->withIndex($index) : Row::fromArray($row, $index);

                $index++;
            }
        });
    }

    /**
     * Build multi-turn rows from the Laravel AI SDK's stored conversations
     * for the given agent class. Supports ->take(), ->latest(), ->where()
     * passthrough to the underlying conversation query.
     */
    public static function fromConversations(string $agentClass): ConversationDataset
    {
        return new ConversationDataset(new ConversationReader($agentClass));
    }

    public function take(int $limit): static
    {
        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    /**
     * @return LazyCollection<int, Row>
     */
    public function rows(): LazyCollection
    {
        $rows = LazyCollection::make($this->source);

        return $this->limit === null ? $rows : $rows->take($this->limit);
    }
}
