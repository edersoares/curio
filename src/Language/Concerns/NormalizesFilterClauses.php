<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language\Concerns;

use Dex\Laravel\Curio\Language\Filtering;
use Illuminate\Validation\ValidationException;

/**
 * Turns an already-matched `key`/`operator`/`value` triple into the
 * normalized filter-clause shape - resolving `field:filled`, `has`/`-has`
 * relation params, wildcard-to-`like`, comma-lists-to-`in`, `a..b` ranges,
 * JSON containment, and `-key` negation - plus the quote-stripping shared
 * with free-text token handling. Used by both `Filtering` (its own
 * `parseValue()` and `Filtering::parseHavingExpression()`) and
 * `Parser::transform()`, so the two don't drift out of sync.
 *
 * @phpstan-import-type FilterClauseParams from Filtering
 * @phpstan-import-type NormalizedClause from Filtering
 */
trait NormalizesFilterClauses
{
    /**
     * Turn a single already-matched `key`/`operator`/`value` triple into the
     * normalized filter-clause shape - resolving `field:filled`,
     * `has`/`-has` relation params, wildcard-to-`like`, comma-lists-to-`in`,
     * `a..b` ranges, and `-key` negation.
     *
     * @param array<string, list<string>> $modifiers
     *
     * @return NormalizedClause
     */
    protected function normalizeClause(string $key, string $operator, string $value, bool $negated, array $modifiers = []): array
    {
        $params = [];
        $resolvedValue = $value;

        if ($resolvedValue === 'filled') {
            $operator = 'filled';
            $resolvedValue = null;
        }

        // A JSON object/array value (e.g. `payload:{"user":123}`) targets a
        // `jsonb` column: skip the list/range/wildcard grammar below - which
        // would otherwise split on the value's own internal commas - and use
        // the `@>` containment operator instead of `=`, mirroring how a `->`
        // in the key already gets Laravel's native JSON path support for free.
        if ($resolvedValue !== null && $this->isJsonValue($resolvedValue)) {
            return $this->jsonContainmentClause($key, $negated, $resolvedValue);
        }

        $this->resolveRelationParams($key, $resolvedValue, $modifiers, $params);

        $parsedValue = $this->parseValue($resolvedValue);

        $this->applyRelationOperator($key, $negated, $operator);
        $this->applyWildcardOperator($operator, $parsedValue);
        $this->applyListOperator($operator, $parsedValue);
        $this->applyRangeOperator($negated, $operator, $parsedValue);
        $this->applyNegation($negated, $operator);

        return $params
            ? ['key' => $key, 'operator' => $operator, 'value' => $parsedValue, 'params' => $params]
            : ['key' => $key, 'operator' => $operator, 'value' => $parsedValue];
    }

    /**
     * `payload:{"user":123}` -> `['key' => 'payload', 'operator' => '@>', 'value' => '{"user":123}']`
     * (or `not @>` for `-payload:{...}`).
     *
     * @return NormalizedClause
     */
    private function jsonContainmentClause(string $key, bool $negated, string $value): array
    {
        return [
            'key' => $key,
            'operator' => $negated ? 'not @>' : '@>',
            'value' => $value,
        ];
    }

    /**
     * `has:relation:@count(<operator><count>)` -> validates the `@count`
     * modifier's argument and turns it into `params['relation.*']`, consumed
     * by `applyClause()`'s `has relation`/`not has relation` arms. Any other
     * modifier on a `has:` clause, or the old `has:relation:<operator><count>`
     * form (the operator/count glued directly onto the value, no `@count` -
     * no longer supported), is a validation error.
     *
     * @param array<string, list<string>> $modifiers
     * @param FilterClauseParams $params
     */
    private function resolveRelationParams(string $key, ?string $value, array $modifiers, array &$params): void
    {
        if ($key !== 'has') {
            return;
        }

        $unsupported = array_diff(array_keys($modifiers), ['count']);

        if ($unsupported !== [] || ($modifiers === [] && $value !== null && str_contains($value, ':'))) {
            throw ValidationException::withMessages([
                $key => "The '$value' relation filter is not valid - use 'has:relation:@count(<operator><count>)'.",
            ]);
        }

        if (!isset($modifiers['count'])) {
            return;
        }

        $arg = $modifiers['count'][0] ?? '';

        if (!preg_match('/^(?P<operator>>=|<=|>|<|=)?(?P<count>\d+)$/', $arg, $matches)) {
            throw ValidationException::withMessages([
                $key => "The operator/count '$arg' is not allowed on the '$value' relation.",
            ]);
        }

        if ($matches['operator'] !== '') {
            $params['relation.operator'] = $matches['operator'];
        }

        $params['relation.count'] = (int) $matches['count'];
    }

    private function applyRelationOperator(string $key, bool $negated, string &$operator): void
    {
        if ($key === 'has') {
            $operator = $negated ? 'not has relation' : 'has relation';
        }
    }

    private function applyWildcardOperator(string &$operator, mixed &$parsedValue): void
    {
        if (!is_string($parsedValue) || (!str_starts_with($parsedValue, '*') && !str_ends_with($parsedValue, '*'))) {
            return;
        }

        $operator = 'like';

        if (str_starts_with($parsedValue, '*')) {
            $parsedValue = '%' . substr($parsedValue, 1);
        }

        if (str_ends_with($parsedValue, '*')) {
            $parsedValue = substr($parsedValue, 0, -1) . '%';
        }
    }

    private function applyListOperator(string &$operator, mixed $parsedValue): void
    {
        if (is_array($parsedValue)) {
            $operator = 'in';
        }
    }

    private function applyRangeOperator(bool $negated, string &$operator, mixed &$parsedValue): void
    {
        if (!is_string($parsedValue) || !str_contains($parsedValue, '..')) {
            return;
        }

        [$start, $end] = explode('..', $parsedValue, 2);

        if ($start !== '' && $end === '') {
            $operator = '>=';
            $parsedValue = $this->parseValue($start);
        } elseif ($start === '' && $end !== '') {
            $operator = '<=';
            $parsedValue = $this->parseValue($end);
        } else {
            $parsedValue = [$this->parseValue($start), $this->parseValue($end)];
            $operator = 'between';
        }
    }

    private function applyNegation(bool $negated, string &$operator): void
    {
        if (!$negated) {
            return;
        }

        if ($operator === 'in') {
            $operator = 'not in';
        }

        if ($operator === '=') {
            $operator = '!=';
        }

        if ($operator === 'like') {
            $operator = 'not like';
        }

        if ($operator === 'between') {
            $operator = 'not between';
        }
    }

    /**
     * Strip a wrapping pair of double quotes, if the whole value is quoted
     * (e.g. `"Something: crazy!"` -> `Something: crazy!`). Leaves the value
     * untouched otherwise, including a value that merely contains quotes.
     */
    protected function stripQuotes(string $value): string
    {
        if (strlen($value) > 1 && $value[0] === '"' && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private function isJsonValue(string $value): bool
    {
        $isObjectOrArray = (str_starts_with($value, '{') && str_ends_with($value, '}'))
            || (str_starts_with($value, '[') && str_ends_with($value, ']'));

        return $isObjectOrArray && json_validate($value);
    }

    private function parseValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (str_contains($value, ',')) {
            preg_match_all('/"([^"]*)"|([^,]+)/', $value, $matches);

            $items = array_map(
                fn ($quoted, $unquoted) => trim($quoted !== '' ? $quoted : $unquoted),
                $matches[1],
                $matches[2]
            );

            return array_map(fn (string $v) => $this->parseValue($v), $items);
        }

        $value = $this->stripQuotes($value);

        if ($value === 'null') {
            return null;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }
}
