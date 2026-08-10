<?php

/**
 * AGENTS.md is a contract with a machine, so it has to be true.
 *
 * A human reading a stale doc notices, shrugs, and looks at the source. A
 * coding agent reading a stale doc writes `assertHelpful()` with total
 * confidence, and the person who asked for the eval gets a fatal error they
 * did not cause and cannot place.
 *
 * The failure mode that actually matters is the opposite one: an assertion
 * added to the package and never documented. The agent cannot use what it has
 * not been told about, so the feature may as well not exist.
 */
function documentedAssertions(): array
{
    $doc = file_get_contents(__DIR__.'/../../AGENTS.md');

    $start = strpos($doc, '<!-- assertions:start -->');
    $end = strpos($doc, '<!-- assertions:end -->');

    expect($start)->not->toBeFalse('AGENTS.md has lost its assertion markers');
    expect($end)->not->toBeFalse('AGENTS.md has lost its assertion markers');

    preg_match_all('/^- `(assert\w+)`$/m', substr($doc, $start, $end - $start), $m);

    return $m[1];
}

function realAssertions(): array
{
    $found = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../src'),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all(
            // `protected`, because an Evaluation calls them on itself. Matching only
        // `public` found nothing at all — and the first test still passed,
        // because an empty list is a subset of anything.
        '/(?:public|protected)\s+function\s+(assert[A-Z]\w*)\s*\(/',
            file_get_contents($file->getPathname()),
            $m,
        );

        $found = array_merge($found, $m[1]);
    }

    return array_values(array_unique($found));
}

it('documents every assertion the package actually has', function () {
    $missing = array_diff(realAssertions(), documentedAssertions());

    expect($missing)->toBeEmpty(
        'Assertions exist but are undocumented, so no agent will use them: '
        .implode(', ', $missing),
    );
});

it('documents no assertion the package does not have', function () {
    $invented = array_diff(documentedAssertions(), realAssertions());

    expect($invented)->toBeEmpty(
        'AGENTS.md promises assertions that do not exist: '.implode(', ', $invented),
    );
});

it('lists them in a stable order so the diff means something', function () {
    $documented = documentedAssertions();
    $sorted = $documented;
    sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);

    expect($documented)->toBe($sorted);
});

/**
 * The instruction the whole document exists to deliver. Everything else is
 * reference material an agent could half-guess; this is the judgement it
 * cannot, and the difference between an eval and a green light that means
 * nothing.
 */
it('keeps telling agents not to write a dataset that always passes', function () {
    $doc = file_get_contents(__DIR__.'/../../AGENTS.md');

    expect($doc)
        ->toContain('At least half the rows')
        ->toContain('Show the dataset to the human before committing it.')
        ->toContain('Never invent an assertion.');
});
