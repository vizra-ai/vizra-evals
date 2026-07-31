<?php

namespace Vizra\Evals\Run;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Vizra\Evals\Evaluation;

/**
 * Finds Evaluation classes. Named lookups probe App\Evals\{Studly} and the
 * raw FQCN; the no-argument scan walks config('evals.paths') recursively and
 * parses each file's real namespace (so classes in subdirectories resolve
 * correctly, unlike filename-only discovery).
 */
class Discovery
{
    /**
     * @return class-string<Evaluation>|null
     */
    public static function resolve(string $name): ?string
    {
        $candidates = [
            app()->getNamespace().'Evals\\'.Str::studly($name),
            $name,
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && is_subclass_of($candidate, Evaluation::class)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, class-string<Evaluation>>
     */
    public static function all(?string $filter = null): array
    {
        $paths = array_filter(
            [...config('evals.paths', []), app_path('Evals')],
            fn (string $path) => is_dir($path)
        );

        if ($paths === []) {
            return [];
        }

        $classes = [];

        foreach ((new Finder)->files()->in($paths)->name('*.php') as $file) {
            $class = self::classFromFile($file->getRealPath());

            if ($class !== null
                && class_exists($class)
                && is_subclass_of($class, Evaluation::class)
                && ! (new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        if ($filter !== null && $filter !== '') {
            $classes = array_values(array_filter(
                $classes,
                fn (string $class) => Str::contains($class, $filter, ignoreCase: true)
            ));
        }

        return $classes;
    }

    private static function classFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        foreach (\PhpToken::tokenize($contents) as $index => $token) {
            if ($token->is(T_NAMESPACE) && $namespace === null) {
                $namespace = self::nameAfter($contents, $token);
            }

            if ($token->is(T_CLASS) && $class === null) {
                $class = self::nameAfter($contents, $token);
            }
        }

        if ($class === null) {
            return null;
        }

        return $namespace === null ? $class : $namespace.'\\'.$class;
    }

    private static function nameAfter(string $contents, \PhpToken $token): ?string
    {
        $offset = $token->pos + strlen($token->text);

        return preg_match('/\G\s+([A-Za-z0-9_\\\\]+)/', $contents, $matches, 0, $offset) === 1
            ? $matches[1]
            : null;
    }
}
