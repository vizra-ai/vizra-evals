<?php

namespace Vizra\Evals\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

class Git
{
    /**
     * @return array{sha: ?string, branch: ?string, dirty: ?bool}
     */
    public static function capture(?string $path = null): array
    {
        $path ??= base_path();

        try {
            $sha = Process::path($path)->run('git rev-parse HEAD');

            if (! $sha->successful()) {
                return ['sha' => null, 'branch' => null, 'dirty' => null];
            }

            $branch = Process::path($path)->run('git rev-parse --abbrev-ref HEAD');
            $status = Process::path($path)->run('git status --porcelain');

            return [
                'sha' => trim($sha->output()),
                'branch' => $branch->successful() ? trim($branch->output()) : null,
                'dirty' => $status->successful() ? trim($status->output()) !== '' : null,
            ];
        } catch (Throwable) {
            return ['sha' => null, 'branch' => null, 'dirty' => null];
        }
    }
}
