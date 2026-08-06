<?php

use Vizra\Evals\Support\Git;

/**
 * Git context is best-effort by design: an eval run from a tarball, a Docker
 * image or a directory nobody ever initialised is still a valid run, so every
 * field here has to be allowed to be null without anything breaking.
 */
it('captures the commit subject alongside the sha', function () {
    // This package is itself a git repository, so it is its own fixture.
    $captured = Git::capture(dirname(__DIR__, 2));

    expect($captured['sha'])->toMatch('/^[a-f0-9]{40}$/')
        ->and($captured['message'])->toBeString()
        ->and($captured['message'])->not->toBeEmpty()
        // Subject only. A commit body runs to paragraphs and this is rendered
        // on one line beside the sha.
        ->and($captured['message'])->not->toContain("\n")
        // Capped where it is captured rather than at the far end, so the local
        // dashboard and the hosted one show the same string.
        ->and(mb_strlen($captured['message']))->toBeLessThanOrEqual(255);
});

it('returns nulls rather than failing outside a repository', function () {
    $captured = Git::capture(sys_get_temp_dir());

    expect($captured)->toBe([
        'sha' => null,
        'branch' => null,
        'dirty' => null,
        'message' => null,
    ]);
});
