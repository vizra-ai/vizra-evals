# Draft: pest-plugin-evals `ScorerReporter` proposal

> Draft issue/PR pitch for pestphp/pest-plugin-evals. Goal: an official
> extension point for observing eval results, so ecosystem packages
> (persistence, dashboards, CI artifacts) can build on the plugin without
> touching `@internal` classes.

---

**Title: Extension point: report scorer results to registered reporters**

Right now every `ScorerResult` is asserted, optionally rendered by the
verbose panel, and discarded. That's exactly right for the test run itself —
but it means the ecosystem can't build anything on top of eval results:
history/trend tracking, baselines, CI artifacts, dashboards all need to
observe results, and today the only options are forking or reflection into
`@internal` classes.

Proposal — a minimal reporter contract:

```php
namespace Pest\Evals\Contracts;

use Pest\Evals\Scorers\ScorerResult;

interface ScorerReporter
{
    /**
     * Called once per scored sample, after assertion.
     */
    public function report(
        string $testName,
        string $input,
        string $output,
        ScorerResult $result,
        float $threshold,
        bool $passed,
        int $sampleIndex,
        int $sampleCount,
    ): void;
}
```

Registration alongside the existing driver configuration:

```php
pest()->evals()->reportUsing(new MyReporter());
```

Implementation surface is small: `ScorerAssertion::assert()` already has
every value in scope at the moment it renders the verbose panel — the loop
body gains one `$reporter?->report(...)` call. Deterministic expectations
(`toContain` etc. via `Samples`) could join later; scorer results alone are
enough for a first iteration.

Non-goals: no persistence, no storage format, no new CLI flags in the plugin
itself — reporters decide what to do with results. The plugin stays exactly
as lean as it is.

Context: we maintain [vizra/evals](https://github.com/vizra-ai/vizra-evals),
which records eval runs (scores, judge reasoning, cost) with baseline
comparison and a dashboard. We'd ship a reporter the day this lands, and
happy to contribute the PR if the shape looks right.
