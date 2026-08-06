<?php

namespace Vizra\Evals\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

class Git
{
    /**
     * @return array{sha: ?string, branch: ?string, dirty: ?bool, message: ?string}
     */
    public static function capture(?string $path = null): array
    {
        $path ??= base_path();

        try {
            $sha = Process::path($path)->run('git rev-parse HEAD');

            if (! $sha->successful()) {
                return self::blank();
            }

            $branch = Process::path($path)->run('git rev-parse --abbrev-ref HEAD');
            $status = Process::path($path)->run('git status --porcelain');
            // Subject only. The body of a commit message is prose that can run
            // to paragraphs, and this is rendered on one line beside the sha.
            $message = Process::path($path)->run('git log -1 --pretty=%s');

            return [
                'sha' => trim($sha->output()),
                'branch' => $branch->successful() ? trim($branch->output()) : null,
                'dirty' => $status->successful() ? trim($status->output()) !== '' : null,
                // Capped to the column it lands in, here rather than at the
                // far end, so the local dashboard and the hosted one show the
                // same string.
                'message' => $message->successful()
                    ? (mb_substr(trim($message->output()), 0, 255) ?: null)
                    : null,
            ];
        } catch (Throwable) {
            return self::blank();
        }
    }

    /**
     * @return array{sha: null, branch: null, dirty: null, message: null}
     */
    private static function blank(): array
    {
        return ['sha' => null, 'branch' => null, 'dirty' => null, 'message' => null];
    }
}
