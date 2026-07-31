<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

/**
 * Brand-voice check: fails when common American spellings appear.
 */
class BritishSpelling implements Assertion
{
    private const AMERICAN_PATTERNS = [
        '/\b\w+ize\b/i' => 'American -ize endings (should be -ise)',
        '/\b\w+ization\b/i' => 'American -ization endings (should be -isation)',
        '/\b(color|honor|favor|humor|labor|neighbor|rumor|tumor|vigor)\b/i' => 'American -or endings (should be -our)',
        '/\b(center|theater|meter|liter|fiber)\b/i' => 'American -er endings (should be -re)',
        '/\b(defense|offense|license)\b/i' => 'American -ense endings (should be -ence)',
        '/\b(gray|aluminum|tire|curb|pajamas|donut)\b/i' => 'American spelling variants',
    ];

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $found = [];

        foreach (self::AMERICAN_PATTERNS as $pattern => $description) {
            if (preg_match_all($pattern, $response->text, $matches)) {
                $found[] = $description.': '.implode(', ', array_unique($matches[0]));
            }
        }

        return AssertionResult::bool(
            'british_spelling',
            $found === [],
            'British spelling only',
            $found === [] ? 'British spelling' : implode('; ', $found),
            $found === [] ? '' : 'Found Americanisms: '.implode('; ', $found),
        );
    }
}
