# Writing evals with `vizra/evals`

Instructions for a coding agent working in an application that has this
package installed. Everything below is the real API — do not invent methods.

## What this package is

Evals for AI agents, written as Pest tests or as classes. An eval runs the
application's own agent against a dataset, scores each response, and records
the result so two runs can be compared. Ordinary `pest` skips evals entirely,
so they never run by accident and never spend tokens in an unrelated suite.

## The shape of an evaluation

```php
namespace App\Evals;

use App\Agents\SupportBot;
use Laravel\Ai\Responses\AgentResponse;
use Vizra\Evals\Dataset\Dataset;
use Vizra\Evals\Dataset\Row;
use Vizra\Evals\Evaluation;
use Vizra\Evals\Run\Gate;

class SupportBotQuality extends Evaluation
{
    /** Each row runs this many times. Agents are nondeterministic; one
        sample proves nothing. Three is the usual choice. */
    public int $samples = 3;

    public function target(): mixed
    {
        return SupportBot::class;
    }

    public function dataset(): Dataset
    {
        return Dataset::fromJsonl(base_path('evals/data/support_bot.jsonl'));
    }

    public function evaluate(Row $row, AgentResponse $response): void
    {
        // ->gate() means: if this fails, skip the judge for this sample.
        // A broken response should never spend judge tokens.
        $this->assertNotEmpty()->gate();

        if ($row->expected() !== null) {
            $this->assertContains($row->expected());
        }

        $this->assertCostBelow(0.01);

        $this->judge()
            ->criteria('...')
            ->minScore(6);
    }

    /** Run-level policy. Decides the CI exit code. */
    public function gatePolicy(): ?Gate
    {
        return new Gate(minScore: 0.7, maxRegressions: 0);
    }
}
```

Scaffold one with `php artisan make:eval SupportBotQuality`, which writes both
the class and an empty dataset file.

## Datasets

JSONL, one object per line. `input` is required; `expected` is optional and is
what `$row->expected()` returns.

```
{"input": "Can I get a refund without a receipt?", "expected": "receipt"}
{"input": "Do you ship to Australia?", "expected": "no"}
```

Multi-turn rows use `messages` instead of `input`:

```
{"messages": [{"role":"user","content":"I bought a lamp"},{"role":"assistant","content":"..."},{"role":"user","content":"Can I return it?"}], "expected": "30 days"}
```

Also available: `Dataset::fromArray([...])`, `Dataset::fromCsv($path)`.

## Writing a dataset that measures something

**This is the part that decides whether the eval is worth having, and the part
most likely to be done badly.**

A dataset of questions the agent can obviously answer measures only that the
API is up. At least half the rows should be cases where the helpful-sounding
answer is the wrong one:

- questions outside the documented facts, which it should decline
- requests it should refuse
- edge cases with no good answer
- things a plausible-sounding invention would fit

Those are where agents fail in production, and they are the rows that move
when somebody swaps a model to save money.

A suite that has passed every row on every run since it was written is not
usually a healthy agent. It is a test that does not test anything.

**Show the dataset to the human before committing it.** A generated dataset
nobody has read is how a project ends up with a green dashboard that means
nothing.

## Assertions

Every assertion below exists. Nothing else does.

<!-- assertions:start -->
- `assertCacheHitRateAbove`
- `assertContains`
- `assertContainsAllOf`
- `assertContainsAnyOf`
- `assertContainsNoBlockedWords`
- `assertCostBelow`
- `assertDurationBelow`
- `assertEndsWith`
- `assertEquals`
- `assertFalse`
- `assertFinishReason`
- `assertGreaterThan`
- `assertIsAmericanSpelling`
- `assertIsBritishSpelling`
- `assertJsonHasKey`
- `assertLengthBetween`
- `assertLessThan`
- `assertMatchesRegex`
- `assertModelUsed`
- `assertNoObviousPII`
- `assertNoPendingApprovals`
- `assertNotContains`
- `assertNotEmpty`
- `assertOutputHasKey`
- `assertOutputKey`
- `assertOutputKeyMatches`
- `assertProviderUsed`
- `assertStartsWith`
- `assertStepsBelow`
- `assertTokensBelow`
- `assertToolCalled`
- `assertToolCalledWith`
- `assertToolCallOrder`
- `assertToolNotCalled`
- `assertTrue`
- `assertValidJson`
- `assertValidXml`
- `assertWith`
- `assertWordCountBetween`
- `assertXmlHasTag`
<!-- assertions:end -->

Modifiers, chainable on any of them:

- `->gate()` — on failure, skip the judge for this sample
- `->weight(float)` — change its share of the sample's score

## The judge

```php
$this->judge()
    ->criteria('The reply may use only these facts: <list them>. Anything
        outside that list must be declined rather than guessed at —
        inventing a plausible policy is worse than saying "I do not know".')
    ->minScore(6);
```

Scores 0–10, normalised to 0–1.

Name the facts the answer is allowed to use, and say what the worst outcome
is. Vague criteria — "is it helpful", "is it accurate" — produce a vibe check
with a number attached.

Use a different model family from the agent under test. Models grade their own
output generously. Configure with `config('evals.judge.provider')` and
`config('evals.judge.model')`.

## Commands

```bash
php artisan evals:ping                          # prove the Cloud connection first
php artisan make:eval SupportBotQuality
php artisan evals:run SupportBotQuality
php artisan evals:run SupportBotQuality --dry-run    # SDK fakes, zero tokens
php artisan evals:run SupportBotQuality --compare=baseline --output=json
php artisan evals:baseline {run-id}
php artisan evals:sync-pricing                  # current prices for cost estimates
./vendor/bin/pest --evals
```

Exit codes: `0` pass, `1` gate or regression failure, `2` harness failure.

Always run `--dry-run` first. It exercises the whole path on SDK fakes and
costs nothing, so a wiring mistake is found before any tokens are spent.

## Testing an eval without spending tokens

`SupportBot::fake([...])`, plus `Ai::fakeAgent(JudgeAgent::class, ...)` if the
eval judges. Then run with `PEST_EVALS=1`.

## Reporting to Vizra Cloud

Set `VIZRA_CLOUD_KEY` in `.env` and finished runs are reported. Leave it unset
and nothing leaves the machine. Ask the human for the key — do not guess one,
and do not commit it.

`VIZRA_CLOUD_SAMPLES=false` sends scores without model input and output, for
teams that cannot send that off-site.

## Things to get right

- Never invent an assertion. The list above is complete.
- Never write an eval whose rows all obviously pass.
- Put datasets in `evals/data/`, evaluations in `app/Evals/`.
- Do not set `VIZRA_CLOUD_KEY` to a placeholder. An invalid key makes every
  run report a failure that looks like the product is broken.
- Run `--dry-run` before the real thing.
