# Changelog

All notable changes to `vizra/evals` are documented here.

This project follows [semantic versioning](https://semver.org). While on 0.x,
breaking changes may land in a minor release — they will always be called out
here.

## 0.3.0 — 2026-08-13

The judge now reads the same instructions as the agent it is grading, and a
run that could not have failed says so.

### Added

- The judge is given the target agent's system prompt automatically. Without
  it, an agent doing exactly what it was told to do reads as invention: on an
  11-row suite, every failure was the agent offering to hand a question to a
  human — which its instructions require — being marked "invents a
  capability". The only workaround was pasting the whole system prompt into
  every criteria and keeping the two in step by hand. Resolved once per
  evaluation, not per sample, and skipped for a `Closure` target or an agent
  that will not resolve.
- `withoutTargetInstructions()` on the judge builder, for criteria that are
  deliberately about the response alone — tone, reading age, format — where
  the system prompt would only bias the grade or cost tokens.
- A warning when a run passes a gate that asserts nothing. With no
  `minScore`, no `minPassRate` and no comparison, every run passes, including
  one where every sample failed — which reads as a green build to anyone who
  wired it into CI without setting a threshold.
- A warning when `--compare` is given a run of one sample per row. There is no
  distribution to compare, so ordinary run-to-run variance reports as
  regression: three rows were flagged whose scores had merely moved 100→80 on
  identical inputs. Warned rather than refused — the numbers are real even
  when the verdict is not trustworthy.

### Changed

- **Judged scores will move.** The judge sees the target's instructions on
  every `judge()` call it did not previously see, so a suite re-run after
  upgrading may score differently — generally higher, where failures were the
  judge not knowing what the agent was permitted to do. Re-baseline before
  reading a comparison across the upgrade.
- `make:eval` writes a starter dataset of four rows rather than two, showing
  `expected.any` and a row the agent should refuse. A dataset of questions the
  agent can obviously answer proves only that the API is up.
- The generated evaluation prefers `expected.any` where the dataset provides
  it, and points `target()` at `app/Ai/Agents`, which is where
  `make:agent` actually writes agents. Matching model prose on one exact
  string fails on an en dash.
- `.claude` is kept out of the release tarball. It holds this package's own
  maintainer skills; shipped, a consumer's coding agent loaded them on startup
  and offered to cut *our* releases.

## 0.2.1 — 2026-08-13

A dataset row's own keys now survive the round trip to the hosted dashboard.

### Fixed

- `meta` was missing from every sample in the cloud payload. The dashboard
  rebuilds an editable dataset from those samples and hands it back to the
  runner when someone clicks Run, so a rebuilt row arrived without the keys the
  evaluation branches on — every `$row->meta(...)` check silently took the
  other branch. A suite scoring 95% locally errored on all of them when
  triggered from the dashboard. No migration: the column already existed and
  was already being written, only the payload left it out.

## 0.2.0 — 2026-08-12

Prices now come from a table you sync, and multi-turn rows and judge spend
reach the cloud intact.

### Added

- `evals:sync-pricing` pulls published model prices from vizra.ai and writes
  `config/evals-pricing.php`, so runs stay offline and CI prices a run exactly
  as the laptop that wrote the file does. It prints what moved, refuses to
  overwrite a working table with an empty or unreachable answer, and rebuilds a
  cached config so the new prices are actually live.
- `pricing_overrides` — a negotiated rate, a self-hosted model, or a provider
  nobody publishes numbers for. Beats both the synced and the bundled table.
- A warning when prices came from a synced table older than sixty days, and one
  when they came from the bundled fallback. One line per process, each naming
  `evals:sync-pricing`. Silent for a current sync and for an override.
- `evals:ping`, which proves the Vizra Cloud connection in about two seconds
  before the evaluation is written — no tokens, no suite, no trend. It names the
  project it reached, the key it used, and whether anything has ever arrived.
- `messages` on `eval_row_results` and in the cloud payload, so a multi-turn
  row's earlier turns survive the round trip instead of arriving incomplete,
  uneditable and blocked from export. Run `php artisan migrate` after upgrading.
- `judge_usage`, `judge_model` and `judge_provider` on reported assertions. A
  judged suite can spend far more on grading than on the thing being graded, and
  only the agent's tokens were being sent.
- `AGENTS.md`, with its assertion list generated from source and held there by a
  test, so a coding agent reads what the package actually offers.
- `CONTRIBUTING.md` and `SECURITY.md`.

### Changed

- The bundled price table in `config/evals.php` is now the last of three layers,
  behind overrides and the synced file. `claude-sonnet-5` is corrected from
  $3/$15 to $2/$10 — every cost estimate and `costBelow()` gate involving Sonnet
  was 50% high.
- `cache_read` and `cache_write` fall back to the input rate rather than to
  nothing, which is what providers without a cache tier charge.
- Dev requirements accept Pest 4 alongside Pest 5.

## 0.1.0 — 2026-08-06

First public release.

### Added

- `Evaluation` classes with datasets from arrays, CSV, JSONL and conversations.
- Assertions across content, structure, tool use, safety, and usage/cost.
- LLM-as-judge scoring via `judge()`, with a configurable judge agent and model.
- Matrix runs over providers and models with `across()`.
- Baseline comparison and per-row regression detection.
- Run-level gates (`minScore`, `minPassRate`, `maxRegressions`) with an exit
  code CI can act on.
- `evals:run`, `make:eval`, `evals:baseline`, `evals:calibrate`, `evals:runner`,
  `evals:sync-pricing`, `evals:ping`.
- Pest integration: write an eval as a test with `toPassEval()`.
- Dry runs against faked agents, so an eval can be developed without spending
  tokens.
- Vizra Cloud reporting: set `VIZRA_CLOUD_KEY` and finished runs are pushed to
  the hosted dashboard, including the gate's verdict. Reporting never changes a
  run's outcome.
- Cloud runner: `evals:runner` collects runs requested from the dashboard and
  executes them inside your app, over outbound HTTP only — no inbound route,
  hostname or firewall rule. It extends its lease as the run progresses, so a
  long suite is not reclaimed mid-run.
