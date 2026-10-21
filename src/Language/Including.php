<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Closure;
use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Pipeline;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
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
    public function __construct(
        private readonly Parser $parser,
        private readonly VariableResolver $variables,
    ) {}

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
     * item shape: an aggregation (`relation:@count`, `@sum(column)`, ...) or
     * an eager load, optionally scoped by one `@filter(...)` or `@only(...)`
     * and/or limited by `@limit(n)`, in either order.
     *
     * Anything else - an unknown modifier, a negated relation, a value, or
     * `@filter` together with `@only` - is a 422 rather than a plain eager
     * load of the bare name, which would quietly drop what the client asked
     * for.
     *
     * @param FilterClause $token
     *
     * @return IncludeItem
     */
    private function buildItem(array $token): array
    {
        $relation = $token['key'];

        if (!preg_match('/^[\w.]+$/', $relation)) {
            throw $this->invalid($relation, 'a relation name may only contain letters, digits, `_` and `.`.');
        }

        if ($token['negated']) {
            throw $this->invalid($relation, 'a relation can\'t be negated.');
        }

        if ($token['operator'] !== null) {
            throw $this->invalid($relation, 'a relation takes modifiers (`:@name(...)`), not a value.');
        }

        $modifiers = $token['modifiers'];
        $first = array_key_first($modifiers);

        if ($first !== null && in_array($first, Aggregating::FUNCTIONS, true)) {
            return $this->parseAggregationInclude($relation, $first, $modifiers);
        }

        return $this->parseEagerLoadInclude($relation, $modifiers);
    }

    /**
     * @param array<string, list<string>> $modifiers
     *
     * @return IncludeOtherItem
     */
    private function parseEagerLoadInclude(string $relation, array $modifiers): array
    {
        $mode = null;
        $filter = null;
        $limit = null;

        foreach ($modifiers as $modifier => $values) {
            $value = $values[0] ?? null;

            match ($modifier) {
                'filter', 'only' => [$mode, $filter] = $this->requireScope($relation, $mode, $modifier, $value),
                'limit' => $limit = $this->requireLimit($relation, $value),
                default => throw $this->invalid($relation, "'@{$modifier}' is not allowed here."),
            };
        }

        return [
            'relation' => $relation,
            'filter' => $filter,
            'mode' => $mode,
            'limit' => $limit,
        ];
    }

    /**
     * @param 'filter'|'only'|null $current
     * @param 'filter'|'only' $modifier
     *
     * @return array{0: 'filter'|'only', 1: string}
     */
    private function requireScope(string $relation, ?string $current, string $modifier, ?string $value): array
    {
        if ($current !== null) {
            throw $this->invalid($relation, "'@filter' and '@only' can't be combined.");
        }

        if ($value === null || trim($value) === '') {
            throw $this->invalid($relation, "'@{$modifier}' requires a filter expression.");
        }

        return [$modifier, $value];
    }

    private function requireLimit(string $relation, ?string $value): int
    {
        if ($value === null || !preg_match('/^[1-9]\d{0,5}$/', $value)) {
            throw $this->invalid($relation, "'@limit' requires a positive integer.");
        }

        return (int) $value;
    }

    private function invalid(string $relation, string $reason): ValidationException
    {
        return ValidationException::withMessages([
            'include' => "The include expression '$relation' is not valid: $reason",
        ]);
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
     * Each relation an item names is constrained the way that relation's own
     * endpoint is: by its query's `defaultFilter()`, unless the item scopes
     * it with `@filter(...)`/`@only(...)` - which replaces the default, the
     * same way `filter=` replaces `defaultFilter()` at the top level. So rows
     * an endpoint hides by default stay hidden when they are reached through
     * an `include=` of another endpoint.
     *
     * In a dotted item (`posts.comments`) every level is a relation of its
     * own: the levels in between get their query's default too, unless
     * another item in the same request names them explicitly.
     *
     * `$context` is the request input that `$variables` in a default filter
     * resolve against. It is null on the fluent `Model::curio()` path, where
     * no `default*()` applies at any level, so none applies here either.
     *
     * @param Builder<Model> $builder
     * @param array<int, IncludeItem> $parsedItems
     * @param Collection<string, mixed>|null $context
     */
    protected function applyParsed(Builder $builder, array $parsedItems, PaginateQuery $query, ?Collection $context = null): void
    {
        $eagerLoads = [];

        foreach ($parsedItems as $parsed) {
            if ($parsed['mode'] === 'aggregation') {
                $this->applyAggregation($builder, $parsed, $query, $context);

                continue;
            }

            $relation = $parsed['relation'];
            $segments = explode('.', $relation);

            if ($parsed['mode'] === 'filter') {
                assert($parsed['filter'] !== null);

                $this->whereHasChain($builder, $segments, $query, $parsed['filter'], $context);
            }

            $path = [];

            foreach (array_slice($segments, 0, -1) as $segment) {
                $path[] = $segment;
                $name = implode('.', $path);
                $levelQuery = $this->resolveQuery($name, $query);

                $eagerLoads[$name] ??= $this->eagerLoadConstraint($name, $levelQuery, $this->defaultFilterOf($levelQuery, $context), null);
            }

            $leafQuery = $this->resolveQuery($relation, $query);

            $eagerLoads[$relation] = $this->eagerLoadConstraint(
                $relation,
                $leafQuery,
                $parsed['filter'] ?? $this->defaultFilterOf($leafQuery, $context),
                $parsed['limit'],
            );
        }

        if ($eagerLoads !== []) {
            $builder->with($eagerLoads);
        }
    }

    /**
     * @return Closure(Builder<Model>|Relation<Model, Model, mixed>): void
     */
    private function eagerLoadConstraint(string $relation, PaginateQuery $query, ?string $filter, ?int $limit): Closure
    {
        return function (Builder|Relation $q) use ($relation, $query, $filter, $limit): void {
            // Larastan types a `with()` constraint's argument as `Relation<*, *, *>`;
            // pin it to the shape the helpers below are declared against.
            /** @var Builder<Model>|Relation<Model, Model, mixed> $q */
            if ($filter !== null) {
                $this->applyRelationFilter($q, $filter, $query);
            }

            if ($limit !== null) {
                $this->applyLimit($q, $relation, $limit);
            }
        };
    }

    /**
     * `@filter(expr)` constrains the parent to rows with a matching related
     * row - one `whereHas()` per level, so the levels in between keep their
     * own query's default filter while the last one gets `expr` instead.
     *
     * @param Builder<Model> $builder
     * @param list<string> $segments
     * @param Collection<string, mixed>|null $context
     */
    private function whereHasChain(Builder $builder, array $segments, PaginateQuery $parentQuery, string $filter, ?Collection $context): void
    {
        $segment = array_shift($segments);

        assert($segment !== null);

        $query = $this->resolveQuery($segment, $parentQuery);

        $builder->whereHas($segment, function (Builder $q) use ($segments, $query, $filter, $context): void {
            if ($segments === []) {
                $this->applyRelationFilter($q, $filter, $query);

                return;
            }

            $default = $this->defaultFilterOf($query, $context);

            if ($default !== null) {
                $this->applyRelationFilter($q, $default, $query);
            }

            $this->whereHasChain($q, $segments, $query, $filter, $context);
        });
    }

    /**
     * The query's `defaultFilter()` with its `$variables` resolved against
     * the request, or null when there is no request context (the fluent
     * path) or no default.
     *
     * @param Collection<string, mixed>|null $context
     */
    private function defaultFilterOf(PaginateQuery $query, ?Collection $context): ?string
    {
        if ($context === null) {
            return null;
        }

        $filter = $this->variables->resolve($query->defaultFilter(), $context);

        return trim($filter) === '' ? null : $filter;
    }

    /**
     * Runs this filter's tokens through `Pipeline`, so
     * `Listeners\ReplaceKeys` and `Listeners\FilterListener` resolve
     * `replaceBy()` aliases and apply the filter exactly as the top-level
     * pipeline does, instead of duplicating that logic here. Every other
     * step filters on a different token `type` and no-ops;
     * `Listeners\SearchListener` also runs - a
     * relation-scoped `:@filter(some words)` now searches the relation's own
     * `searchBy()` too, same as free text does in the top-level `filter=`
     * pipeline.
     *
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    private function applyRelationFilter(Builder|Relation $builder, string $filter, PaginateQuery $query): void
    {
        $tokens = $this->parser->transform($filter, 'filter');

        app(Pipeline::class)->pending(new ApplyPending($tokens, $builder, $query));
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
    private function applyAggregation(Builder $builder, array $parsed, PaginateQuery $query, ?Collection $context): void
    {
        $relation = $parsed['relation'];
        $type = $parsed['aggregation'];
        $column = $parsed['column'] ?? '*';
        $distinct = $parsed['distinct'];
        $alias = $parsed['alias'];
        $relationQuery = $this->resolveQuery($relation, $query);

        // `@only(expr)` replaces the related query's default filter, as in
        // `applyParsed()`: a count of comments counts the comments that
        // endpoint would show.
        $filter = $parsed['filter'] ?? $this->defaultFilterOf($relationQuery, $context);

        $relationExpr = "{$relation} as {$alias}";

        $constraint = null;

        if ($filter !== null) {
            $constraint = function (Builder $q) use ($filter, $relationQuery): void {
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
     * @param Collection<string, mixed>|null $context the request input, on the HTTP path - see `applyParsed()`
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query, ?Collection $context = null): void
    {
        assert($builder instanceof Builder);

        $parsedItems = $this->build($tokens);

        if ($errors = $this->validate($parsedItems, $query)) {
            throw ValidationException::withMessages($errors);
        }

        $this->applyParsed($builder, $parsedItems, $query, $context);
    }

    /**
     * Every parsed item - plain, filtered, or aggregated - must name a
     * relation reachable through `includeBy()`; a name outside it never
     * reaches `Builder::with()`, where one colliding with a forwarded
     * builder method (`has`, ...) would throw a raw `ArgumentCountError`.
     *
     * `@limit(n)` is bounded by the related query's `defaultMaxPageSize()`,
     * the same ceiling `size=` has on that relation's own endpoint.
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

            $related = $this->resolveQueryOrNull($parsed['relation'], $query);

            if ($related === null) {
                $messages[] = "The relation '{$parsed['relation']}' is not allowed on query include.";

                continue;
            }

            $limit = $parsed['mode'] === 'aggregation' ? null : $parsed['limit'];

            if ($limit !== null && $limit > $related->defaultMaxPageSize()) {
                $messages[] = "The '@limit' on '{$parsed['relation']}' may not be greater than {$related->defaultMaxPageSize()}.";
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
