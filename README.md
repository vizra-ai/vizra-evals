# Vizra Evals

**Write agent evals as Pest tests. Keep every result.**

Pest tells you whether your AI agent passed *today*. Vizra Evals records every run — sampled scores, pass rates, judge reasoning, tool calls, cost — so you can hold a baseline, fail CI on regressions, and watch quality trend over time in a dashboard. Built for agents on the official [Laravel AI SDK](https://github.com/laravel/ai).

```bash
composer require vizra/evals --dev
php artisan migrate
```

Requires PHP 8.4+, Laravel 12+, `laravel/ai`, and Pest 5 for the testing surface (a standalone CLI exists too — see below).

## The five-minute version

**1. Write a Pest test:**

```php
// tests/Evals/SupportBotTest.php

use App\Agents\SupportBot;

it('answers support questions from documented policy', function () {
    expect(SupportBot::class)->toPassEval(fn ($eval) => $eval
        ->dataset(base_path('evals/support.jsonl'))
        ->samples(3)
        ->assert(fn ($a, $row) => $a
            ->notEmpty()->gate()
            ->contains($row->expected())
            ->costBelow(0.02))
        ->judge('Answers using only documented store policy.', min: 7)
        ->gate(minScore: 0.8, maxRegressions: 0)
    );
});
```

**2. Give it data** — one JSON object per line:

```jsonl
{"input": "What is your refund policy?", "expected": "30 days"}
{"messages": [{"role": "user", "content": "Hi, I ordered a lamp"}, {"role": "assistant", "content": "How can I help?"}, {"role": "user", "content": "Can I return it?"}], "expected": "30 days"}
```

`input` is the prompt; or give `messages` and the final user turn becomes the prompt with earlier turns replayed as real conversation context. `expected` is reference data; other keys land in `$row->meta()`.

**3. Run it:**

```bash
./vendor/bin/pest             # evals skipped — zero tokens, zero cost
./vendor/bin/pest --evals     # evals run against the real model
```

Each row runs 3 times (agents are nondeterministic — one sample proves nothing). Deterministic checks run first; a failed `->gate()` skips the LLM judge for that sample, so broken samples never spend judge tokens. Everything persists: scores, pass rates, judge reasoning, tool calls, token usage, cost.

**4. Regressions fail the build.** The first passing run becomes the suite's baseline automatically. From then on, any row whose pass rate drops — or whose score falls beyond tolerance — fails the test with the receipts:

```
Eval [pest: answers support questions from documented policy] — score 61.7%, pass rate 33.3% across 6 samples (run 01kyw…).
Gate failed: 2 rows regressed against the reference run (allowed: 0).
  ↓ regressed: "What is your refund policy?" 96.7% → 51.7%
  ↓ regressed: "Can I return it?" 93.3% → 55.0%
```

**5. Watch it over time** — install [`vizra/evals-ui`](../vizra-evals-ui) and visit `/evals` for score trends, per-sample drill-downs with judge reasoning, and run comparisons.

## Inline assertions

Inside `->assert(fn ($a, $row, $response) => ...)`, methods are chainable, and any assertion can take `->gate()` (failure hard-fails the sample, skips judges) or `->weight(float)`:

- **Content** — `contains`, `notContains`, `containsAnyOf`, `containsAllOf`, `startsWith`, `endsWith`, `matchesRegex`, `lengthBetween`, `wordCountBetween`, `notEmpty`, `isBritishSpelling`, `isAmericanSpelling`
- **Structure** — `validJson`, `jsonHasKey`, `validXml`, `xmlHasTag`; structured output: `outputHasKey`, `outputKey('score', fn ($v) => $v >= 1)`, `outputKeyMatches`
- **Agent behavior** (against the real `AgentResponse`, not text parsing) — `toolCalled`, `toolNotCalled`, `toolCalledWith('lookup_order', ['id' => 7])`, `toolCallOrder([...])`, `stepsBelow`, `finishReason(FinishReason::Stop)`, `noPendingApprovals`
- **Usage & cost** — `costBelow`, `tokensBelow`, `cacheHitRateAbove`, `durationBelow`, `modelUsed`, `providerUsed`
- **Safety pre-filters** — `containsNoBlockedWords`, `noObviousPII` (honest names: wordlist and regex checks, not classifiers)

Custom checks implement `Vizra\Evals\Assertions\Assertion` and run via `$a->with(new MyAssertion(...))` — or subclass `Evaluation` for the full authoring surface and point the test at it with `->using(SupportQuality::class)`.

## The judge

`->judge($criteria, min: 7)` runs a structured-output judge agent — `{score: 1–10, reasoning}` — no regex response parsing anywhere. Reasoning is persisted per sample (it's the debugging payload). Options: `dimensions: ['accuracy' => 7, 'tone' => 6]`, `provider:`/`model:` (point the judge at a *different model family* than the agent under test — models grade their own family leniently), `using: MyJudge::class`.

Don't trust an uncalibrated judge — feed it human-labelled data and measure agreement:

```bash
php artisan evals:calibrate storage/labelled.jsonl --criteria="Correctness"
```

## Datasets

`->dataset(...)` accepts a `.jsonl`/`.csv` path, an inline array, or any `Dataset`:

| | |
|---|---|
| `Dataset::fromJsonl($path)` | preferred format, streamed lazily |
| `Dataset::fromCsv($path)` | spreadsheets non-devs can edit |
| `Dataset::fromArray([...])` | quick starts |
| `Dataset::fromEloquent($query, fn ($m) => [...])` | anything in your DB |
| `->fromConversations(take: 50)` | **real production traffic** from the SDK's conversation tables |

`fromConversations()` turns stored conversations into multi-turn rows: latest user turn becomes the prompt, prior turns replay, and the reply your agent actually gave becomes `$row->expected()`. Rows carry a content hash, so the same logical row is tracked across runs even when files are reordered.

## Scoring model

Deterministic assertions score 1/0, judge scores normalize to 0–1, weights apply. A failed gate zeroes the sample. Row result = pass rate + score mean/stddev across samples; run result aggregates rows. Pass/fail is a **run-level policy** (`->gate(minScore:, minPassRate:, maxRegressions:)`), not a per-assertion verdict. Comparisons join rows across runs by content hash; a score drop within `evals.compare.epsilon` (default 0.05) is jitter, not a regression — pass-rate drops always count.

## Beyond the test suite

Everything also runs without Pest — same engine, same tables, same dashboard:

```bash
php artisan evals:run SupportQuality              # class-based evaluation
php artisan evals:run SupportQuality --dry-run    # validate wiring, zero tokens (SDK fakes)
php artisan evals:run SupportQuality --compare=baseline --output=json   # CI without Pest
php artisan evals:baseline {run-id}               # promote any past run
php artisan make:eval SupportQuality              # scaffold a class + dataset
```

Class-based `Evaluation`s add `across()` model matrices (each provider/model combo becomes its own series), `transform()` hooks, and are what the dashboard's Run button executes. Exit codes: `0` pass, `1` gate/regression failure, `2` harness failure.

## Testing your evals without spending tokens

The SDK's fakes work end-to-end: `SupportBot::fake([...])` (plus `Ai::fakeAgent(JudgeAgent::class, ...)` if you use judges), then run the test with `PEST_EVALS=1`. Multi-turn rows route straight to a faked agent, and `assertPrompted()` sees every prompt. The package's own 148 tests run this way — no network, no keys.

## Vizra Cloud

Local runs live in your own database, which means baselines live on whoever's
laptop set them and CI has no history at all. Set one variable and every
finished run is also pushed to the hosted dashboard:

```bash
VIZRA_CLOUD_KEY=vz_...
```

That is the whole setup. Reporting then happens automatically after every run —
`--no-report` skips it for a one-off, and dry runs are never reported, because
faked numbers filed in the history would look exactly like real ones.

Runs from CI are filed under `ci` rather than whatever `APP_ENV` happens to be
on the build box, and the branch and pull-request number come from the CI
provider — GitHub Actions, GitLab, CircleCI and Buildkite are detected without
configuration. Nothing needs to be set by hand.

**Reporting cannot fail your build.** A run that passed its gate has passed it
whether or not the upload worked; a failed upload prints a warning and leaves
the exit code alone.

### The Run button

Setting the key also lets you trigger a run from the hosted dashboard, with
nothing else to install and no line to add: the package registers
`evals:runner` on your scheduler itself, and the cron already running
`schedule:run` picks it up.

Vizra Cloud never executes your code. It holds no model keys, no database
credentials and no repo — a click there queues a request, your app collects it
on its next check, and the eval runs against your environment exactly as it
would from your terminal. Every call is outbound, so nothing has to be exposed:
no route, no public hostname, no firewall rule. It works from staging behind a
VPN, from a container, or from a laptop.

The check runs once a minute and does nothing at all unless a run has been
requested. If you would rather register it yourself:

```bash
VIZRA_CLOUD_AUTO_SCHEDULE=false
```

```php
Schedule::command('evals:runner')->everyMinute()->withoutOverlapping();
```

Note that `evals:runner` only exists where this package is installed. If you
require it with `--dev`, the Run button works in any environment that installs
dev dependencies — which is normally where you want evals running anyway.

If nothing of yours is running with cron — plenty of teams only run evals in
CI — point Vizra Cloud at a workflow instead, from the project's settings. It
starts the workflow, the workflow runs `evals:runner`, and that claims the same
queued run. Nothing extra to install; the workflow is a dozen lines and the
dashboard shows you exactly what to paste.

### What gets sent

Scores, timings, cost and git metadata always. Per-sample detail — the verbatim
prompt, the model's response, tool calls and judge reasoning — is what powers
the drill-down on the hosted run page, and is sent by default.

If model output is not allowed to leave your network:

```bash
VIZRA_CLOUD_SAMPLES=false
```

You keep the trend line, the baselines, the per-row scores and the PR checks,
and lose only the ability to click into a row and read what the model actually
said.

## Configuration

`php artisan vendor:publish --tag=evals-config` — judge defaults, gate defaults, comparison epsilon, concurrency, table prefix, and the **user-maintained** price table that powers cost tracking (unknown models cost `null` + one warning, never an error).

## Coming from vizra-adk or pest-plugin-evals?

From **vizra-adk**: `$agentName`/`Agent::run()` → a `laravel/ai` agent target; `evaluateRow($row, string $response)` → assertions against the full `AgentResponse`; CSV results → database + dashboard; `assertNotToxic`/`assertNoPII` → honestly renamed pre-filters; sentiment/grammar/readability assertions are gone (each is one `judge()` call done properly).

From **pest-plugin-evals**: the two coexist in one file — Nuno's expectations are quick unrecorded text checks; `toPassEval()` is for datasets, sampling, real tool-call assertions, multi-turn, and everything you want recorded.

## License

MIT. See [LICENSE.md](LICENSE.md).
