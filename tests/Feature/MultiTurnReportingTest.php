<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Models\EvalRowResult;
use Vizra\Evals\Tests\Fixtures\Agents\SupportAgent;
use Vizra\Evals\Tests\Fixtures\Evals\SupportQuality;

/**
 * A multi-turn row's earlier turns have to survive the trip.
 *
 * `input` merges the whole exchange into one JSON string, which reads well on
 * a run page and is useless for identity: a row is hashed over input, messages
 * and expected as three separate values. Anything holding only the merged form
 * cannot reconstruct the row, which is why every multi-turn row used to arrive
 * in Vizra Cloud uneditable, unexportable, and rendered as a raw blob.
 */
beforeEach(function () {
    config([
        'evals.cloud.endpoint' => 'https://vizra.test/api/v1/runs',
        'evals.cloud.key' => 'vz_test_key',
    ]);

    Ai::fakeAgent(SupportAgent::class, fn () => 'Within 30 days of delivery.');
});

function multiTurnDataset(): array
{
    return [[
        'messages' => [
            ['role' => 'user', 'content' => 'Hi, I bought a lamp last week'],
            ['role' => 'assistant', 'content' => 'Thanks for reaching out! How can I help?'],
        ],
        'input' => 'Can I still return it?',
        'expected' => '30 days',
    ]];
}

it('persists the earlier turns apart from the merged input', function () {
    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--no-report' => true,
        '--dataset' => datasetFile(multiTurnDataset()),
    ])->assertExitCode(0);

    $sample = EvalRowResult::sole();

    // Merged, for reading.
    expect(json_decode($sample->input, true))->toHaveCount(3)
        // Split, for identity.
        ->and($sample->messages)->toHaveCount(2)
        ->and($sample->messages[0]['content'])->toBe('Hi, I bought a lamp last week');
});

it('sends the earlier turns to the cloud', function () {
    Http::fake(['vizra.test/*' => Http::response(['status' => 'recorded', 'run' => []], 201)]);

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--dataset' => datasetFile(multiTurnDataset()),
    ])->assertExitCode(0);

    Http::assertSent(fn ($request) => count($request['samples'][0]['messages']) === 2
        && $request['samples'][0]['messages'][1]['role'] === 'assistant');
});

/**
 * The whole point of sending them: what arrives has to rebuild the row's
 * identity exactly, or the far end still cannot let anyone edit it.
 */
it('sends enough to recompute the row hash at the far end', function () {
    Http::fake(['vizra.test/*' => Http::response(['status' => 'recorded', 'run' => []], 201)]);

    $this->artisan('evals:run', [
        'suite' => SupportQuality::class,
        '--concurrency' => 1,
        '--dataset' => datasetFile(multiTurnDataset()),
    ])->assertExitCode(0);

    Http::assertSent(function ($request) {
        $sample = $request['samples'][0];

        // Exactly what Vizra Cloud's DeriveDataset does with the payload.
        $turns = json_decode($sample['input'], true);
        $prompt = end($turns)['content'];

        return Row::computeHash($prompt, $sample['messages'], $sample['expected'])
            === $sample['row_hash'];
    });
});

function datasetFile(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'vizra-multiturn-').'.jsonl';

    file_put_contents($path, implode("\n", array_map(
        fn (array $row) => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $rows,
    ))."\n");

    return $path;
}
