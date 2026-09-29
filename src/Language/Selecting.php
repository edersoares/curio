<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Language\Concerns\BuildsAggregateExpressions;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type SelectItem array{field: string, function: string|null, alias: string|null, raw: bool}
 * @phpstan-import-type FilterClause from Filtering
 */
class Selecting implements Applier
{
    use BuildsAggregateExpressions;

    /**
     * @param array<int, FilterClause> $tokens already-parsed via `Parser::transform()`
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query): void
    {
        assert($builder instanceof Builder);

        $parsedItems = $this->build($tokens);

        if ($errors = $this->validate($parsedItems, $query->selectBy())) {
            throw ValidationException::withMessages($errors);
        }

        $this->applyParsed($builder, $parsedItems);
    }

    /**
     * @param array<int, SelectItem> $parsedItems
     * @param array<string> $allowed
     *
     * @return array<string, array<string>>
     */
    public function validate(array $parsedItems, array $allowed): array
    {
        if (empty($allowed)) {
            return ['select' => ['There is no allowed fields to select.']];
        }

        $reject = array_filter($parsedItems, fn (array $item) => !in_array($item['field'], $allowed, true));

        if ($reject === []) {
            return [];
        }

        $fields = implode(', ', array_map(fn (array $item) => $item['field'], $reject));

        return ['select' => ["The fields '$fields' is not allowed to select."]];
    }

    /**
     * @param Builder<Model> $builder
     * @param array<int, SelectItem> $parsedItems
     */
    protected function applyParsed(Builder $builder, array $parsedItems): void
    {
        $columns = [];

        foreach ($parsedItems as $parsed) {
            if ($parsed['raw']) {
                $columns[] = $this->buildExpression($builder, $parsed);
                continue;
            }

            $field = $parsed['field'];

            // If the field is a relation, skip column name qualification
            if (str_contains($field, '.')) {
                continue;
            }

            $column = $builder->qualifyColumn($field);

            $columns[] = $parsed['alias'] !== null ? "{$column} as {$parsed['alias']}" : $column;
        }

        if (empty($columns)) {
            return;
        }

        $builder->addSelect($columns);
    }

    /**
     * Build a select item from an already-tokenized field (via
     * `Parser::transform()`), describing whether it's a plain column, or a
     * date-part transform (`field:@function`) with an optional
     * `:@alias(name)` modifier. Aggregate functions (`@count`/`@sum`/etc.)
     * are not selectable here - see `Aggregating` for those. The date-part
     * function, if present, must be the first modifier right after the
     * field (same as `field:@function` always did) - anything else in that
     * position is treated as a plain field with an unrecognized modifier and
     * rejected below. `selectBy()` allow-list enforcement happens separately,
     * in `validate()`.
     *
     * @param array<int, FilterClause> $tokens
     *
     * @return array<int, SelectItem>
     */
    public function build(array $tokens): array
    {
        $result = [];

        foreach ($tokens as $token) {
            $modifiers = $token['modifiers'];

            if ($token['operator'] !== null || !preg_match('/^[\w.]+$/', $token['key'])) {
                throw ValidationException::withMessages([
                    'select' => "The select expression '{$token['key']}' is not valid.",
                ]);
            }

            $first = array_key_first($modifiers);
            $function = $first !== null && in_array($first, Aggregating::DATE_PARTS, true) ? $first : null;

            if ($function !== null && ($modifiers[$function][0] ?? '') !== '') {
                throw ValidationException::withMessages([
                    'select' => "The select expression '{$token['key']}' is not valid: '@{$function}' does not accept arguments.",
                ]);
            }

            $alias = null;

            foreach ($modifiers as $modifier => $values) {
                if ($modifier === $function) {
                    continue;
                }

                match ($modifier) {
                    'alias' => $alias = $this->requireAliasValue($values[0] ?? '', $token['key']),
                    default => throw ValidationException::withMessages([
                        'select' => "The select expression '{$token['key']}' is not valid: '@{$modifier}' is not allowed here.",
                    ]),
                };
            }

            $result[] = [
                'field' => $token['key'],
                'function' => $function,
                'alias' => $alias,
                'raw' => $function !== null,
            ];
        }

        return $result;
    }

    private function requireAliasValue(string $value, string $item): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw ValidationException::withMessages([
                'select' => "The select expression '$item' is not valid.",
            ]);
        }

        return $value;
    }

    /**
     * @param Builder<Model> $builder
     * @param SelectItem $parsed
     *
     * @return Expression<literal-string|int|float>
     */
    private function buildExpression(Builder $builder, array $parsed): Expression
    {
        $field = $parsed['field'];
        $function = $parsed['function'];

        // buildExpression() is only called for parsed items where raw === true,
        // which build() guarantees always sets a non-null (date-part) function.
        assert($function !== null);

        $alias = $parsed['alias'] ?? $this->defaultAlias($field, $function, true);

        return $this->aliased($this->datePartExpression($builder, $function, $field), $alias);
    }
}
