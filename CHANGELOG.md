# Changelog

All notable changes to `vizra/evals` are documented here.

This project follows [semantic versioning](https://semver.org). While on 0.x,
breaking changes may land in a minor release — they will always be called out
here.

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
