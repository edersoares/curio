# Laravel Curio

[![Latest Version on Packagist](https://img.shields.io/packagist/v/dex/curio.svg?style=flat-square)](https://packagist.org/packages/dex/curio)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/edersoares/curio/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/edersoares/curio/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/edersoares/curio/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/edersoares/curio/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/dex/curio.svg?style=flat-square)](https://packagist.org/packages/dex/curio)

A curious way to query Eloquent — turn a single query string into filtering, sorting, eager loading, column selection, aggregation and pagination, with validation baked in.

```
GET /api/posts?filter=title:*laravel* published_at:>=2025-01-01&sort=-published_at&include=author comments:@count&page=1&size=25
```

## Requirements

- PHP ^8.4
- Laravel `^12.0 || ^13.0` — Curio targets a full Laravel application, not the standalone Illuminate components: `PaginateRequest` builds on `Illuminate\Foundation\Http\FormRequest`, which only ships with `laravel/framework`.

## Installation

```bash
composer require dex/curio
```

The package auto-registers `Dex\Laravel\Curio\Providers\CurioServiceProvider`. No further setup is required — sensible defaults are merged from `config/curio.php`. To override any default, add your own `config/curio.php` in the app with just the sections you want to change (see [Configuration](#configuration)).

## Quick start

Every endpoint is built from three pieces: a `PaginateQuery` (what's allowed), a `FormRequest` (wires the query to validation), and a controller that calls `$request->paginate($builder)`.

### 1. Describe what's queryable

```php
use Dex\Laravel\Curio\Query\PaginateQuery;

class PostQuery extends PaginateQuery
{
    public function filterBy(): array
    {
        return [
            'title' => ['string'],
            'published_at' => ['date'],
            'author_id' => ['integer'],
        ];
    }

    public function sortBy(): array
    {
        return ['id', 'title', 'published_at', 'author.name'];
    }

    public function includeBy(): array
    {
        return [
            'author' => AuthorQuery::class,
            'comments' => CommentQuery::class,
        ];
    }

    public function defaultSort(): string
    {
        return '-published_at';
    }
}
```

### 2. Wire it to a `FormRequest`

```php
use Dex\Laravel\Curio\Extensions\PaginateRequest;
use Illuminate\Foundation\Http\FormRequest;

class PostPaginateRequest extends FormRequest
{
    use PaginateRequest;

    public function getPaginateQuery(): string
    {
        return PostQuery::class;
    }
}
```

The `PaginateRequest` trait generates the request's validation `rules()` from `PostQuery` automatically (allowed filter fields, sort fields, include relations, aggregate fields, cast fields, `page`, `size`), and exposes `paginate(Builder $builder)`.

Override `additionalRules(): array` on the request to merge in extra validation rules (e.g. for other, non-`curio` inputs on the same endpoint) alongside the generated ones.

### 3. Paginate in the controller

```php
class PostPaginateController
{
    public function __invoke(PostPaginateRequest $request): PostResourceCollection
    {
        return new PostResourceCollection(
            $request->paginate(Post::query())
        );
    }
}
```

```php
Route::get('posts', PostPaginateController::class);
```

That's it — `filter`, `sort`, `include`, `select`, `aggregate`, `cast`, `page` and `size` query parameters are now all parsed, validated and applied for you, and the result is a standard `LengthAwarePaginator`.

## Query string reference

Query parameter names (`filter`, `sort`, `include`, `select`, `aggregate`, `cast`, `page`, `size`) are configurable in `config/curio.php`. Free text search has no query parameter of its own — it rides inside `filter=` (see [Search (free text)](#search-free-text)).

### Filtering (`filter`)

Only fields declared in `filterBy()` (or a valid dotted relation declared in `includeBy()`) may be filtered — anything else fails validation with a 422.

| Pattern                     | SQL                                                                                                                                                                           |
|-----------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `field:value`               | `WHERE field = value`                                                                                                                                                         |
| `field:a,b`                 | `WHERE field IN (a, b)`                                                                                                                                                       |
| `field:>=value`             | `WHERE field >= value` (also `>`, `<`, `<=`, `!=`)                                                                                                                            |
| `field:*value*`             | `WHERE field LIKE '%value%'`                                                                                                                                                  |
| `field:a..b`                | `WHERE field BETWEEN a AND b`                                                                                                                                                 |
| `field:a..` / `field:..b`   | `WHERE field >= a` / `WHERE field <= b` (partial range)                                                                                                                       |
| `-field:a..` / `-field:..b` | `WHERE field < a` / `WHERE field > b` (negated partial range flips the comparison instead of wrapping it in `NOT`)                                                            |
| `-field:value`              | Negates (`!=`, `NOT IN`, `NOT LIKE`, `NOT BETWEEN`, `<`, `>`)                                                                                                                 |
| `field:null`                | `WHERE field IS NULL`                                                                                                                                                         |
| `field:filled`              | `WHERE field IS NOT NULL`                                                                                                                                                     |
| `has:relation`              | `WHERE EXISTS (relation)`                                                                                                                                                     |
| `-has:relation`             | `WHERE (SELECT COUNT(*) ...) = 0`                                                                                                                                             |
| `has:relation:@count(>=3)`  | `whereHas` with an operator and count (also `>`, `<=`, `<`, `=`)                                                                                                              |
| `-has:relation:@count(>=3)` | Negates the count comparison itself (`>=` ↔ `<`, `>` ↔ `<=`) rather than wrapping it in `NOT` — `WHERE (SELECT COUNT(*) ...) < 3`                                            |
| `relation.field:value`      | Filters through `whereHas` on relation (nested relations supported: `relation.nested.field:value`)                                                                            |
| `field->path:value`         | `WHERE json_extract(field, '$.path') = value` — JSON path filter, nested paths supported (`field->a->b:value`); every path is a field of its own in `filterBy()`              |
| `field:{"a":1}`             | `whereJsonContains(field, ['a' => 1])` — JSON containment on the whole column (also accepts a JSON array); a value that isn't valid JSON is treated as a plain string instead |
| `-field:{"a":1}`            | `whereJsonDoesntContain(field, ['a' => 1])`                                                                                                                                   |
| `@preset-name`              | Expands a registered filter preset                                                                                                                                            |
| free text (`laravel php`)   | Fanned out across `searchBy()` fields — see [Search (free text)](#search-free-text)                                                                                          |
| `field:"a value"`           | Quote a value that contains spaces                                                                                                                                            |

Multiple clauses are space-separated: `filter=title:*laravel* -author_id:1,2 published_at:>=2025-01-01`.

Values are coerced to the type the field declares in `filterBy()`: a `string` field always receives a string (`name:00123` and `name:true` match the text `00123` and `true`), and an `integer`/`numeric`/`decimal` field receives a number for any numeric string (`ranking:007` is `7`). A field that declares neither gets a best guess from how the value is written — `true`/`false` become booleans, and a number in its canonical form becomes an `int`/`float`, while `00123` or `1e3` stay strings. `null` always means `NULL`, whatever the field's type. Comma-separated lists (`field:a,b`) become an `IN` clause; each item can itself be quoted (`field:"a b",c`). Quotes protect spaces, not commas: a comma always separates list items, so a single value that contains one (`field:"a,b"`) can't be expressed and fails validation.

A JSON path has to be declared in `filterBy()` exactly as it is sent — `'payload->user' => ['integer']` allows `payload->user:1` and nothing else under `payload`. Declaring the column alone (`'payload'`) allows containment (`payload:{"a":1}`), not paths into it.

#### Presets

Register reusable, named snippets — expanded wherever a free-standing `@name` appears in **any** of the six DSL strings (`filter`, `sort`, `select`, `aggregate`, `include`, `cast`), typically from a service provider's `boot()`:

```php
use Dex\Laravel\Curio\Language\Preset;

Preset::register('active', 'status:active');
Preset::register('vip', '@active plan:premium'); // presets can reference other presets
Preset::register('year', fn (string $year) => "created_at:{$year}-01-01..{$year}-12-31");
```

```
GET /api/authors?filter=@vip @year:2025
```

Register several at once with `Preset::registerMany(['active' => 'status:active', 'vip' => '@active plan:premium'])`. Referencing a preset that doesn't exist leaves the `@name` token untouched (no error) — it is then read like any other token, which in `filter=` means free text; a preset that references itself, directly or through another preset, throws an `InvalidArgumentException` when it's registered — closure-based presets are exempt from that check, which is exactly how the package's own built-in `count` preset (see [Aggregating](#aggregating-aggregate)) safely re-emits `@count`-shaped output without tripping the cycle detector. `Preset::clear()` resets your own registered presets but leaves the built-in `count` preset in place.

#### Variables

Through the HTTP pipeline (a `PaginateRequest`-based endpoint), `default*()` return values **and** the incoming `filter`/`select`/`sort`/`aggregate`/`include`/`cast` query values are all resolved against the rest of the request's input before parsing: `$name` (optional — resolves to an empty string if absent) or `$name!` (required — throws a validation error if absent):

```php
public function defaultFilter(): string
{
    return 'author_id:$author_id!';
}
```

```
GET /api/posts?author_id=42          # defaultFilter() resolves to 'author_id:42'
GET /api/posts?filter=title:$term    # a client-supplied string can reference other input too
```

A `$name` is client input: it is filled by whatever the request sent under that key and substituted before parsing, so its value is read as query syntax (`?author_id=42 status:draft` adds a clause of its own). It can add constraints, never pin one down — don't use a variable for a tenant id or the authenticated user; constrain the `Builder` you pass to `paginate()` instead. An optional `$name` that is absent leaves a key with no value (`title:`), which is read as free text rather than as a filter.

This variable resolution is specific to the HTTP pipeline (`Extensions\Paginator`) — the fluent `Model::curio()`/`Curio` path (see [Fluent querying](#fluent-querying-without-a-paginatequery)) parses whatever string you pass it as-is, with no `$name` substitution.

#### Search (free text)

Any token in `filter=` that isn't a valid `key:value` clause (a bare word, a quoted phrase) is collected into one free-text search and fanned out with `OR` across every field declared in `searchBy()` — declare it on your `PaginateQuery`:

```php
public function searchBy(): array
{
    return ['title', 'content'];
}
```

`searchBy()` takes columns of the model's own table — a relation column (`author.name`) is not joined in and fails at the database. `searchBy()` returning `[]` (the default) silently discards free text instead of erroring. Three matching modes, set via `searchMode()` (defaults to `config('curio.search.mode')`):

| Mode | SQL | Notes |
|------|-----|-------|
| `like` (default) | `field LIKE '%value%'` | Works on any driver |
| `trgm` | `similarity(unaccent(field), unaccent('value')) > threshold` | PostgreSQL only — requires the `pg_trgm` and `unaccent` extensions; threshold from `searchSimilarityThreshold()` (defaults to `config('curio.search.similarity_threshold')`, `0.3`) |
| `unaccent` | `unaccent(lower(field)) LIKE unaccent(lower('%value%'))` | PostgreSQL only — accent-insensitive substring match, no `pg_trgm` needed |

```php
public function searchMode(): string
{
    return 'trgm';
}
```

### Sorting (`sort`)

Space-separated field list, prefix `-` for descending: `sort=-published_at title`. Only fields declared in `sortBy()` are allowed. Sorting by a related model's column (`author.name`) is applied through a correlated subquery rather than a `JOIN`, so sorting never changes which rows come back: a parent is returned once however many related rows it has, and a parent with no related row is kept (its sort value is `NULL`). The subquery carries the relation's own constraints and ordering — `latestPost.title` sorts by the latest post's title — and only one relation level is supported (`relation.column`). A relation column can't be sorted alongside `aggregate=`. Adding a per-field `:@unaccent` modifier (e.g. `sort=email:@unaccent`) orders by `unaccent(lower(field))` instead — PostgreSQL only.

### Including (`include`)

Space-separated relation list. Only relations declared in `includeBy()` (mapped to their own `PaginateQuery` class) are allowed.

| Pattern                            | Effect                                                        |
|------------------------------------|----------------------------------------------------------------|
| `relation`                         | Eager loads the relation                                       |
| `relation:@filter(expr)`           | Eager loads and constrains the parent query via `whereHas`     |
| `relation:@only(expr)`             | Eager loads, filtering only the loaded relation rows           |
| `relation:@limit(n)`               | Eager loads, limiting each parent to at most `n` related rows  |
| `relation:@filter(expr):@limit(n)` | Combines `@filter` and `@limit` (in that order only)           |
| `relation:@only(expr):@limit(n)`   | Combines `@only` and `@limit` (in that order only)             |
| `relation:@count`                  | `withCount('relation')`                                        |
| `relation:@sum(column)`            | `withSum('relation', 'column')` (also `@avg`, `@min`, `@max`)  |
| `relation:@count(distinct:column)` | `COUNT(DISTINCT column)` on the relation                       |
| `relation:@count:@alias(name)`     | Aliases the aggregate column                                   |
| `relation:@count:@only(expr)`      | Filters the rows counted/aggregated                            |

The `expr` inside `@filter(...)` / `@only(...)` is a normal filter string, validated against the related `PaginateQuery`'s `filterBy()` — including free text, which searches the related query's own `searchBy()` fields exactly like a top-level `filter=` would (e.g. `include=author:@filter(ada lovelace)` searches `AuthorQuery::searchBy()` inside the `author` relation's subquery).

`@sum`/`@avg`/`@min`/`@max` always require a column argument (`@sum(column)`). `@count` takes no argument (`COUNT(*)`) or `distinct:column` (`COUNT(DISTINCT column)`); `distinct` is not supported on the other aggregators.

`@limit(n)` is only supported on relations that return multiple rows per parent (`HasMany`, `HasManyThrough`, `MorphMany`, `BelongsToMany`, `MorphToMany`, and their singular `HasOne`/`HasOneThrough`/`MorphOne` counterparts) — using it on a `BelongsTo`/`MorphTo` relation throws a validation error, since those return a single related row per parent and limiting would apply a single global limit across all parents' results instead. `@limit(n)` does not itself guarantee row order — pair it with a relation that defines its own ordering (e.g. `Author::latestPost()` uses `->hasOne(Post::class, 'author_id')->latest()`) if you need deterministic "first N" / "latest N" semantics. `@limit(...)` must come after `@filter(...)`/`@only(...)`, not before — in the other order (`relation:@limit(2):@filter(expr)`) nothing is rejected, but both modifiers are ignored and the relation is eager loaded whole.

An `include=` item may go through at most `maxIncludeDepth()` relations (`posts.comments.author` is 3, the default from `config('curio.include.max_depth')`); a deeper chain fails validation.

### Selecting (`select`)

Space-separated column list, restricting the returned columns: `select=id title published_at`. A relation-scoped field (`author.name`) is validated against `selectBy()` like any other field, but on its own it doesn't add a column to the query or eager-load the relation (sent alone, the query selects every column) — pair it with `include=author` if you actually want that relation loaded.

Columns also support a date-part transform:

| Pattern                                                               | Effect                                                               |
|-----------------------------------------------------------------------|-------------------------------------------------------------------------|
| `field:@month` / `@year` / `@day` / `@hour` / `@minute` / `@second`   | Extracts a date part (portable across MySQL, PostgreSQL and SQLite) |
| `field:@month:@alias(name)`                                           | Aliases the resulting column (defaults to the field name itself)     |

Give a date part an `@alias` when the field has a date cast on the model (`created_at`, `updated_at`, anything in `casts()`). The default alias is the field's own name, so the model casts the extracted number back into a date: `created_at:@year` comes out of the database as `2026` and out of `$model->toArray()` as `1970-01-01T00:33:46Z`. `created_at:@year:@alias(year)` avoids it.

Aggregation (`COUNT`/`SUM`/`AVG`/`MIN`/`MAX`, grouping, `HAVING`) is a separate, mutually exclusive parameter — see below.

### Aggregating (`aggregate`)

Space-separated items, like every other parameter — a comma is never an item separator, because it already means something inside a value (an `IN` list, e.g. a `@having(expr)` expression). `aggregate` fully replaces `select` for that request: sending both `select` and `aggregate` fails validation with a 422.

Every item must carry one role token — a bare field with no `@` token is invalid, since an unmarked column alongside `GROUP BY` is invalid SQL on Postgres:

| Pattern                                                             | Meaning                                                                      |
|---------------------------------------------------------------------|------------------------------------------------------------------------------|
| `field:@group`                                                      | Group-by key, also selected as a passthrough column                          |
| `field:@group:@alias(name)`                                         | Same, renamed in the output                                                  |
| `field:@month` / `@year` / `@day` / `@hour` / `@minute` / `@second` | Date-part transform, selected but not grouped                                |
| `field:@month:@group[:@alias(name)]`                                | Date-part transform **and** group-by key (`@group`/`@alias` in either order) |
| `field:@count` / `@sum` / `@avg` / `@min` / `@max`                  | Aggregate function (`*` only valid with `@count`)                            |
| `field:@sum:@alias(name)`                                           | Aliased aggregate (defaults to `field_function`, e.g. `views_sum`)           |
| `field:@sum:@having(expr)`                                          | `HAVING` condition on this aggregate                                         |
| `field:@sum:@alias(name):@having(expr)`                             | Both, either order                                                           |
| `@count`                                                            | Shorthand for `*:@count` — the only aggregate function that needs no column  |
| `@count(distinct:column)`                                           | `COUNT(DISTINCT column)` (also `*:@count(distinct:column)`; a real field before `:@count` can't be combined with `distinct:`) |
| `field:@group:@default(value)`                                      | `COALESCE(field, value)` in the output, grouping stays on the raw column     |
| `relation:@count:@join` / `relation:@sum(col):@join`                | Aggregates a **related** table's column via a real `LEFT JOIN` instead of a subquery — `relation` here is a relation name, `col` a column on the related table |
| `relation:@group(col):@join`                                        | Groups by a related table's column via the same shared `LEFT JOIN`          |

`:@join` only supports `BelongsTo`/`HasOne`/`HasMany` relations (not many-to-many or polymorphic), and only one relation may be joined per request — join two different relations and validation rejects it (the classic SQL "fan-out" problem). Combining any `aggregate=` with `sort=` by a relation column is also rejected: an aggregated query returns one row per group, not per parent, so there is no parent row for the sort to look its related column up from.

`expr` inside `@having(...)` uses the same value grammar as `filter` (`>=100`, `10..20` as `BETWEEN`, `10,20,30` as `IN`, `*text*` as `LIKE`, `null`/`filled`) and a leading `-` for negation (there's no key here to prefix, so the `-` goes on the expression itself: `@having(-10..20)` is `NOT BETWEEN`). It's applied directly against that one aggregate expression — no alias lookup involved, unlike a top-level `filter=`.

Fields referenced in `aggregate` (both `@group` targets and aggregate source columns) are validated against `aggregateBy()`; `*` is exempt. A date part defaults its alias to the field's own name, which a model with a date cast on that field turns back into a date — alias it (`created_at:@year:@group:@alias(year)`), as described under [Selecting](#selecting-select).

```
GET /api/author?aggregate=name:@group @count:@alias(total):@having(>=2)&filter=name:*fernando*
```

becomes roughly `SELECT name, COUNT(*) AS total FROM author GROUP BY name HAVING COUNT(*) >= 2` (`filter=name:*fernando*` still applies as a plain `WHERE`, evaluated before grouping).

### Casting (`cast`)

Space-separated items in `field:@castName[:@alias(name)]` form, resolved against named casts declared on `castMutator()` (array/`Collection` lookup, or a callable) and validated against `castBy()`:

```php
public function castBy(): array
{
    return ['ranking'];
}

public function castMutator(): array
{
    return [
        'rankingTier' => [10 => 'bronze', 20 => 'silver'],
    ];
}
```

```
GET /api/authors?cast=ranking:@rankingTier
```

Unlike every other parameter, `cast=` isn't applied as SQL — it runs after the query executes, against the hydrated result(s) (a single model from `first()`, a collection from `get()`/`paginate()`), and writes results into a `casts` attribute on each model (keyed by `:@alias(name)` or the field name) rather than overwriting the field's own attribute. Pair it with the `AggregateResource` trait on a `JsonResource` to surface `casts` (and `Aggregating`/`Including`-produced `aggregates`) as top-level response keys automatically:

```php
use Dex\Laravel\Curio\Extensions\AggregateResource;

class AuthorResource extends JsonResource
{
    use AggregateResource;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ranking' => $this->ranking,
        ];
    }
}
```

`aggregates` and `casts` are added as top-level siblings of whatever `toArray()` already returns, and are omitted entirely (not sent as empty arrays) when there's nothing to report. `aggregates` is collected by a naming-pattern heuristic (any attribute whose key contains `_count`, `_sum`, `_avg`, `_min`, `_max`, or `_exists`) rather than an explicit list, so a genuine column that happens to match that pattern would be swept in too — keep that in mind if you have a real `_count`-suffixed column outside of `aggregate=`/`include=@count` usage. When the request's `aggregate=` parameter is present, `AggregateResource` returns the model's raw grouped/aggregated attributes directly instead of running `toArray()` at all, since there's no longer a single "row" shaped like your normal resource.

### Pagination (`page`, `size`)

`page` (default `1`) and `size` (default `25`) — all configurable per query class or globally. Requesting a `size` above `defaultMaxPageSize()` (default `250`) fails validation with a 422, it is not silently capped.

## `PaginateQuery` reference

Subclass `Dex\Laravel\Curio\Query\PaginateQuery` and override what you need — everything defaults to "nothing allowed" / empty string:

| Method                                                                                                                | Returns                                   | Purpose                                                                                                                        |
|-----------------------------------------------------------------------------------------------------------------------|-------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------|
| `filterBy()`                                                                                                          | `['field' => 'laravel_validation_rules']` | Allowed filter fields and their validation rules                                                                               |
| `searchBy()`                                                                                                          | `['field', ...]`                          | Fields free text is matched against — see [Search (free text)](#search-free-text)                                              |
| `searchMode()` / `searchSimilarityThreshold()`                                                                        | `string` / `float`                        | `like`/`trgm`/`unaccent` and the `trgm` similarity threshold, backed by `config('curio.search.*')`                             |
| `sortBy()`                                                                                                            | `['field', ...]`                          | Allowed sort fields                                                                                                            |
| `allowSort(string ...$columns)` | `static` | Adds sortable fields to one instance, on top of `sortBy()` — for a query built per request (`getPaginateQuery()` may return an instance instead of a class name) |
| `includeBy()`                                                                                                         | `['relation' => QueryClass::class]`       | Allowed eager-loads, each linked to its own `PaginateQuery`                                                                    |
| `selectBy()`                                                                                                          | `['field', ...]`                          | Allowed select fields                                                                                                          |
| `aggregateBy()`                                                                                                       | `['field', ...]`                          | Allowed fields referenceable inside `aggregate` (both `@group` targets and aggregate source columns)                           |
| `castBy()` / `castMutator()`                                                                                          | `['field', ...]` / `['castName' => resolver]` | Allowed cast fields and the named resolvers available to `cast=` — see [Casting](#casting-cast)                           |
| `replaceBy()`                                                                                                         | `['alias' => 'real_field']`               | Field aliases (URL-friendly names → real keys) — applies to `filter`/`sort`/`select`/`aggregate`/`cast`, not `include`         |
| `defaultFilter()` / `defaultSort()` / `defaultSelect()` / `defaultInclude()` / `defaultAggregate()` / `defaultCast()` | `string`                 | Applied when the request omits that query parameter                                                                            |
| `defaultPageNumber()` / `defaultPageSize()` / `defaultMaxPageSize()`                                                  | `int`                                     | Pagination defaults, backed by `config('curio.paginate.*')`                                                                    |
| `maxFilterClauses()` / `maxFilterDepth()` / `maxSortFields()` / `maxAggregateItems()` / `maxIncludeDepth()` / `maxIncludeRelations()` | `int` | How much one request may ask for, backed by `config('curio.*.max_*')` — exceeding a limit is a 422 under the parameter's own key |

### Field aliases

`replaceBy()` lets clients use friendlier or differently-cased query keys than your database columns. It applies to `filter`, `sort`, `select`, `aggregate` and `cast` — every DSL type **except** `include`, since an `include=` key names a relation rather than a field, and aliasing it the same way would risk rewriting a real relation name (`include=author`) into a broken nested-relation lookup:

```php
public function replaceBy(): array
{
    return [
        'author' => 'author.name',      // filter=author:jane / sort=author
        'authorName' => 'author.name',
        'date-of-birth' => 'date_of_birth',  // also resolves in select=/aggregate=/cast=
    ];
}
```

## Fluent querying without a `PaginateQuery`

`use Dex\Laravel\Curio\YourCuriosity;` on a model to get a `Model::curio()` fluent builder that speaks the same `filter=`/`sort=`/`select=`/`aggregate=`/`include=`/`cast=` syntax — handy for internal tooling, jobs and tinker, where there is no request to hang a `PaginateQuery` on. It runs the exact same pipeline as the HTTP path, validation included: the allow-lists just come from the model itself, which declares the same `filterBy()`/`sortBy()`/`selectBy()`/`aggregateBy()`/`includeBy()`/`castBy()` methods a `PaginateQuery` would. Anything the model doesn't declare is rejected with a `ValidationException`, and a model that declares nothing allows nothing.

```php
use Dex\Laravel\Curio\YourCuriosity;

class Post extends Model
{
    use YourCuriosity;

    public function filterBy(): array
    {
        return [
            'title' => ['string'],
            'published_at' => ['date'],
        ];
    }

    public function sortBy(): array
    {
        return ['title', 'published_at'];
    }
}

Post::curio()->filter('title:*laravel*')->sort('-published_at')->get();

Post::curio()->filter('content:*laravel*')->get(); // ValidationException: The field 'content' is not allowed on query filter.
```

Every DSL method (`filter()`, `sort()`, `select()`, `aggregate()`, `include()`, `cast()`) only queues its argument — nothing touches the builder until `get()`/`paginate()` runs, or a forwarded builder call does. Free-text search works here too, keyed off `searchBy()` — implement `Dex\Laravel\Curio\Contracts\Searchable` (typically via the `Query\SearchBy` trait) directly on the model:

```php
use Dex\Laravel\Curio\Contracts\Searchable;
use Dex\Laravel\Curio\Query\SearchBy;

class Post extends Model implements Searchable
{
    use SearchBy;
    use YourCuriosity;

    public function searchBy(): array
    {
        return ['title'];
    }
}

Post::curio()->filter('laravel')->get(); // free text, LIKE across title
```

## Configuration

```php
// config/curio.php
return [
    'query' => [
        'aggregate' => 'aggregate',
        'cast' => 'cast',
        'filter' => 'filter',
        'include' => 'include',
        'page' => 'page',
        'size' => 'size',
        'select' => 'select',
        'sort' => 'sort',
    ],

    'paginate' => [
        'default_page_number' => 1,
        'default_page_size' => 25,
        'default_max_page_size' => 250,
    ],

    'filter' => [
        // Maximum number of clauses in `filter=`, counted after presets are expanded.
        'max_clauses' => 50,
        // Maximum number of relations a filter key may go through.
        'max_depth' => 3,
    ],

    'sort' => [
        // Maximum number of fields in `sort=`.
        'max_fields' => 5,
    ],

    'aggregate' => [
        // Maximum number of items in `aggregate=`.
        'max_items' => 10,
    ],

    'include' => [
        // Maximum relation nesting depth accepted in `include=`.
        'max_depth' => 3,
        // Maximum number of relations in `include=`.
        'max_relations' => 10,
    ],

    'search' => [
        // Options: like, trgm (Postgres), unaccent (Postgres)
        'mode' => 'like',
        'similarity_threshold' => 0.3,
    ],
];
```

Add your own `config/curio.php` with only the sections you want to change — the package's defaults are merged in for the rest.

Defaults are merged one level deep: a top-level section you define (`query`, `paginate`, `filter`, `sort`, `aggregate`, `include`, `search`) **replaces** the package's section, it isn't merged key by key. Copy the whole section you are changing — a `paginate` section holding only `default_page_size` leaves the other two keys undefined, and every request fails. `search` is the exception: its two keys fall back to their defaults (`like`, `0.3`) when absent.

The `max_*` keys bound how much a single request can ask the database to do — an allow-list limits which fields a request may name, not how many times it names them. Each one is the default for the matching `PaginateQuery` method (`maxFilterClauses()`, `maxFilterDepth()`, `maxSortFields()`, `maxAggregateItems()`, `maxIncludeDepth()`, `maxIncludeRelations()`), which a query can override for its own endpoint. Clauses are counted after presets are expanded, free text counts as a single clause, and a filter scoped to a relation (`include=posts:@filter(...)`) has a budget of its own.

## Extending Curio

Every fluent DSL call (`filter()`, `sort()`, `select()`, `aggregate()`, `include()`, `cast()` — whether from `Model::curio()` or through the HTTP pipeline) parses its argument immediately and queues the result; nothing touches the query builder until a terminal call (`get()`, `paginate()`, `first()`, ...) actually runs. At that point the whole batch goes through `Dex\Laravel\Curio\Pipeline`, which first dispatches one `Dex\Laravel\Curio\Events\ApplyPending` event carrying every queued token, and then runs its own steps in a fixed order — field-alias resolution, one step per DSL type, and registering relation-aggregate/include aliases as sortable. It's a normal Laravel event, so you can hook your own listener onto it — for auditing, metrics, or rewriting a request before it is applied:

```php
use Dex\Laravel\Curio\Events\ApplyPending;
use Illuminate\Support\Facades\Event;

Event::listen(ApplyPending::class, function (ApplyPending $event) {
    Log::info('curio query', [
        'model' => $event->builder->getModel()::class,
        'types' => array_unique(array_column($event->tokens, 'type')),
    ]);
});
```

The event is a hook, not the mechanism: Curio's own steps are called directly, never through the dispatcher. Your listener runs *before* them, so it sees the tokens as they were parsed (aliases from `replaceBy()` not yet resolved) and may rewrite `$event->tokens` — whatever it leaves there is still validated against the allow-lists before it reaches the query. What a listener can't do is switch the pipeline off: filters, validation and `defaultFilter()` are applied even under `Event::fake()`, or when a listener returns `false`.

## Testing

```bash
composer test     # run all tests (vendor/bin/pest)
composer analyse  # PHPStan static analysis
composer format   # Laravel Pint code style fixer
composer coverage # test coverage with Xdebug
composer mutate   # mutation testing with Xdebug (slow - takes minutes)
composer test:postgres # the suite that executes against a real PostgreSQL server

vendor/bin/pest tests/path/to/Test.php --filter "test name" # run a single test
```

### PostgreSQL suite

`composer test` runs on an in-memory SQLite, and most of it asserts the SQL Curio *builds* — which never reaches a database. That is no proof for what only PostgreSQL can run (the `trgm` and `unaccent` search modes, `sort=field:@unaccent`, `EXTRACT()`, `jsonb` containment), so those have a suite of their own, under `tests/Postgres`, that executes every query against a real server:

```bash
docker run --rm -d --name curio-postgres -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=curio -p 5432:5432 postgres:17-alpine

CURIO_POSTGRES_URL=pgsql://postgres:postgres@127.0.0.1:5432/curio composer test:postgres
```

The suite enables the `pg_trgm` and `unaccent` extensions itself, so the user in the URL has to be allowed to. Without `CURIO_POSTGRES_URL` every test in it is skipped rather than failed; CI runs it with `--fail-on-skipped`, against every supported PostgreSQL version.

### Mutation testing

`composer mutate` rewrites small pieces of `src/` — flipping a comparison, dropping a guard, changing an integer — and reruns the tests that cover each change. A mutant that survives is a line the suite doesn't actually hold in place. The run fails below a score of 80%.

```bash
vendor/bin/pest --mutate --covered-only --parallel --class='Dex\Laravel\Curio\Language\Sorting'
vendor/bin/pest --mutate --covered-only --parallel --path=src/Language/Filtering.php
```

Two things are worth knowing when reading a report. Some mutants can't be killed by any test — the classic case is `assert()`, which production compiles out — and `// @pest-mutate-ignore` (optionally `// @pest-mutate-ignore: Mutator`) on that line is the escape hatch. And a handful of mutants are only caught by running out of time rather than by an assertion, so the score moves by about a point between runs on a busy machine; treat the threshold as a floor, not a target.

The `workbench/` directory is a runnable Orchestra Testbench app (`Author`, `Post`, `Comment`, `User` models with matching queries, requests, controllers and resources) used by the test suite — it's a good place to see the full feature set wired together end-to-end.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Eder Soares](https://github.com/edersoares)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
