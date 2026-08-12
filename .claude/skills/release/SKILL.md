---
name: release
description: Cut a new release of this package (vizra/evals or vizra/evals-ui) — changelog, tests, tag, GitHub release, Packagist verification. Use when asked to release, cut or tag a new version, bump the version, or publish an update of this package.
---

# Releasing a new version

This repo is one of two coupled Packagist packages that live side by side on disk:

| repo | Packagist name | depends on |
|---|---|---|
| `vizra-evals` | `vizra/evals` | — |
| `vizra-evals-ui` | `vizra/evals-ui` | `vizra/evals` |

Identify which one you are in from the `name` field of `composer.json`. The sibling repo is at `../vizra-evals` or `../vizra-evals-ui`.

## The one rule that matters

A version IS a pushed git tag (`vX.Y.Z`), nothing else. Never add a `version` field to `composer.json` — Packagist derives versions from tags, and a `version` field that disagrees with a tag makes Packagist skip that release. There is nothing to update on Packagist itself; its webhook picks up pushed tags within seconds.

## Decide the version number

Semver, currently on 0.x: bug fixes → patch (`v0.1.1`); new features or breaking changes → minor (`v0.2.0`). Breaking changes MUST be called out in the changelog entry. Confirm the chosen number with the user before tagging.

## Cross-package coupling — check every time

- **Minor bump of `vizra/evals`** (e.g. 0.1.x → 0.2.0): evals-ui's `"vizra/evals": "^0.1"` constraint will NOT allow it (`^0.1` means `<0.2.0` in Composer). Widen the constraint in `../vizra-evals-ui/composer.json` (e.g. `"^0.1|^0.2"`) and plan an evals-ui release as well.
- **Any `vizra/evals` release**: bump the `versions` pin for `vizra/evals` inside evals-ui's `repositories` path block to the new version. It is local-dev metadata only (consumers never see it), but keep it in step.
- **Releasing evals-ui**: confirm its `vizra/evals` constraint allows the latest version published on Packagist.
- **Releasing both**: always `vizra/evals` first, then `vizra/evals-ui`.

## Steps

1. **Preflight**: on `main`, clean working tree, `git pull`. Abort if any of these fail.
2. **Quality gate**: `vendor/bin/pint --test` (check only — plain `pint` rewrites files and dirties the tree) and `composer test`. The test suites run offline against SDK fakes — no API keys are needed. Abort on failure unless the user explicitly waives it.
3. **Changelog**: `CHANGELOG.md` must gain its section BEFORE tagging, in the house format: `## X.Y.Z — YYYY-MM-DD` (em dash), an optional one-line summary, then `### Added` / `### Changed` / `### Fixed` bullet lists, hard-wrapped near 80 columns. If the entry is missing, draft it from `git log <last-tag>..HEAD --oneline` and get the user's approval on the wording before committing.
4. **Commit** the changelog (plus any constraint/pin edits from the coupling checks) to `main` and push.
5. **Tag and push**: `git tag -a vX.Y.Z -m "vX.Y.Z"` then `git push origin vX.Y.Z`. The version is now live — Packagist indexes it from the webhook within seconds.
6. **GitHub release**: `gh release create vX.Y.Z --title vX.Y.Z --notes-file <notes>` where the notes are the new changelog section WITHOUT its `##` heading, plus a final line:
   `Full changelog: [CHANGELOG.md](https://github.com/vizra-ai/<repo>/blob/main/CHANGELOG.md)`
   Publish as a normal release — never mark 0.x releases as pre-release; leave it as latest. If `gh` is unavailable, point the user at `https://github.com/vizra-ai/<repo>/releases/new`, choosing the just-pushed tag, with the same title and notes.
7. **Verify**: fetch `https://repo.packagist.org/p2/vizra/evals.json` (or `.../vizra/evals-ui.json`) and confirm the new version is listed — allow up to a minute. Report the Packagist package URL and the GitHub release URL.

## Never

- Add `version` to `composer.json`.
- Tag without a matching changelog entry.
- Move, re-point, or delete an already-pushed tag — cut a new patch instead.
- Release evals-ui requiring a `vizra/evals` version that is not on Packagist yet.
