<?php

namespace Vizra\Evals\Cloud;

/**
 * What CI, if any, this run happened in.
 *
 * The run itself does not record this — a local run and a CI run produce
 * identical rows — but the cloud needs it to separate "someone tried something
 * on their laptop" from "this is what main scores", and to link a run back to
 * the pull request that caused it.
 *
 * Everything here is read from environment variables the providers set
 * themselves, so nothing has to be configured by hand.
 */
class Environment
{
    /**
     * @return array{provider: ?string, build_url: ?string, pull_request: ?int, branch: ?string}
     */
    public static function ci(): array
    {
        if (getenv('GITHUB_ACTIONS') !== false) {
            $server = getenv('GITHUB_SERVER_URL') ?: 'https://github.com';
            $repo = getenv('GITHUB_REPOSITORY') ?: null;
            $runId = getenv('GITHUB_RUN_ID') ?: null;

            return [
                'provider' => 'github',
                'build_url' => $repo && $runId ? "{$server}/{$repo}/actions/runs/{$runId}" : null,
                // GITHUB_REF on a PR is "refs/pull/42/merge".
                'pull_request' => self::intFrom(getenv('GITHUB_REF') ?: '', '#refs/pull/(\d+)/#'),
                // On a PR, GITHUB_REF_NAME is "42/merge" rather than the branch,
                // so the head ref is the only honest answer.
                'branch' => (getenv('GITHUB_HEAD_REF') ?: getenv('GITHUB_REF_NAME')) ?: null,
            ];
        }

        if (getenv('GITLAB_CI') !== false) {
            return [
                'provider' => 'gitlab',
                'build_url' => getenv('CI_JOB_URL') ?: null,
                'pull_request' => self::intFrom(getenv('CI_MERGE_REQUEST_IID') ?: '', '#^(\d+)$#'),
                'branch' => (getenv('CI_COMMIT_REF_NAME') ?: null) ?: null,
            ];
        }

        if (getenv('CIRCLECI') !== false) {
            return [
                'provider' => 'circleci',
                'build_url' => getenv('CIRCLE_BUILD_URL') ?: null,
                'pull_request' => self::intFrom(getenv('CIRCLE_PULL_REQUEST') ?: '', '#/(\d+)$#'),
                'branch' => (getenv('CIRCLE_BRANCH') ?: null) ?: null,
            ];
        }

        if (getenv('BUILDKITE') !== false) {
            return [
                'provider' => 'buildkite',
                'build_url' => getenv('BUILDKITE_BUILD_URL') ?: null,
                'pull_request' => self::intFrom(getenv('BUILDKITE_PULL_REQUEST') ?: '', '#^(\d+)$#'),
                'branch' => (getenv('BUILDKITE_BRANCH') ?: null) ?: null,
            ];
        }

        return ['provider' => null, 'build_url' => null, 'pull_request' => null, 'branch' => null];
    }

    /**
     * The environment a run is filed under.
     *
     * Anything running in CI is `ci` regardless of APP_ENV, because CI boxes
     * almost always set APP_ENV=testing and filing every CI run under
     * "testing" would put them in the same bucket as someone's local test run.
     */
    public static function name(): string
    {
        $configured = config('evals.cloud.environment');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (self::ci()['provider'] !== null || getenv('CI') !== false) {
            return 'ci';
        }

        return (string) app()->environment();
    }

    private static function intFrom(string $value, string $pattern): ?int
    {
        return preg_match($pattern, $value, $matches) === 1 ? (int) $matches[1] : null;
    }
}
