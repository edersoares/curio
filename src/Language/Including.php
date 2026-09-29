<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * @phpstan-type IncludeAggregationItem array{relation: string, aggregation: string, column: string|null, distinct: bool, alias: string, filter: string|null, mode: 'aggregation'}
 * @phpstan-type IncludeOtherItem array{relation: string, filter: string|null, mode: 'filter'|'only'|null, limit: int|null}
 * @phpstan-type IncludeItem IncludeAggregationItem|IncludeOtherItem
 * @phpstan-import-type FilterClause from Filtering
 */
class Including implements Applier
{
    public function __construct(private readonly Parser $parser) {}

    /**
     * @param array<int, FilterClause> $tokens
     *
     * @return array<int, IncludeItem>
     */
    public function build(array $tokens): array
    {
        return array_map(fn (array $token) => $this->buildItem($token), $tokens);
    }

    /**
     * Dispatches an already-tokenized `key`/`modifiers` pair to the matching
     * item shape. Falls back to a literal relation name (`parsePlainInclude()`)
     * for a negated key, a value-shaped token, a key outside `[\w.]+`, or a
     * modifier chain whose first entry isn't a recognized role (an aggregate
     * function, `@limit`, `@filter`, or `@only`) - matching `Eloquent::with()`'s
     * own `relation:col1,col2` column-selection syntax for anything this
     * grammar doesn't otherwise recognize.
     *
     * @param FilterClause $token
     *
     * @return IncludeItem
     */
    private function buildItem(array $token): array
    {
        $relation = $token['key'];

        if ($token['negated'] || $token['operator'] !== null || !preg_match('/^[\w.]+$/', $relation)) {
            return $this->parsePlainInclude($relation);
        }

        $modifiers = $token['modifiers'];
        $first = array_key_first($modifiers);

        if ($first !== null && in_array($first, Aggregating::FUNCTIONS, true)) {
            return $this->parseAggregationInclude($relation, $first, $modifiers);
        }

        if ($first === 'limit' && count($modifiers) === 1 && preg_match('/^\d+$/', $modifiers['limit'][0] ?? '')) {
            return ['relation' => $relation, 'filter' => null, 'mode' => null, 'limit' => (int) $modifiers['limit'][0]];
        }

        if (($first === 'filter' || $first === 'only') && ($modifiers[$first][0] ?? '') !== '') {
            return $this->parseFilterOrOnlyInclude($relation, $first, $modifiers);
        }

        return $this->parsePlainInclude($relation);
    }

    /**
     * @param array<string, list<string>> $modifiers
     *
     * @return IncludeAggregationItem
     */
    private function parseAggregationInclude(string $relation, string $aggregation, array $modifiers): array
    {
        $args = $this->parseAggregationArgs($aggregation, $modifiers[$aggregation][0] ?? null, $relation);

        $filter = null;
        $alias = null;

        foreach ($modifiers as $modifier => $values) {
            if ($modifier === $aggregation) {
                continue;
            }

            match ($modifier) {
                'only' => $filter = $this->requireModifierValue($values[0] ?? '', $relation, 'only'),
                'alias' => $alias = $this->requireAliasValue($values[0] ?? '', $relation),
                default => throw ValidationException::withMessages([
                    'include' => "The include expression '$relation' is not valid: '@{$modifier}' is not allowed here.",
                ]),
            };
        }

        if ($alias === null) {
            $alias = $args['distinct']
                ? "{$relation}_count_distinct_{$args['column']}"
                : $this->defaultAggregateAlias($relation, $aggregation, $args['column'] ?? '*');
        }

        return [
            'relation' => $relation,
            'aggregation' => $aggregation,
            'column' => $args['column'],
            'distinct' => $args['distinct'],
            'alias' => $alias,
            'filter' => $filter,
            'mode' => 'aggregation',
        ];
    }

    private function requireModifierValue(string $value, string $include, string $modifier): string
    {
        if ($value === '') {
            throw ValidationException::withMessages([
                'include' => "The include expression '$include' is not valid: '@{$modifier}' requires a value.",
            ]);
        }

        return $value;
    }

    /**
     * An aggregation alias becomes a real SQL alias (`relation as alias`,
     * see `applyAggregation()`) *and* a sortable column name
     * (`Listeners\AllowSortIncludes` feeds every alias built here into
     * `PaginateQuery::allowSort()`) - so it has to be a plain identifier,
     * exactly like `Selecting`/`Aggregating`/`Casting` already require of
     * their own `:@alias(name)`. Accepting anything non-empty let a request
     * name an alias carrying a `.` (`:@alias(truncate.x)`), which
     * `Sorting::applySort()` then read as `relation.column` and resolved by
     * calling `$model->{relation}()` - and `Model::__call()` forwards an
     * unknown name straight to the query builder, so `sort=truncate.x`
     * reached `Builder::truncate()`.
     */
    private function requireAliasValue(string $value, string $include): string
    {
        $this->requireModifierValue($value, $include, 'alias');

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw ValidationException::withMessages([
                'include' => "The include expression '$include' is not valid: '@alias' requires a valid identifier.",
            ]);
        }

        return $value;
    }

    /**
     * Replicates Eloquent's own default alias for `withAggregate()` (used by
     * `withCount`/`withSum`/`withAvg`/`withMin`/`withMax` when no explicit
     * alias is given), so the alias is known up front instead of being
     * computed internally and hidden from `build()`'s caller.
     *
     * @see \Illuminate\Database\Eloquent\Concerns\QueriesRelationships::withAggregate()
     */
    private function defaultAggregateAlias(string $relation, string $function, string $column): string
    {
        $normalized = preg_replace(
            '/[^[:alnum:][:space:]_]/u',
            '',
            sprintf('%s %s %s', $relation, $function, strtolower($column)),
        );

        return str($normalized)->snake()->toString();
    }

    /**
     * `$modifiers` may carry other keys besides `$mode`/`limit` (e.g.
     * `posts:@filter(a):@alias(b)`) - those are silently ignored, same as
     * before: only an exact `[$mode, 'limit']` pair (nothing else) combines
     * with a limit.
     *
     * @param 'filter'|'only' $mode
     * @param array<string, list<string>> $modifiers
     *
     * @return IncludeOtherItem
     */
    private function parseFilterOrOnlyInclude(string $relation, string $mode, array $modifiers): array
    {
        $limit = array_keys($modifiers) === [$mode, 'limit'] && preg_match('/^\d+$/', $modifiers['limit'][0] ?? '')
            ? (int) $modifiers['limit'][0]
            : null;

        return [
            'relation' => $relation,
            'filter' => $modifiers[$mode][0],
            'mode' => $mode,
            'limit' => $limit,
        ];
    }

    /**
     * @return IncludeOtherItem
     */
    private function parsePlainInclude(string $include): array
    {
        return [
            'relation' => $include,
            'filter' => null,
            'mode' => null,
            'limit' => null,
        ];
    }

    /**
     * @param Builder<Model> $builder
     * @param array<int, IncludeItem> $parsedItems
     */
    protected function applyParsed(Builder $builder, array $parsedItems, ?PaginateQuery $query = null): void
    {
        foreach ($parsedItems as $parsed) {
            if ($parsed['mode'] === 'aggregation') {
                $this->applyAggregation($builder, $parsed, $query);
                continue;
            }

            if ($parsed['filter'] === null && $parsed['limit'] === null) {
                $builder->with($parsed['relation']);
                continue;
            }

            $relationQuery = $parsed['filter'] !== null && $query !== null
                ? $this->resolveQuery($parsed['relation'], $query)
                : null;

            $filter = $parsed['filter'];
            $limit = $parsed['limit'];
            $relationName = $parsed['relation'];

            if ($parsed['mode'] === 'filter') {
                // $parsed['mode'] === 'filter' is only ever produced alongside a non-null 'filter' (see parseFilterOrOnlyInclude()); $relationQuery above is only guaranteed non-null when $query was given.
                assert($filter !== null);

                $builder->whereHas($relationName, function (Builder $q) use ($filter, $relationQuery) {
                    // $relationQuery is only null when the outer $query was itself null - unreachable in practice, see applyRelationFilter()'s docblock.
                    assert($relationQuery !== null);

                    $this->applyRelationFilter($q, $filter, $relationQuery);
                });
            }

            $builder->with([
                $relationName => function (Builder|Relation $q) use ($filter, $relationQuery, $limit, $relationName) {
                    if ($filter !== null) {
                        // $relationQuery is only null when the outer $query was itself null - unreachable in practice, see applyRelationFilter()'s docblock.
                        assert($relationQuery !== null);

                        $this->applyRelationFilter($q, $filter, $relationQuery);
                    }

                    if ($limit !== null) {
                        $this->applyLimit($q, $relationName, $limit);
                    }
                },
            ]);
        }
    }

    /**
     * Dispatches an `ApplyPending` event carrying this filter's tokens, so
     * `Listeners\ReplaceKeys` and `Listeners\FilterListener` (already
     * registered for `ApplyPending` in `CurioServiceProvider::boot()`) resolve
     * `replaceBy()` aliases and apply the filter exactly as the top-level
     * pipeline does, instead of duplicating that logic here. Every other
     * listener registered on `ApplyPending` filters on a different token
     * `type` and no-ops; `Listeners\SearchListener` also fires - a
     * relation-scoped `:@filter(some words)` now searches the relation's own
     * `searchBy()` too, same as free text does in the top-level `filter=`
     * pipeline.
     *
     * `$relationQuery === null` only when the *outer* `$query` passed to
     * `apply()` was itself null - unreachable in practice (`apply()`'s own
     * `PaginateQuery $query` parameter is non-nullable), kept only as a
     * defensive fallback matching this method's previous behavior.
     *
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    private function applyRelationFilter(Builder|Relation $builder, string $filter, PaginateQuery $query): void
    {
        $tokens = $this->parser->transform($filter, 'filter');

        event(new ApplyPending($tokens, $builder, $query));
    }

    /**
     * @param Builder<Model>|Relation<Model, Model, mixed> $relation
     */
    private function applyLimit(Builder|Relation $relation, string $name, int $limit): void
    {
        if (
            !$relation instanceof HasOneOrMany
            && !$relation instanceof BelongsToMany
            && !$relation instanceof HasOneOrManyThrough
        ) {
            throw ValidationException::withMessages([
                'include' => "The relation '{$name}' does not support ':@limit()' because it does not return multiple rows per parent.",
            ]);
        }

        $relation->limit($limit);
    }

    /**
     * @param Builder<Model> $builder
     * @param IncludeAggregationItem $parsed
     */
    private function applyAggregation(Builder $builder, array $parsed, ?PaginateQuery $query = null): void
    {
        $relation = $parsed['relation'];
        $type = $parsed['aggregation'];
        $column = $parsed['column'] ?? '*';
        $distinct = $parsed['distinct'];
        $alias = $parsed['alias'];
        $filter = $parsed['filter'];

        $relationExpr = "{$relation} as {$alias}";

        $constraint = null;

        if ($filter) {
            $relationQuery = $query !== null ? $this->resolveQuery($relation, $query) : null;

            $constraint = function (Builder $q) use ($filter, $relationQuery) {
                // $relationQuery is only null when the outer $query was itself null - unreachable in practice, see applyRelationFilter()'s docblock.
                assert($relationQuery !== null);

                $this->applyRelationFilter($q, $filter, $relationQuery);
            };
        }

        $target = $constraint ? [$relationExpr => $constraint] : [$relationExpr];

        if ($distinct) {
            $qualifiedColumn = $this->qualifyRelationColumn($builder, $relation, $column);
            $wrappedColumn = $builder->getQuery()->getGrammar()->wrap($qualifiedColumn);

            // @phpstan-ignore-next-line argument.type (Expression<TValue of literal-string> - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
            $builder->withAggregate($target, new Expression('distinct ' . $wrappedColumn), 'count');

            return;
        }

        match ($type) {
            'count' => $builder->withCount($target),
            'sum' => $builder->withSum($target, $column),
            'avg' => $builder->withAvg($target, $column),
            'min' => $builder->withMin($target, $column),
            'max' => $builder->withMax($target, $column),
            default => throw new RuntimeException("The aggregation '{$type}' is not supported."), // @codeCoverageIgnore
        };
    }

    /**
     * @return array{column: string|null, distinct: bool}
     */
    private function parseAggregationArgs(string $aggregation, ?string $args, string $include): array
    {
        if ($aggregation === 'count') {
            if ($args === null || $args === '') {
                return ['column' => null, 'distinct' => false];
            }

            if (preg_match('/^distinct:(?<column>\w+)$/', $args, $matches)) {
                return ['column' => $matches['column'], 'distinct' => true];
            }

            throw ValidationException::withMessages([
                'include' => "The include expression '$include' is not valid: '@count' only accepts no arguments or 'distinct:column'.",
            ]);
        }

        if ($args === null || $args === '') {
            throw ValidationException::withMessages([
                'include' => "The include expression '$include' is not valid: '@{$aggregation}' requires a column, e.g. '@{$aggregation}(column)'.",
            ]);
        }

        if (str_starts_with($args, 'distinct:')) {
            throw ValidationException::withMessages([
                'include' => "The include expression '$include' is not valid: 'distinct' is only supported with '@count'.",
            ]);
        }

        if (!preg_match('/^\w+$/', $args)) {
            throw ValidationException::withMessages([
                'include' => "The include expression '$include' is not valid.",
            ]);
        }

        return ['column' => $args, 'distinct' => false];
    }

    /**
     * @param Builder<Model> $builder
     */
    private function qualifyRelationColumn(Builder $builder, string $relation, string $column): string
    {
        $model = $builder->getModel();

        foreach (explode('.', $relation) as $segment) {
            $relatedRelation = $model->isRelation($segment) ? $model->{$segment}() : null;

            // `includeBy()` already vouched for every segment, but it maps
            // names to `PaginateQuery` classes - it can't know the model
            // still declares the relation. Checked at runtime rather than
            // with `assert()` (compiled out in production) because a name
            // that isn't a relation would otherwise be forwarded by
            // `Model::__call()` to the query builder and invoked there.
            if (!$relatedRelation instanceof Relation) {
                throw ValidationException::withMessages([
                    'include' => "The relation '{$relation}' is not allowed on query include.",
                ]);
            }

            $model = $relatedRelation->getRelated();
        }

        return $model->qualifyColumn($column);
    }

    /**
     * @param array<int, FilterClause> $tokens already-parsed via `Parser::transform()`
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query): void
    {
        assert($builder instanceof Builder);

        $parsedItems = $this->build($tokens);

        if ($errors = $this->validate($parsedItems, $query)) {
            throw ValidationException::withMessages($errors);
        }

        $this->applyParsed($builder, $parsedItems, $query);
    }

    /**
     * Every parsed item - plain, filtered, or aggregated - must name a
     * relation reachable through `includeBy()`. Previously only filtered
     * items (`:@filter`/`:@only`) were checked here, so a plain include
     * naming anything outside the allowlist (e.g. a stray `has:usage`
     * token that fails to parse as a recognized modifier and falls back to
     * `parsePlainInclude()`) skipped this validation entirely and reached
     * `Builder::with()` unchecked - which, for a name that collides with a
     * builder/relation-forwarded method expecting arguments (`has`, ...),
     * throws a raw `ArgumentCountError` instead of a 422.
     *
     * A relation is also rejected for nesting deeper than
     * `maxIncludeDepth()`: `includeBy()` can't bound a chain on its own,
     * because a chain may revisit a query class it already passed through
     * (`posts.author.posts...` is allow-listed at every level), and each
     * extra level multiplies the serialized payload by the number of related
     * rows per parent - a few extra characters were enough to answer a single
     * request with gigabytes of JSON.
     *
     * @param array<int, IncludeItem> $parsedItems
     *
     * @return array<string, array<string>>
     */
    public function validate(array $parsedItems, PaginateQuery $query): array
    {
        $messages = [];
        $maxDepth = $query->maxIncludeDepth();

        foreach ($parsedItems as $parsed) {
            if (substr_count($parsed['relation'], '.') + 1 > $maxDepth) {
                $messages[] = "The relation '{$parsed['relation']}' is nested deeper than the maximum include depth of {$maxDepth}.";

                continue;
            }

            if ($this->resolveQueryOrNull($parsed['relation'], $query) === null) {
                $messages[] = "The relation '{$parsed['relation']}' is not allowed on query include.";
            }
        }

        return $messages ? ['include' => $messages] : [];
    }

    private function resolveQueryOrNull(string $relation, PaginateQuery $query): ?PaginateQuery
    {
        $segments = explode('.', $relation);
        $current = $query;

        foreach ($segments as $segment) {
            $queryClass = $current->includeBy()[$segment] ?? null;

            if ($queryClass === null) {
                return null;
            }

            $current = app($queryClass);
            assert($current instanceof PaginateQuery);
        }

        return $current;
    }

    private function resolveQuery(string $relation, PaginateQuery $query): PaginateQuery
    {
        return $this->resolveQueryOrNull($relation, $query) ?? throw ValidationException::withMessages([
            'include' => "The relation '{$relation}' is not allowed on query include.",
        ]);
    }
}
