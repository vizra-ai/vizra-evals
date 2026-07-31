<?php

namespace Vizra\Evals\Assertions\Content;

use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Assertions\Assertion;
use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Dataset\Row;

/**
 * Brand-voice check: fails when common British spellings appear.
 */
class AmericanSpelling implements Assertion
{
    private const BRITISH_PATTERNS = [
        '/\b\w+ise\b/i' => 'British -ise endings (should be -ize)',
        '/\b\w+isation\b/i' => 'British -isation endings (should be -ization)',
        '/\b(colour|honour|favour|humour|labour|neighbour|rumour|tumour|vigour)\b/i' => 'British -our endings (should be -or)',
        '/\b(centre|theatre|metre|litre|fibre)\b/i' => 'British -re endings (should be -er)',
        '/\b(defence|offence|licence)\b/i' => 'British -ence endings (should be -ense)',
        '/\b(grey|aluminium|tyre|kerb|pyjamas|doughnut)\b/i' => 'British spelling variants',
    ];

    public function __invoke(AgentResponse $response, Row $row): AssertionResult
    {
        $found = [];

        foreach (self::BRITISH_PATTERNS as $pattern => $description) {
            if (preg_match_all($pattern, $response->text, $matches)) {
                $found[] = $description.': '.implode(', ', array_unique($matches[0]));
            }
        }

        return AssertionResult::bool(
            'american_spelling',
            $found === [],
            'American spelling only',
            $found === [] ? 'American spelling' : implode('; ', $found),
            $found === [] ? '' : 'Found Briticisms: '.implode('; ', $found),
        );
    }
}
