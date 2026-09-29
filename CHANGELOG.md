# Changelog

All notable changes to `curio` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While Curio is pre-1.0, the query-string syntax and the `PaginateQuery` method surface may still change between minor versions.

## 0.1.0 - 2026-09-29

First public release.

### Added

- **A structured query layer over Eloquent**, driven by one query string per concern. A `PaginateQuery` subclass declares what is allowed (`filterBy()`, `sortBy()`, `searchBy()`, `includeBy()`, `selectBy()`, `aggregateBy()`, `castBy()`, `replaceBy()` and the matching `default*()` methods); a `FormRequest` using the `PaginateRequest` trait turns that declaration into validation rules and exposes `paginate(Builder $builder)`. Anything not on the allow-list fails validation with a 422 rather than reaching the database.
- **Filtering** (`filter=`) — equality, `IN` lists, comparison operators, `LIKE` wildcards, full and partial ranges, `NULL`/`NOT NULL`, negation of any of the above, relation existence (`has:relation`, with an optional `@count` comparison), filtering through a relation (`relation.field:value`), and JSON support (`field->path:value` and `whereJsonContains`). Values are coerced to the type each field declares in `filterBy()`, so a `string` field can be filtered by a number-shaped value (`code:00123`) without losing its leading zeros.
- **Free-text search** — rides inside `filter=` rather than taking a query parameter of its own, matched against the fields listed in `searchBy()`. Three modes: `like` (portable, the default), and `trgm` and `unaccent` for PostgreSQL, with a configurable similarity threshold.
- **Sorting** (`sort=`) — space-separated fields, `-` for descending, sorting by a related model's column (through a correlated subquery, so sorting never duplicates or drops rows), and a per-field `:@unaccent` modifier (PostgreSQL).
- **Including** (`include=`) — eager loading, scoped eager loading (`:@filter(...)`, `:@only(...)`, `:@limit(n)`), and relation aggregates (`relation:@count`, `relation:@sum(column)`, `relation:@count(distinct:column)`, …). Nesting depth is bounded by `config('curio.include.max_depth')`, overridable per query with `maxIncludeDepth()`.
- **Selecting** (`select=`) — column selection plus date-part transforms (`field:@year`/`@month`/`@day`/`@hour`/`@minute`/`@second`, with optional `@alias`).
- **Aggregating** (`aggregate=`) — `GROUP BY` with `COUNT`/`SUM`/`AVG`/`MIN`/`MAX`, `@alias`, `@having` and `@default`, date-part transforms combinable with `@group`, and `:@join` to group by a related table's column through a real `LEFT JOIN`. Mutually exclusive with `select=`.
- **Casting** (`cast=`) — post-fetch, pure-PHP transforms resolved against a `castMutator()` registry, written to a separate `casts` attribute instead of overwriting the model's own.
- **Presets** (`@name`) — reusable, named snippets registered via `Preset::register()`/`registerMany()`, expanded wherever they appear in any of the six query strings, including recursively and with a parameter (`@year:2025`).
- **Variables** (`$name`, `$name!`) — request-context references expanded inside `default*()` values and incoming query strings; the `!` form fails validation when the named input is absent.
- **Field aliases** (`replaceBy()`) — URL-friendly names mapped onto real column names, applied to `filter=`, `sort=`, `select=`, `aggregate=` and `cast=` (not `include=`, whose keys name relations).
- **`Model::curio()`** — a fluent DSL on any Eloquent builder (`Model::curio()->filter(...)->sort(...)->get()`) via the `YourCuriosity` trait. It runs the same pipeline as the HTTP path, validating against the allow-lists declared on the model itself (`filterBy()`, `sortBy()`, `selectBy()`, …) instead of a `PaginateQuery`.
- **`AggregateResource`** — a `JsonResource` trait that surfaces aggregate, include-aggregate and cast values under their own `aggregates`/`casts` response keys rather than mixed in with the model's real attributes.
- **Events** — `ApplyPending` and `ApplyToken` are ordinary Laravel events dispatched before any token reaches the builder, so applications can hook in their own listeners for auditing, metrics, or to rewrite a request. They are a hook, not the mechanism: the steps that validate and apply a query are called directly by `Pipeline`, so filters and `defaultFilter()` still apply under `Event::fake()`.
- **Limits** — how much one request may ask for is bounded: clauses and relation depth in `filter=`, fields in `sort=`, items in `aggregate=`, relations and nesting depth in `include=`. Each limit has a config default and a `PaginateQuery` method to override it per endpoint, and exceeding one is a 422 under the parameter's own key.
- **Configuration** (`config/curio.php`) — every query parameter name is renameable, plus pagination defaults, request limits and search mode/threshold. Defaults are merged by the service provider; there is no `vendor:publish` step.
