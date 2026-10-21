<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * `LIKE` patterns built from client input.
 *
 * `*text*` and free-text search turn a value into `%text%`, and the value
 * itself used to go in untouched - so a `%` or `_` the client typed was a
 * wildcard too: `name:*50%_off*` matched "500 off", and searching for `%`
 * matched every row. `escape()` makes them literal.
 *
 * The escape character is `!` rather than `\`: `\` would need different
 * quoting in the `ESCAPE` clause on MySQL (where it escapes the string
 * literal itself) and on PostgreSQL/SQLite (where it doesn't), and SQLite has
 * no default escape character at all, so the clause has to be spelled out.
 * `!` reads the same everywhere.
 *
 * A pattern with nothing to escape keeps going through `whereLike()`, so the
 * SQL for an ordinary value is exactly what it was.
 */
final class Like
{
    public const string ESCAPE = '!';

    /**
     * Makes every `%`, `_` and `!` in `$value` literal.
     */
    public static function escape(string $value): string
    {
        return strtr($value, [
            self::ESCAPE => self::ESCAPE . self::ESCAPE,
            '%' => self::ESCAPE . '%',
            '_' => self::ESCAPE . '_',
        ]);
    }

    /**
     * Whether `$pattern` carries an escape sequence and so needs an
     * `ESCAPE` clause - every `!` in a pattern built by this class is one,
     * since a literal `!` is escaped as `!!`.
     */
    public static function isEscaped(string $pattern): bool
    {
        return str_contains($pattern, self::ESCAPE);
    }

    /**
     * Case-insensitive `[NOT] LIKE`, like `whereLike()`, with an `ESCAPE`
     * clause whenever `$pattern` needs one.
     *
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     * @param 'and'|'or' $boolean
     */
    public static function where(Builder|Relation $builder, string $column, string $pattern, bool $not = false, string $boolean = 'and'): void
    {
        if ($builder instanceof Relation) {
            $builder = $builder->getQuery();
        }

        if (!self::isEscaped($pattern)) {
            $builder->whereLike($column, $pattern, false, $boolean, $not);

            return;
        }

        $grammar = $builder->getQuery()->getGrammar();
        $postgres = $grammar instanceof PostgresGrammar;

        $sql = sprintf(
            '%s%s %s%s ? escape \'%s\'',
            $grammar->wrap($column),
            $postgres ? '::text' : '',
            $not ? 'not ' : '',
            $postgres ? 'ilike' : 'like',
            self::ESCAPE,
        );

        // @phpstan-ignore-next-line argument.type (whereRaw() requires a literal-string - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
        $builder->whereRaw($sql, [$pattern], $boolean);
    }

    /**
     * The `ESCAPE` clause to append to a hand-written `LIKE` - empty when
     * `$pattern` doesn't need one.
     */
    public static function clause(string $pattern): string
    {
        return self::isEscaped($pattern) ? " escape '" . self::ESCAPE . "'" : '';
    }
}
