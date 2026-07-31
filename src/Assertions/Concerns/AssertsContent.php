<?php

namespace Vizra\Evals\Assertions\Concerns;

use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Assertions\Content\AmericanSpelling;
use Vizra\Evals\Assertions\Content\BritishSpelling;
use Vizra\Evals\Assertions\Content\Contains;
use Vizra\Evals\Assertions\Content\ContainsAllOf;
use Vizra\Evals\Assertions\Content\ContainsAnyOf;
use Vizra\Evals\Assertions\Content\EndsWith;
use Vizra\Evals\Assertions\Content\LengthBetween;
use Vizra\Evals\Assertions\Content\MatchesRegex;
use Vizra\Evals\Assertions\Content\NotContains;
use Vizra\Evals\Assertions\Content\NotEmpty;
use Vizra\Evals\Assertions\Content\StartsWith;
use Vizra\Evals\Assertions\Content\WordCountBetween;

trait AssertsContent
{
    protected function assertContains(string $needle, bool $ignoreCase = true): AssertionResult
    {
        return $this->assertWith(new Contains($needle, $ignoreCase));
    }

    protected function assertNotContains(string $needle, bool $ignoreCase = true): AssertionResult
    {
        return $this->assertWith(new NotContains($needle, $ignoreCase));
    }

    protected function assertContainsAnyOf(array $needles, bool $ignoreCase = true): AssertionResult
    {
        return $this->assertWith(new ContainsAnyOf($needles, $ignoreCase));
    }

    protected function assertContainsAllOf(array $needles, bool $ignoreCase = true): AssertionResult
    {
        return $this->assertWith(new ContainsAllOf($needles, $ignoreCase));
    }

    protected function assertStartsWith(string $prefix): AssertionResult
    {
        return $this->assertWith(new StartsWith($prefix));
    }

    protected function assertEndsWith(string $suffix): AssertionResult
    {
        return $this->assertWith(new EndsWith($suffix));
    }

    protected function assertMatchesRegex(string $pattern): AssertionResult
    {
        return $this->assertWith(new MatchesRegex($pattern));
    }

    protected function assertLengthBetween(int $min, int $max): AssertionResult
    {
        return $this->assertWith(new LengthBetween($min, $max));
    }

    protected function assertWordCountBetween(int $min, int $max): AssertionResult
    {
        return $this->assertWith(new WordCountBetween($min, $max));
    }

    protected function assertNotEmpty(): AssertionResult
    {
        return $this->assertWith(new NotEmpty);
    }

    protected function assertIsBritishSpelling(): AssertionResult
    {
        return $this->assertWith(new BritishSpelling);
    }

    protected function assertIsAmericanSpelling(): AssertionResult
    {
        return $this->assertWith(new AmericanSpelling);
    }
}
