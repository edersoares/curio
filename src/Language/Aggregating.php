<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Language\Concerns\BuildsAggregateExpressions;
use Dex\Laravel\Curio\Language\Concerns\JoinsAggregateRelations;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * @phpstan-type AggregateItem array{field: string, role: 'group'|'datepart'|'aggregate', function: string|null, group: bool, alias: string|null, having: string|null, distinct?: bool, join?: bool, joinColumn?: string|null, groupColumn?: string|null, default?: string|null}
 * @phpstan-type AggregateColumn Expression<literal-string|int|float>|string
 * @phpstan-import-type FilterClause from Filtering
 */
class Aggregating implements Applier
{
    use BuildsAggregateExpressions;
    use JoinsAggregateRelations;

    public const array FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max'];

    public const array DATE_PARTS = ['year', 'month', 'day', 'hour', 'minute', 'second'];

    /**
     * @param array<int, FilterClause> $tokens
     *
     * @return array<int, AggregateItem>
     */
    public function build(array $tokens): array
    {
        return array_map(fn (array $token) => $this->buildItem($token), $tokens);
    }

    /**
     * This method's job is just to dispatch on which role-defining modifier
     * is present in the token's modifier map and build the matching
     * `AggregateItem`. Error messages reference `$field` (the key) since the
     * original raw string isn't available once tokenization already ran.
     *
     * @param FilterClause $token
     *
     * @return AggregateItem
     */
    private function buildItem(array $token): array
    {
        $modifiers = $token['modifiers'];

        if ($token['operator'] !== null || $modifiers === []) {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '{$token['key']}' is not valid: each item must include ':@group', a date-part function (':@month'/':@year'/':@day'/':@hour'), or an aggregate function (':@count'/':@sum'/':@avg'/':@min'/':@max').",
            ]);
        }

        $field = $token['key'];
        $functions = array_values(array_intersect(array_keys($modifiers), self::FUNCTIONS));
        $dateParts = array_values(array_intersect(array_keys($modifiers), self::DATE_PARTS));

        return match (true) {
            $functions !== [] => $this->buildAggregateRole($field, $functions[0], $modifiers),
            $dateParts !== [] => $this->buildDatePartRole($field, $dateParts[0], $modifiers),
            isset($modifiers['group']) => $this->buildGroupRole($field, $modifiers),
            default => throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid: each item must include ':@group', a date-part function (':@month'/':@year'/':@day'/':@hour'), or an aggregate function (':@count'/':@sum'/':@avg'/':@min'/':@max').",
            ]),
        };
    }

    /**
     * @param array<string, list<string>> $modifiers
     *
     * @return AggregateItem
     */
    private function buildAggregateRole(string $field, string $function, array $modifiers): array
    {
        $args = $modifiers[$function][0] ?? '';
        $join = isset($modifiers['join']);

        if ($field === '*') {
            if ($join) {
                throw ValidationException::withMessages([
                    'aggregate' => "The aggregate expression '$field' is not valid: ':@join' requires an explicit relation name.",
                ]);
            }

            if ($function !== 'count') {
                throw ValidationException::withMessages([
                    'aggregate' => "The wildcard '*' can only be used with the @count function.",
                ]);
            }
        } else {
            $this->requireFieldName($field);
        }

        if ($join) {
            return $this->buildJoinAggregateItem($field, $function, $args, $modifiers);
        }

        if ($function === 'count') {
            $distinctColumn = $this->parseCountDistinctArgs($args, $field);

            if ($distinctColumn !== null) {
                if ($field !== '*') {
                    throw ValidationException::withMessages([
                        'aggregate' => "The aggregate expression '$field' is not valid: 'distinct:column' can only be used with the wildcard ('*:@count(distinct:column)' or '@count(distinct:column)').",
                    ]);
                }

                return $this->buildAggregateItem($distinctColumn, 'count', $modifiers, distinct: true);
            }
        } elseif ($args !== '') {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid: '@{$function}' does not accept arguments.",
            ]);
        }

        return $this->buildAggregateItem($field, $function, $modifiers);
    }

    /**
     * Build a relation-aggregate item for the `:@join` modifier - here
     * `$field` is a relation name (not a base-model column) and `$args` is a
     * column on the *related* table, joined via a `LEFT JOIN` at apply-time
     * (see `applyJoinAggregateItem()`/`JoinsAggregateRelations`).
     *
     * @param array<string, list<string>> $modifiers
     *
     * @return AggregateItem
     */
    private function buildJoinAggregateItem(string $field, string $function, string $args, array $modifiers): array
    {
        $distinct = false;
        $joinColumn = null;

        if ($function === 'count') {
            if ($args !== '') {
                $joinColumn = $this->parseCountDistinctArgs($args, $field);
                $distinct = true;
            }
        } elseif ($args === '' || !preg_match('/^\w+$/', $args)) {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid: '@{$function}:@join' requires a related column, e.g. '{$field}:@{$function}(column):@join'.",
            ]);
        } else {
            $joinColumn = $args;
        }

        $scanned = $this->scanModifiers($modifiers, $field, ['alias', 'having', 'join'], [$function, 'join']);

        $alias = $scanned['alias'] ?? ($distinct
            ? $field . '_count_distinct'
            : $this->defaultAlias($field, $function, isDatePart: false));

        return [
            'field' => $field,
            'role' => 'aggregate',
            'function' => $function,
            'group' => false,
            'alias' => $alias,
            'having' => $scanned['having'],
            'distinct' => $distinct,
            'join' => true,
            'joinColumn' => $joinColumn,
        ];
    }

    /**
     * Parse `@count`'s optional `(distinct:column)` argument. Returns the
     * distinct column name, or null when no argument was given (plain count).
     */
    private function parseCountDistinctArgs(string $args, string $field): ?string
    {
        if ($args === '') {
            return null;
        }

        if (preg_match('/^distinct:(?<column>\w+)$/', $args, $matches)) {
            return $matches['column'];
        }

        throw ValidationException::withMessages([
            'aggregate' => "The aggregate expression '$field' is not valid: '@count' only accepts no arguments or 'distinct:column'.",
        ]);
    }

    /**
     * @param array<string, list<string>> $modifiers
     *
     * @return AggregateItem
     */
    private function buildAggregateItem(string $field, string $function, array $modifiers, bool $distinct = false): array
    {
        $scanned = $this->scanModifiers($modifiers, $field, ['alias', 'having'], [$function]);

        $alias = $scanned['alias'] ?? ($distinct
            ? $field . '_count_distinct'
            : $this->defaultAlias($field, $function, isDatePart: false));

        $result = [
            'field' => $field,
            'role' => 'aggregate',
            'function' => $function,
            'group' => false,
            'alias' => $alias,
            'having' => $scanned['having'],
        ];

        if ($distinct) {
            $result['distinct'] = true;
        }

        return $result;
    }

    /**
     * @param array<string, list<string>> $modifiers
     *
     * @return AggregateItem
     */
    private function buildDatePartRole(string $field, string $function, array $modifiers): array
    {
        $this->requireFieldName($field);

        if (($modifiers[$function][0] ?? '') !== '') {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid: '@{$function}' does not accept arguments.",
            ]);
        }

        $scanned = $this->scanModifiers($modifiers, $field, ['alias', 'group'], [$function]);

        return [
            'field' => $field,
            'role' => 'datepart',
            'function' => $function,
            'group' => $scanned['group'],
            'alias' => $scanned['alias'],
            'having' => null,
        ];
    }

    /**
     * @param array<string, list<string>> $modifiers
     *
     * @return AggregateItem
     */
    private function buildGroupRole(string $field, array $modifiers): array
    {
        $this->requireFieldName($field);

        $args = $modifiers['group'][0] ?? '';

        if (isset($modifiers['join'])) {
            return $this->buildJoinGroupItem($field, $args, $modifiers);
        }

        if ($args !== '') {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid: '@group' does not accept arguments unless combined with ':@join'.",
            ]);
        }

        $scanned = $this->scanModifiers($modifiers, $field, ['alias', 'default'], ['group']);

        $result = [
            'field' => $field,
            'role' => 'group',
            'function' => null,
            'group' => true,
            'alias' => $scanned['alias'],
            'having' => null,
        ];

        if ($scanned['default'] !== null) {
            $result['default'] = $scanned['default'];
        }

        return $result;
    }

    /**
     * Build a relation-group item for the `:@join` modifier - here `$field`
     * is a relation name (not a base-model column) and `$args` is the
     * *related* table's column to group by, joined via the same shared
     * `LEFT JOIN` used by relation-aggregate items on the same relation (see
     * `resolveSharedJoinRelatedModel()`).
     *
     * @param array<string, list<string>> $modifiers
     *
     * @return AggregateItem
     */
    private function buildJoinGroupItem(string $field, string $args, array $modifiers): array
    {
        if ($args === '' || !preg_match('/^\w+$/', $args)) {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid: '@group:@join' requires a related column, e.g. '{$field}:@group(column):@join'.",
            ]);
        }

        $scanned = $this->scanModifiers($modifiers, $field, ['alias', 'join', 'default'], ['group', 'join']);

        $result = [
            'field' => $field,
            'role' => 'group',
            'function' => null,
            'group' => true,
            'alias' => $scanned['alias'],
            'having' => null,
            'join' => true,
            'groupColumn' => $args,
        ];

        if ($scanned['default'] !== null) {
            $result['default'] = $scanned['default'];
        }

        return $result;
    }

    /**
     * Validates a field extracted via `Tokenizer::matchKeyPrefix()` (whose
     * key charclass, `[\w.>-]+`, is shared with `Filtering` and accepts
     * `.`/`-`) against the narrower field shape `Aggregating` has always
     * required (`\w+` - the wildcard `*` is handled separately by its
     * callers before this is reached).
     */
    private function requireFieldName(string $field): void
    {
        if (!preg_match('/^\w+$/', $field)) {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid: each item must include ':@group', a date-part function (':@month'/':@year'/':@day'/':@hour'), or an aggregate function (':@count'/':@sum'/':@avg'/':@min'/':@max').",
            ]);
        }
    }

    /**
     * @param array<string, list<string>> $modifiers
     * @param array<int, string> $allowed
     * @param array<int, string> $consumed
     *
     * @return array{alias: string|null, having: string|null, group: bool, join: bool, default: string|null}
     */
    private function scanModifiers(array $modifiers, string $field, array $allowed, array $consumed): array
    {
        $result = ['alias' => null, 'having' => null, 'group' => false, 'join' => false, 'default' => null];

        foreach ($modifiers as $modifier => $values) {
            if (in_array($modifier, $consumed, true)) {
                continue;
            }

            if (!in_array($modifier, $allowed, true)) {
                throw ValidationException::withMessages([
                    'aggregate' => "The aggregate expression '$field' is not valid: '@{$modifier}' is not allowed here.",
                ]);
            }

            $value = $values[0] ?? '';

            match ($modifier) {
                'alias' => $result['alias'] = $this->requireAliasValue($value, $field),
                'having' => $result['having'] = $this->requireValue($value, $field, 'having'),
                'default' => $result['default'] = $this->requireValue($value, $field, 'default'),
                'group' => $result['group'] = true,
                default => throw new RuntimeException("The aggregate modifier '@{$modifier}' is not supported."), // @codeCoverageIgnore
            };
        }

        return $result;
    }

    private function requireAliasValue(string $value, string $field): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid.",
            ]);
        }

        return $value;
    }

    private function requireValue(string $value, string $field, string $modifier): string
    {
        if ($value === '') {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '$field' is not valid: '@{$modifier}' requires a value.",
            ]);
        }

        return $value;
    }

    /**
     * @param array<int, FilterClause> $tokens already-parsed via `Parser::transform()`
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query): void
    {
        assert($builder instanceof Builder);

        $parsedItems = $this->build($tokens);

        if ($errors = $this->validate($parsedItems, $query->aggregateBy())) {
            throw ValidationException::withMessages($errors);
        }

        $this->applyParsed($builder, $parsedItems);
    }

    /**
     * @param array<int, AggregateItem> $parsedItems
     * @param array<string> $allowed
     *
     * @return array<string, array<string>>
     */
    public function validate(array $parsedItems, array $allowed): array
    {
        $messages = [];

        foreach ($parsedItems as $parsed) {
            $field = $parsed['field'];

            if ($field === '*') {
                continue;
            }

            if (!in_array($field, $allowed, true)) {
                $messages[] = ($parsed['join'] ?? false)
                    ? "The relation '{$field}' is not allowed on query aggregate."
                    : "The field '{$field}' is not allowed on query aggregate.";
            }
        }

        // Joining and aggregating two different one-to-many relations in the
        // same query would multiply rows across both joins and silently
        // corrupt every aggregate (the classic SQL "fan-out" problem) - so
        // only one relation may be joined via `:@join` per request. Multiple
        // items (group and/or aggregate) naming that *same* relation are
        // fine - they share a single physical join (see
        // `resolveSharedJoinRelatedModel()`). Additional, different relations
        // can still be aggregated via the existing, subquery-based
        // `include=relation:@count` mechanism instead.
        $joinRelations = array_unique(array_column(array_filter($parsedItems, fn (array $parsed) => $parsed['join'] ?? false), 'field'));

        if (count($joinRelations) > 1) {
            $messages[] = "Only one relation can be joined via ':@join' per query aggregate.";
        }

        return $messages ? ['aggregate' => $messages] : [];
    }

    /**
     * @param Builder<Model> $builder
     * @param array<int, AggregateItem> $parsedItems
     */
    protected function applyParsed(Builder $builder, array $parsedItems): void
    {
        /** @var array<int, AggregateColumn> $groupColumns */
        $groupColumns = [];
        /** @var array<int, AggregateColumn> $selectColumns */
        $selectColumns = [];
        /** @var array<int, int|float|string> $selectBindings */
        $selectBindings = [];

        $relatedModel = $this->resolveSharedJoinRelatedModel($builder, $parsedItems);

        foreach ($parsedItems as $parsed) {
            match ($parsed['role']) {
                'group' => $this->applyGroupItem($builder, $parsed, $groupColumns, $selectColumns, $selectBindings, $relatedModel),
                'datepart' => $this->applyDatePartItem($builder, $parsed, $groupColumns, $selectColumns),
                'aggregate' => $this->applyAggregateOrJoinItem($builder, $parsed, $selectColumns, $relatedModel),
            };
        }

        if (!empty($groupColumns)) {
            $builder->groupBy($groupColumns);
        }

        // Bindings must be added after the columns that reference them, so
        // they line up positionally with the `?` placeholders in
        // `$selectColumns` (e.g. from `:@default(value)`).
        $builder->addSelect($selectColumns);

        foreach ($selectBindings as $binding) {
            $builder->getQuery()->addBinding($binding, 'select');
        }
    }

    /**
     * Resolve and join the single relation referenced by `:@join` items,
     * exactly once, regardless of how many items (group and/or aggregate)
     * reference it - `validate()` already guarantees at most one distinct
     * relation name is ever involved, so each item building its own join
     * independently would just join the same table redundantly.
     *
     * @param Builder<Model> $builder
     * @param array<int, AggregateItem> $parsedItems
     */
    private function resolveSharedJoinRelatedModel(Builder $builder, array $parsedItems): ?Model
    {
        foreach ($parsedItems as $parsed) {
            if ($parsed['join'] ?? false) {
                $related = $this->resolveAggregateJoinRelation($builder->getModel(), $parsed['field']);

                $this->applyAggregateJoin($builder, $related);

                return $related->getModel();
            }
        }

        return null;
    }

    /**
     * @param Builder<Model> $builder
     * @param AggregateItem $parsed
     * @param array<int, AggregateColumn> $groupColumns
     * @param array<int, AggregateColumn> $selectColumns
     * @param array<int, int|float|string> $selectBindings
     */
    private function applyGroupItem(Builder $builder, array $parsed, array &$groupColumns, array &$selectColumns, array &$selectBindings, ?Model $relatedModel): void
    {
        if ($parsed['join'] ?? false) {
            assert($relatedModel !== null);
            assert(($parsed['groupColumn'] ?? null) !== null);

            $qualified = $relatedModel->qualifyColumn($parsed['groupColumn']);
            $fallbackAlias = $parsed['groupColumn'];
        } else {
            $qualified = $builder->qualifyColumn($parsed['field']);
            $fallbackAlias = $parsed['field'];
        }

        $default = $parsed['default'] ?? null;

        // Grouping stays on the raw column (not a `COALESCE(...)` wrapper the
        // select expression below might apply) - a `NULL` group key still
        // collapses into one bucket exactly like today, this only changes
        // how that bucket is *displayed*. Postgres permits selecting an
        // expression that's functionally dependent on a grouped column
        // without also needing that expression itself in `GROUP BY`.
        $groupColumns[] = $qualified;

        if ($default === null) {
            $selectColumns[] = $parsed['alias'] !== null ? "{$qualified} as {$parsed['alias']}" : $qualified;

            return;
        }

        $wrapped = $builder->getQuery()->getGrammar()->wrap($qualified);
        $alias = $parsed['alias'] ?? $fallbackAlias;

        // @phpstan-ignore-next-line argument.type (Expression<TValue of literal-string> - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
        $selectColumns[] = new Expression("COALESCE({$wrapped}, ?) as \"{$alias}\"");
        $selectBindings[] = $this->parseGroupDefaultValue($default);
    }

    /**
     * `@default(value)`'s raw captured string, typed for binding: an
     * integer-looking value binds as an int, any other numeric-looking value
     * binds as a float, anything else binds as a plain string - so
     * `COALESCE(column, 0)` and `COALESCE(column, 'unknown')` both render
     * with the SQL-appropriate literal shape instead of everything becoming
     * a quoted string.
     */
    private function parseGroupDefaultValue(string $raw): int|float|string
    {
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }

        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return $raw;
    }

    /**
     * @param Builder<Model> $builder
     * @param AggregateItem $parsed
     * @param array<int, AggregateColumn> $groupColumns
     * @param array<int, AggregateColumn> $selectColumns
     */
    private function applyDatePartItem(Builder $builder, array $parsed, array &$groupColumns, array &$selectColumns): void
    {
        assert($parsed['function'] !== null);

        $expressionSql = $this->datePartExpression($builder, $parsed['function'], $parsed['field']);
        $alias = $parsed['alias'] ?? $this->defaultAlias($parsed['field'], $parsed['function'], true);

        $selectColumns[] = $this->aliased($expressionSql, $alias);

        // Group by the raw expression, not the alias - Postgres (the target
        // driver here) doesn't universally support grouping by a `SELECT` alias.
        if ($parsed['group']) {
            // @phpstan-ignore-next-line argument.type (Expression<TValue of literal-string> - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
            $groupColumns[] = new Expression($expressionSql);
        }
    }

    /**
     * @param Builder<Model> $builder
     * @param AggregateItem $parsed
     * @param array<int, AggregateColumn> $selectColumns
     */
    private function applyAggregateOrJoinItem(Builder $builder, array $parsed, array &$selectColumns, ?Model $relatedModel): void
    {
        if ($parsed['join'] ?? false) {
            assert($relatedModel !== null);

            $this->applyJoinAggregateItem($builder, $parsed, $selectColumns, $relatedModel);

            return;
        }

        $this->applyAggregateItem($builder, $parsed, $selectColumns);
    }

    /**
     * @param Builder<Model> $builder
     * @param AggregateItem $parsed
     * @param array<int, AggregateColumn> $selectColumns
     */
    private function applyAggregateItem(Builder $builder, array $parsed, array &$selectColumns): void
    {
        assert($parsed['function'] !== null);

        $expressionSql = $this->aggregateColumnExpression($builder, $parsed['function'], $parsed['field'], $parsed['distinct'] ?? false);
        $alias = $parsed['alias'] ?? $this->defaultAlias($parsed['field'], $parsed['function'], false);

        $selectColumns[] = $this->aliased($expressionSql, $alias);

        if ($parsed['having'] !== null) {
            // @phpstan-ignore-next-line argument.type (Expression<TValue of literal-string> - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
            $this->applyHaving($builder, new Expression($expressionSql), $parsed['having']);
        }
    }

    /**
     * Aggregate a column on a related table via a real `LEFT JOIN`, instead
     * of `applyAggregateItem()`'s correlated-subquery-free base-model
     * expression. `$parsed['field']` is the relation name here, not a
     * base-model column. `$relatedModel` is already resolved and joined by
     * `resolveSharedJoinRelatedModel()`.
     *
     * @param Builder<Model> $builder
     * @param AggregateItem $parsed
     * @param array<int, AggregateColumn> $selectColumns
     */
    private function applyJoinAggregateItem(Builder $builder, array $parsed, array &$selectColumns, Model $relatedModel): void
    {
        assert($parsed['function'] !== null);

        $column = $parsed['joinColumn'] ?? $this->defaultJoinCountColumn($relatedModel);

        $expressionSql = $this->aggregateRelationColumnExpression($builder, $relatedModel, $parsed['function'], $column, $parsed['distinct'] ?? false);
        $alias = $parsed['alias'] ?? $this->defaultAlias($parsed['field'], $parsed['function'], false);

        $selectColumns[] = $this->aliased($expressionSql, $alias);

        if ($parsed['having'] !== null) {
            // @phpstan-ignore-next-line argument.type (Expression<TValue of literal-string> - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
            $this->applyHaving($builder, new Expression($expressionSql), $parsed['having']);
        }
    }

    /**
     * Apply a `@having(expr)` condition directly against the aggregate's own
     * expression - the expression is already unambiguous (it's whatever this
     * one item just built), so there's no alias to look up, unlike a normal
     * `filter=` clause.
     *
     * @param Builder<Model> $builder
     * @param Expression<literal-string|int|float> $expression
     */
    private function applyHaving(Builder $builder, Expression $expression, string $havingExpr): void
    {
        $clause = app(Filtering::class)->parseHavingExpression($havingExpr);
        $operator = $clause['operator'];
        $value = $clause['value'];

        // Unlike `where()`, `having()` doesn't auto-convert `operator '=' +
        // value === null` into `IS NULL` - handle it explicitly, covering
        // both the plain `null` keyword and the `filled`/`!=` negations.
        if ($value === null) {
            match ($operator) {
                '=' => $this->applyHavingNull($builder, $expression, not: false),
                '!=', 'filled' => $this->applyHavingNull($builder, $expression, not: true),
                default => throw new RuntimeException("The having operator '{$operator}' is not supported with a null value."), // @codeCoverageIgnore
            };

            return;
        }

        match (true) {
            in_array($operator, Filtering::COMPARISON_OPERATORS, true) => $this->applyHavingComparison($builder, $expression, $operator, $value),
            $operator === 'not between' => $this->applyHavingBetween($builder, $expression, $value, not: true),
            $operator === 'between' => $this->applyHavingBetween($builder, $expression, $value, not: false),
            $operator === 'not in' => $this->applyHavingIn($builder, $expression, $value, true),
            $operator === 'in' => $this->applyHavingIn($builder, $expression, $value, false),
            $operator === 'not like' => $this->applyHavingLike($builder, $expression, $value, not: true),
            $operator === 'like' => $this->applyHavingLike($builder, $expression, $value, not: false),
            default => throw new RuntimeException("The having operator '{$operator}' is not supported."), // @codeCoverageIgnore
        };
    }

    /**
     * @param Builder<Model> $builder
     * @param Expression<literal-string|int|float> $expression
     */
    private function applyHavingNull(Builder $builder, Expression $expression, bool $not): void
    {
        // @phpstan-ignore-next-line argument.type (havingNull()/havingNotNull() only declare array|string, unlike having() - Laravel's grammar handles Expression for both the same way)
        $not ? $builder->havingNotNull($expression) : $builder->havingNull($expression);
    }

    /**
     * @param Builder<Model> $builder
     * @param Expression<literal-string|int|float> $expression
     */
    private function applyHavingComparison(Builder $builder, Expression $expression, string $operator, mixed $value): void
    {
        if (is_bool($value)) {
            $value = (int) $value;
        }

        assert(is_int($value) || is_float($value) || is_string($value) || $value instanceof \DateTimeInterface);

        $builder->having($expression, $operator, $value);
    }

    /**
     * @param Builder<Model> $builder
     * @param Expression<literal-string|int|float> $expression
     */
    private function applyHavingBetween(Builder $builder, Expression $expression, mixed $value, bool $not): void
    {
        assert(is_array($value));

        // @phpstan-ignore-next-line argument.type (havingBetween() only declares string, unlike having() - Laravel's grammar handles Expression for both the same way)
        $not ? $builder->havingBetween($expression, $value, 'and', true) : $builder->havingBetween($expression, $value);
    }

    /**
     * @param Builder<Model> $builder
     * @param Expression<literal-string|int|float> $expression
     */
    private function applyHavingLike(Builder $builder, Expression $expression, mixed $value, bool $not): void
    {
        assert(is_string($value));

        $builder->having($expression, $not ? 'not like' : 'like', $value);
    }

    /**
     * `having()` has no `in`/`not in` counterpart, so the clause is built by
     * hand: the expression is inlined as raw SQL (it only ever contains a
     * whitelisted function name and a qualified column) while the values
     * stay parameter-bound via `havingRaw()`.
     *
     * @param Builder<Model> $builder
     * @param Expression<literal-string|int|float> $expression
     */
    private function applyHavingIn(Builder $builder, Expression $expression, mixed $value, bool $negate): void
    {
        assert(is_array($value));

        $grammar = $builder->getQuery()->getGrammar();

        $sql = $grammar->getValue($expression)
            . ($negate ? ' not in (' : ' in (')
            . implode(', ', array_fill(0, count($value), '?'))
            . ')';

        // @phpstan-ignore-next-line argument.type (havingRaw() requires a literal-string - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
        $builder->havingRaw($sql, $value);
    }
}
