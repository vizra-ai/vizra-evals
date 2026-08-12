# Contributing

## Getting set up

```bash
composer install
composer test      # 197 tests, no network and no API keys required
composer lint      # Pint
```

The test suite uses the Laravel AI SDK's fakes throughout, so nothing here
calls a model or spends a token. If a test you add needs a real provider, it
belongs somewhere else.

## Supported versions

`composer.json` is the source of truth, and CI tests the matrix it advertises:
PHP 8.4+, Laravel 12 and 13. Pest 4 on Laravel 12, Pest 5 on Laravel 13 — the
`suggest` block says so, and the dev constraint allows both.

If you widen or narrow a constraint, update `.github/workflows/tests.yml` in
the same commit. A matrix that does not match the manifest is how a package
ends up advertising a version it has never once been built against.

## Working on vizra/evals-ui at the same time

Register the path repository **globally** from the `vizra/evals-ui` side, so it
stays on your machine:

```bash
composer config --global repositories.vizra-evals \
  '{"type":"path","url":"/absolute/path/to/vizra-evals","options":{"symlink":true,"versions":{"vizra/evals":"0.1.0"}}}'
```

The `versions` map is not optional — a path repository reports `dev-<branch>`
otherwise, which does not satisfy `^0.1`, and a path repo outranks Packagist so
Composer refuses rather than falling back. Undo it with
`composer config --global --unset repositories.vizra-evals`.

Do not add a `repositories` block to either package's `composer.json`. A path
URL that does not exist is a fatal error in Composer rather than a warning, so
a committed one breaks CI and every contributor who clones a single repo.

## Pull requests

- One change per PR, with a test that fails without it.
- Follow the surrounding comment style: explain *why*, especially where the
  obvious implementation is wrong for a reason that is not obvious.
- Run `composer lint` before pushing.

## Reporting bugs

Open an issue with the versions involved, a minimal eval that reproduces it,
and what you expected instead. For anything security-related, see
[SECURITY.md](SECURITY.md) — please do not open a public issue.
