<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use RuntimeException;

/**
 * Shared raw-SQL expression building for aggregate functions (`SUM`, `COUNT`,
 * etc.) and date-part transforms (`EXTRACT`/`strftime`), used by both
 * `Selecting` (plain per-row expressions) and `Aggregating` (grouped
 * expressions), so the two don't drift out of sync.
 */
trait BuildsAggregateExpressions
{
    /**
     * Bare `FUNCTION(column)` SQL fragment, without an `as alias` suffix.
     *
     * @param Builder<Model> $builder
     */
    public function aggregateColumnExpression(Builder $builder, string $function, string $field, bool $distinct = false): string
    {
        $column = $field === '*' ? '*' : $builder->getQuery()->getGrammar()->wrap($builder->qualifyColumn($field));

        return $this->wrapAggregateFunction($function, $column, $distinct);
    }

    /**
     * Same shape as `aggregateColumnExpression()`, but qualifies the column
     * against a related model's table instead of the base builder's - used by
     * the `:@join` aggregate modifier, which aggregates a column from a
     * joined relation rather than the base model's own table.
     *
     * @param Builder<Model> $builder
     */
    public function aggregateRelationColumnExpression(Builder $builder, Model $relatedModel, string $function, string $column, bool $distinct = false): string
    {
        $wrapped = $builder->getQuery()->getGrammar()->wrap($relatedModel->qualifyColumn($column));

        return $this->wrapAggregateFunction($function, $wrapped, $distinct);
    }

    private function wrapAggregateFunction(string $function, string $wrappedColumn, bool $distinct): string
    {
        $prefix = $distinct ? 'DISTINCT ' : '';

        return strtoupper($function) . '(' . $prefix . $wrappedColumn . ')';
    }

    private const array EXTRACT_DRIVERS = ['pgsql', 'mysql', 'mariadb'];

    private const array SQLITE_DATE_PART_FORMATS = [
        'year' => '%Y',
        'month' => '%m',
        'day' => '%d',
        'hour' => '%H',
        'minute' => '%M',
        'second' => '%S',
    ];

    /**
     * Date-part extraction syntax differs across drivers: `EXTRACT()` is
     * standard SQL supported by pgsql/mysql/mariadb, but SQLite has no such
     * function and instead needs `strftime()`.
     *
     * @param Builder<Model> $builder
     */
    public function datePartExpression(Builder $builder, string $function, string $column): string
    {
        $connection = $builder->getQuery()->getConnection();

        assert($connection instanceof Connection);

        $driver = $connection->getDriverName();

        $column = $builder->getQuery()->getGrammar()->wrap($builder->qualifyColumn($column));

        if (in_array($driver, self::EXTRACT_DRIVERS, true)) {
            return 'EXTRACT(' . strtoupper($function) . ' FROM ' . $column . ')';
        }

        if ($driver === 'sqlite' && isset(self::SQLITE_DATE_PART_FORMATS[$function])) {
            $format = self::SQLITE_DATE_PART_FORMATS[$function];

            return "CAST(strftime('{$format}', {$column}) as integer)";
        }

        throw new RuntimeException("The date-part '{$function}' is not supported on the '{$driver}' driver.");
    }

    private function defaultAlias(string $field, string $function, bool $isDatePart): string
    {
        if ($isDatePart) {
            return $field;
        }

        if ($field === '*') {
            return $function;
        }

        return $field . '_' . $function;
    }

    /**
     * Wrap a raw SQL expression with a quoted `as "alias"` suffix, as a
     * bindable `Expression` - the one shared way `Selecting` and
     * `Aggregating` alias their raw expressions, instead of each building
     * the "expr as alias" string by hand in a slightly different style.
     *
     * @return Expression<literal-string|int|float>
     */
    public function aliased(string $expression, string $alias): Expression
    {
        // @phpstan-ignore-next-line argument.type (Expression<TValue of literal-string> - Laravel generic bound, not satisfiable by SQL built from a runtime column/alias name)
        return new Expression("{$expression} as \"{$alias}\"");
    }
}
