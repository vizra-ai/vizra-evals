<?php

namespace Vizra\Evals\Assertions\Concerns;

use Vizra\Evals\Assertions\AssertionResult;
use Vizra\Evals\Assertions\Safety\ContainsNoBlockedWords;
use Vizra\Evals\Assertions\Safety\NoObviousPII;

trait AssertsSafety
{
    /**
     * Wordlist pre-filter (built-in list + config + $additional). Not a
     * toxicity classifier — use judge() for anything requiring judgment.
     */
    protected function assertContainsNoBlockedWords(array $additional = []): AssertionResult
    {
        return $this->assertWith(new ContainsNoBlockedWords($additional));
    }

    /**
     * Regex-grade PII scan (emails, US SSNs/phones, card-shaped numbers,
     * IPv4). A cheap pre-filter, not a compliance control.
     */
    protected function assertNoObviousPII(): AssertionResult
    {
        return $this->assertWith(new NoObviousPII);
    }
}
