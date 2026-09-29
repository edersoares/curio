# Contributing

Thanks for your interest in Curio. Bug reports, feature ideas and pull requests are all welcome.

## Before you start

Open an [issue](https://github.com/edersoares/curio/issues) describing the bug or feature before starting significant work, so the approach can be discussed first. Small, obvious fixes (a typo, a broken link, a one-line bug with a test) don't need an issue — just send the pull request.

Curio is pre-1.0, so the query-string syntax and the `PaginateQuery` method surface are still allowed to change. If your change touches either, say so explicitly in the issue.

## Setting up

```bash
git clone git@github.com:edersoares/curio.git
cd curio
composer install
composer test
```

The `workbench/` directory is a runnable [Orchestra Testbench](https://packages.tools/testbench) application (`Author`, `Post`, `Comment` and `User` models with matching queries, requests, controllers and resources). The whole suite runs against it, and it's the fastest way to see the feature set wired together end to end.

By default the suite runs on SQLite. To run it against PostgreSQL — required if you're touching the `trgm`/`unaccent` search modes or `sort=field:@unaccent` — create a `testbench.yaml` at the repository root (it's git-ignored):

```yaml
env:
  STUDIO_WORKBENCH_NAMESPACE: Dex\Laravel\Curio\Workbench
  DB_CONNECTION: pgsql
  DB_URL: pgsql://postgres:postgres@localhost:5432/curio

providers:
  - Dex\Laravel\Curio\Workbench\App\Providers\WorkbenchServiceProvider

seeders:
  - Dex\Laravel\Curio\Workbench\Database\Seeders\DatabaseSeeder
```

## Before opening a pull request

```bash
composer test     # the full suite
composer analyse  # PHPStan, level max, must stay at zero errors
composer format   # Laravel Pint (PSR-12)
```

All three are enforced in CI. Pint also runs automatically on push and commits its own fixes, so don't worry if you forget it.

For a bug fix, include a failing test that demonstrates the bug. Tests mirror the source: `tests/UseCase/*Test.php` covers each `Language` parser/validator individually, and `tests/Extensions/PaginatorTest.php` covers the end-to-end HTTP pipeline.

### Mutation testing

`composer mutate` rewrites small pieces of `src/` — flipping a comparison, dropping a guard, changing an integer — and reruns the tests covering each change. A surviving mutant is a line the suite doesn't actually hold in place. It runs weekly against `main` rather than on every pull request, because a full run takes tens of minutes, but it's worth running scoped to whatever you changed:

```bash
vendor/bin/pest --mutate --covered-only --parallel --path=src/Language/Filtering.php
```

## Pull requests

- Target `main`.
- One logical change per pull request.
- Describe what changed and why — the "why" is the part that isn't in the diff.
- If the change affects behaviour a user can observe, update `README.md` and the matching page under `docs/`. They're checked against the real implementation, so an example that doesn't run is a bug.

## Security

Please don't open a public issue for a security vulnerability — see [SECURITY.md](.github/SECURITY.md).
