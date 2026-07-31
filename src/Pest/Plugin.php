<?php

namespace Vizra\Evals\Pest;

use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Plugins\Concerns\HandleArguments;

/**
 * Handles the --evals flag for Pest runs. Mirrors pest-plugin-evals'
 * PEST_EVALS environment contract exactly, so the two plugins interoperate:
 * whichever one consumes the CLI flag, both see the same mode.
 */
final class Plugin implements HandlesArguments
{
    use HandleArguments;

    private const string ENV_EVAL_MODE = 'PEST_EVALS';

    private static bool $evalMode = false;

    public static function isEvalMode(): bool
    {
        return self::$evalMode
            || ($_SERVER[self::ENV_EVAL_MODE] ?? $_ENV[self::ENV_EVAL_MODE] ?? getenv(self::ENV_EVAL_MODE)) === '1';
    }

    /** @internal for tests */
    public static function setEvalMode(bool $mode): void
    {
        self::$evalMode = $mode;
    }

    public function handleArguments(array $arguments): array
    {
        if (! $this->hasArgument('--evals', $arguments)) {
            return $arguments;
        }

        self::$evalMode = true;
        $_SERVER[self::ENV_EVAL_MODE] = '1';
        $_ENV[self::ENV_EVAL_MODE] = '1';
        putenv(self::ENV_EVAL_MODE.'=1');

        return $this->popArgument('--evals', $arguments);
    }
}
