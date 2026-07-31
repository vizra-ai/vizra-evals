<?php

use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Exceptions\DatasetException;

function dataset_fixture(string $name): string
{
    return __DIR__.'/../Fixtures/datasets/'.$name;
}

describe('Row', function () {
    it('normalizes a bare string into a single-turn row', function () {
        $row = Row::fromArray('Hello there');

        expect($row->input)->toBe('Hello there')
            ->and($row->isMultiTurn())->toBeFalse()
            ->and($row->hash)->toHaveLength(64);
    });

    it('hoists the trailing user turn of a messages-only row into input', function () {
        $row = Row::fromArray([
            'messages' => [
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello!'],
                ['role' => 'user', 'content' => 'Where is my order?'],
            ],
        ]);

        expect($row->input)->toBe('Where is my order?')
            ->and($row->messages)->toHaveCount(2)
            ->and($row->isMultiTurn())->toBeTrue();
    });

    it('rejects a messages row whose final turn is not from the user', function () {
        Row::fromArray(['messages' => [['role' => 'assistant', 'content' => 'Hi']]]);
    })->throws(DatasetException::class, 'final message');

    it('collects unrecognised keys into meta and exposes expected', function () {
        $row = Row::fromArray([
            'input' => 'Q',
            'expected' => ['contains' => '30 days'],
            'category' => 'billing',
        ]);

        expect($row->meta('category'))->toBe('billing')
            ->and($row->expected('contains'))->toBe('30 days')
            ->and($row->expected())->toBe(['contains' => '30 days']);
    });

    it('keeps a stable hash across meta changes and transforms, but not content changes', function () {
        $a = Row::fromArray(['input' => 'Q', 'expected' => 'A', 'category' => 'x']);
        $b = Row::fromArray(['input' => 'Q', 'expected' => 'A', 'category' => 'y']);
        $c = Row::fromArray(['input' => 'Q', 'expected' => 'B']);

        expect($a->hash)->toBe($b->hash)
            ->and($a->hash)->not->toBe($c->hash)
            ->and($a->withInput('rewritten prompt')->hash)->toBe($a->hash);
    });
});

describe('Dataset::fromArray', function () {
    it('accepts strings, arrays, and Row objects and assigns indexes', function () {
        $rows = Dataset::fromArray([
            'plain prompt',
            ['input' => 'array prompt', 'expected' => 'x'],
            new Row('row object'),
        ])->rows()->all();

        expect($rows)->toHaveCount(3)
            ->and($rows[0]->index)->toBe(0)
            ->and($rows[2]->index)->toBe(2)
            ->and($rows[2]->input)->toBe('row object');
    });

    it('supports take()', function () {
        expect(Dataset::fromArray(['a', 'b', 'c'])->take(2)->rows()->all())->toHaveCount(2);
    });
});

describe('Dataset::fromJsonl', function () {
    it('parses single-turn, multi-turn, and meta rows, skipping blank lines', function () {
        $rows = Dataset::fromJsonl(dataset_fixture('support.jsonl'))->rows()->all();

        expect($rows)->toHaveCount(3)
            ->and($rows[0]->input)->toBe('What is your refund policy?')
            ->and($rows[0]->expected('contains'))->toBe('30 days')
            ->and($rows[0]->meta('category'))->toBe('billing')
            ->and($rows[1]->isMultiTurn())->toBeTrue()
            ->and($rows[1]->input)->toBe('Where is it?')
            ->and($rows[1]->messages)->toHaveCount(2)
            ->and($rows[2]->expected())->toBeNull();
    });

    it('reports the line number of a malformed line', function () {
        Dataset::fromJsonl(dataset_fixture('malformed.jsonl'))->rows()->all();
    })->throws(DatasetException::class, 'line 2');

    it('streams lazily', function () {
        $rows = Dataset::fromJsonl(dataset_fixture('malformed.jsonl'))->rows();

        // Taking only the first (valid) row must not reach the malformed line.
        expect($rows->first()->input)->toBe('fine');
    });

    it('throws for a missing file', function () {
        Dataset::fromJsonl('/nope/missing.jsonl')->rows()->all();
    })->throws(DatasetException::class, 'not found');
});

describe('Dataset::fromCsv', function () {
    it('reads header-based rows with prompt and expected columns', function () {
        $rows = Dataset::fromCsv(dataset_fixture('support.csv'))->rows()->all();

        expect($rows)->toHaveCount(2)
            ->and($rows[0]->input)->toBe('What is your refund policy?')
            ->and($rows[0]->expected())->toBe('30 days')
            ->and($rows[0]->meta('category'))->toBe('billing')
            ->and($rows[1]->expected())->toBeNull()
            ->and($rows[1]->meta('category'))->toBe('shipping');
    });

    it('throws when the input column is missing', function () {
        Dataset::fromCsv(dataset_fixture('support.csv'), inputColumn: 'question')->rows()->all();
    })->throws(DatasetException::class, 'question');
});
