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

`composer test` always runs on an in-memory SQLite database — `tests/TestCase.php` selects it, so there is nothing to configure and a `testbench.yaml` has no effect on it. What only PostgreSQL can run — the `trgm`/`unaccent` search modes, `sort=field:@unaccent`, `EXTRACT()`, `jsonb` containment — has a suite of its own under `tests/Postgres`, which executes every query against a real server. Run it whenever you touch any of those:

```bash
docker run --rm -d --name curio-postgres -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=curio -p 5432:5432 postgres:17-alpine

CURIO_POSTGRES_URL=pgsql://postgres:postgres@127.0.0.1:5432/curio composer test:postgres
```

A feature that only works on PostgreSQL needs a test there: a string assertion on the generated SQL proves Curio built it, not that the database accepts it.

## Before opening a pull request

```bash
composer test     # the full suite
composer test:postgres # if you touched anything PostgreSQL-specific
composer analyse  # PHPStan, level max, must stay at zero errors
composer format   # Laravel Pint (PSR-12)
```

The tests and PHPStan run in CI on every push. Pint runs there too, on every branch except `main`, and commits its own fixes to your branch — so don't worry if you forget it, but pull before pushing again.

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
