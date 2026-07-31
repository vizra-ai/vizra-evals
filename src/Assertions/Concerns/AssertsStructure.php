<?php

namespace Vizra\Evals\Assertions\Concerns;

use Closure;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Assertions\Structure\JsonHasKey;
use Vizra\Evals\Assertions\Structure\OutputHasKey;
use Vizra\Evals\Assertions\Structure\OutputKey;
use Vizra\Evals\Assertions\Structure\ValidJson;
use Vizra\Evals\Assertions\Structure\ValidXml;
use Vizra\Evals\Assertions\Structure\XmlHasTag;

trait AssertsStructure
{
    protected function assertValidJson(): AssertionResult
    {
        return $this->assertWith(new ValidJson);
    }

    protected function assertJsonHasKey(string $key): AssertionResult
    {
        return $this->assertWith(new JsonHasKey($key));
    }

    protected function assertValidXml(): AssertionResult
    {
        return $this->assertWith(new ValidXml);
    }

    protected function assertXmlHasTag(string $tag): AssertionResult
    {
        return $this->assertWith(new XmlHasTag($tag));
    }

    protected function assertOutputHasKey(string $key): AssertionResult
    {
        return $this->assertWith(new OutputHasKey($key));
    }

    /**
     * Assert on a structured-output key: pass a literal for equality or a
     * Closure(mixed $value): bool for a custom check.
     */
    protected function assertOutputKey(string $key, mixed $expectation): AssertionResult
    {
        return $this->assertWith(new OutputKey($key, $expectation));
    }

    protected function assertOutputKeyMatches(string $key, string $pattern): AssertionResult
    {
        return $this->assertWith(new OutputKey($key, fn ($value) => is_string($value) && preg_match($pattern, $value) === 1), 'output_key_matches');
    }
}
