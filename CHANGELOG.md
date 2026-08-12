# Changelog

All notable changes to `vizra/evals` are documented here.

This project follows [semantic versioning](https://semver.org). While on 0.x,
breaking changes may land in a minor release — they will always be called out
here.

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
