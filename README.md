# Vizra Evals

Evaluation framework for AI agents built on the official [Laravel AI SDK](https://github.com/laravel/ai) (`laravel/ai`).

Evals are not unit tests. Agents are nondeterministic, so Vizra Evals never draws a conclusion from a single run: every row is **sampled N times**, results are **scores and pass rates** rather than single booleans, every run is **persisted to your database**, and the core workflow is **comparing a run against a baseline** — which is what makes it useful in CI.

```
composer require vizra/evals
php artisan vendor:publish --tag=evals-config
php artisan migrate
```

Requires PHP 8.3+, Laravel 12+, and `laravel/ai`.

## Five-minute quickstart

**1. Generate an evaluation** (creates the class and a starter JSONL dataset):

```
php artisan make:eval SupportQuality
```

**2. Point it at your agent and describe what "good" looks like:**

```php
class SupportQuality extends Evaluation
{
    public int $samples = 3;

    public function target(): mixed
    {
        return SupportAgent::class;   // any Laravel\Ai agent — or an instance, or a Closure
    }

    public function dataset(): Dataset
    {
        return Dataset::fromJsonl(base_path('evals/data/support_quality.jsonl'));
    }

    public function evaluate(Row $row, AgentResponse $response): void
    {
        // Cheap deterministic checks run first. A failed ->gate() hard-fails
        // the sample and skips the judge — no tokens wasted on broken samples.
        $this->assertFinishReason(FinishReason::Stop)->gate();
        $this->assertToolCalled('lookup_order');
        $this->assertCostBelow(0.02);

        // Expensive judgment runs last, via a structured-output judge agent.
        $this->judge()
            ->criteria('Answers the customer question without inventing policy.')
            ->minScore(7);
    }
}
```

**3. Edit the dataset.** One JSON object per line:

```jsonl
{"input": "What is your refund policy?", "expected": "30 days"}
{"messages": [{"role": "user", "content": "Hi, I ordered a lamp"}, {"role": "assistant", "content": "How can I help?"}, {"role": "user", "content": "Where is it?"}]}
```

`input` is the prompt. Or give `messages` — the final user turn becomes the prompt and the earlier turns are replayed as conversation context. `expected` is free-form reference data (`$row->expected()`); any other key lands in `$row->meta()`.

**4. Wire it up without spending a token:**

```
php artisan evals:run SupportQuality --dry-run
```

**5. Run it for real, then make that run your baseline:**

```
php artisan evals:run SupportQuality --baseline
```

**6. In CI, compare every change against the baseline:**

```
php artisan evals:run SupportQuality --compare=baseline --output=json
```

Exit codes: `0` passed, `1` the gate failed or rows regressed, `2` harness failure. The JSON document is stable and versioned — parse it, archive it, chart it.

## Datasets

| Constructor | Use |
|---|---|
| `Dataset::fromJsonl($path)` | The preferred format (streamed lazily) |
| `Dataset::fromCsv($path)` | Header row; `prompt` and `expected` columns by default — spreadsheets your non-dev teammates can edit |
| `Dataset::fromArray([...])` | Quick starts and tests |
| `Dataset::fromEloquent($query, fn ($model) => [...])` | Anything in your database |
| `Dataset::fromConversations(SupportAgent::class)` | **Real production traffic** from the SDK's conversation tables |

`fromConversations()` turns stored conversations into multi-turn rows: the latest user turn becomes the prompt, prior turns are replayed, and the reply your agent actually gave is exposed as `$row->expected()` — ideal for `judge()->comparedTo($row->expected())`. Refine with `->latest()`, `->take(50)`, `->where(...)`.

Rows are identified by a content hash (input + messages + expected), so the same logical row is tracked across runs even when the file is reordered.

## Assertions

All assertion helpers run against the **current sample** — no `$response` parameter needed. Every result records `expected` and `actual`. Any assertion can take `->gate()` (failure hard-fails the sample and skips judges) and `->weight(float)` (its share of the sample score).

**Content** — `assertContains`, `assertNotContains`, `assertContainsAnyOf`, `assertContainsAllOf`, `assertStartsWith`, `assertEndsWith`, `assertMatchesRegex`, `assertLengthBetween`, `assertWordCountBetween`, `assertNotEmpty`, `assertIsBritishSpelling`, `assertIsAmericanSpelling`

**Structure** — `assertValidJson`, `assertJsonHasKey`, `assertValidXml`, `assertXmlHasTag`; for structured-output agents: `assertOutputHasKey`, `assertOutputKey('score', fn ($v) => $v >= 1)`, `assertOutputKeyMatches`

**Agent behavior** (the reason this package exists — assertions against the full `AgentResponse`, not just text) — `assertToolCalled`, `assertToolNotCalled`, `assertToolCalledWith('lookup_order', ['id' => 7])`, `assertToolCallOrder([...])`, `assertStepsBelow`, `assertFinishReason(FinishReason::Stop)` (flags `Length`/`ContentFilter` as truncation/refusal), `assertNoPendingApprovals`

**Usage & cost** — `assertCostBelow`, `assertTokensBelow`, `assertCacheHitRateAbove`, `assertDurationBelow`, `assertModelUsed`, `assertProviderUsed`

**Safety pre-filters** (named honestly: wordlist and regex checks, not classifiers) — `assertContainsNoBlockedWords`, `assertNoObviousPII`

**Scalars** — `assertEquals`, `assertTrue`, `assertFalse`, `assertGreaterThan`, `assertLessThan`

**Custom** — implement `Vizra\Evals\Assertions\Assertion` and call `$this->assertWith(new MyAssertion(...))`.

## The judge

The judge is a `laravel/ai` structured-output agent — no response parsing, no regexes. Scores are 1–10 with mandatory reasoning, and both are persisted (`eval_assertion_results.judge_reasoning` is the debugging payload).

```php
$this->judge()
    ->criteria('Cites the actual policy; invents nothing.')
    ->minScore(7)
    ->dimensions(['accuracy' => 7, 'tone' => 6])   // per-dimension minimums
    ->weight(2.0);

// Pairwise comparison against a reference (e.g. the stored production reply):
$this->judge()->comparedTo($row->expected())->prefer('actual');
```

Configure the judge's provider/model in `config/evals.php` (or per-builder with `->provider()` / `->model()`). **Point the judge at a different model family than the agent under test** — models grade their own family's output leniently.

Judges are deferred: they run after all deterministic assertions, and are skipped entirely when a gate failed (configurable via `evals.judge.skip_on_gate_failure`).

**Don't trust an uncalibrated judge.** Feed it a labelled dataset (`output` + `human_score` or `human_verdict` per row) and check agreement:

```
php artisan evals:calibrate storage/labelled.jsonl --criteria="Correctness"
```

## Scoring model

- Deterministic assertions score 1.0/0.0; judge scores are normalized to 0–1; weights apply.
- A failed gate ⇒ the sample scores 0.0, no questions asked. Passing gates are preconditions, not quality signal — they're excluded from the mean.
- Row result = pass rate + score mean/stddev across its samples. Run result = aggregates across rows.
- Pass/fail is a *policy applied at run level*, not something decided per assertion:

```php
public function gatePolicy(): ?Gate
{
    return new Gate(minScore: 0.8, maxRegressions: 0);
}
```

CLI flags `--min-score`, `--min-pass-rate`, `--max-regressions` override per run.

## Comparing runs

```
php artisan evals:run SupportQuality --compare=baseline   # or a run id, or "latest"
php artisan evals:baseline 01JG...                        # promote any past run
```

Rows are joined across runs by content hash + provider/model combo. A row **regressed** if its pass rate dropped, or its mean score dropped by more than `evals.compare.epsilon` (default 0.05 — small score jitter is expected from a sampled system). `newly_failing` lists rows that were fully passing on the baseline. Regressions beyond `max_regressions` fail the run with exit code 1.

## Provider/model matrices

```php
public function across(): array
{
    return [
        ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'],
        ['provider' => 'openai', 'model' => 'gpt-5'],
    ];
}
```

Every combo runs the full dataset and shows up as its own series in results and comparisons.

## Cost tracking

Per-sample cost is computed from the SDK's `Usage` against the price table in `config/evals.php`. **The table is yours to maintain** — prices change and this package doesn't pretend to keep them current. Unknown models produce `null` costs and a single warning, never an error. Judge tokens are tracked separately (`judge_cost`).

## Concurrency

Rows × samples run concurrently through Laravel's `Concurrency` facade (default 5, `--concurrency=1` for sequential debugging). Assertions, judges, and persistence always run in the parent process. Dry runs are always sequential — agent fakes live in process memory.

## Testing without spending tokens

Two layers, both built on the SDK's own faking:

- **`--dry-run`** on `evals:run` fakes the target agent, the judge, and everything else the run might invoke — the whole suite executes offline. Use it to validate datasets, wiring, and assertions.
- **In your own tests**, fake the agent and (if you use `judge()`) the judge, then drive the Runner directly:

```php
SupportBot::fake(fn (string $prompt) => str_contains($prompt, 'France')
    ? 'Yes, we ship to France.'
    : 'Refunds within 30 days.');

Ai::fakeAgent(JudgeAgent::class, fn () => ['score' => 9, 'reasoning' => 'On policy.']);

$result = (new Runner(concurrencyOverride: 1))->run(new SupportBotQuality);

$this->assertSame(1.0, $result->passRate());
SupportBot::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'refund'));
```

Fakes accept canned strings, closures, or full `TextResponse` objects with real `Usage`/`Meta`/tool calls — so cost and tool assertions are testable offline too. Multi-turn rows work with a plain agent fake: when the target agent is faked, the runner prompts it directly (fake gateways never see message history), so your canned responses and `assertPrompted` checks behave the same for single- and multi-turn rows.

## Migrating from vizra-adk evaluations

The authoring model survives (class-per-eval, generator, `expected`/`actual`, per-row isolation), but the model changed: string in/string out became `AgentResponse` in, and single-run pass/fail became sampled, persisted, baseline-compared runs.

- `$agentName` + `Agent::run()` → `target()` returning a `laravel/ai` agent class.
- `preparePrompt()` → `transform(Row $row): Row` (optional; use `$row->withInput()` to keep row identity).
- `evaluateRow($row, string $response)` → `evaluate(Row $row, AgentResponse $response)`; assertion helpers no longer take the response.
- `assertNotToxic` → `assertContainsNoBlockedWords`; `assertNoPII` → `assertNoObviousPII` (same checks, honest names).
- **Removed:** `assertResponseHasPositiveSentiment`, `assertGrammarCorrect`, `assertReadabilityLevel` — keyword counting and hand-rolled Flesch-Kincaid measured nothing real. Each is one `judge()->criteria(...)` call away if you want it measured properly.
- CSV results output → database persistence + `--output=json`.
- `assertLlmJudge*` and its regex parsers → `judge()` on structured output.

## License

MIT.
