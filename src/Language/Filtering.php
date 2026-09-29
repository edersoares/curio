<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Events\ApplyToken;
use Dex\Laravel\Curio\Language\Concerns\NormalizesFilterClauses;
use Dex\Laravel\Curio\Pipeline;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Dex\Laravel\Curio\Support\Errors;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type FilterClauseParams array{fields?: array<string>, mode?: string, threshold?: float, 'relation.operator'?: string, 'relation.count'?: int}
 * @phpstan-type NormalizedClause array{key: string, operator: string, value: mixed, params?: FilterClauseParams}
 * @phpstan-type FilterClause array{type: string, key: string, operator: string|null, value: mixed, negated: bool, modifiers: array<string, list<string>>, params?: FilterClauseParams}
 */
class Filtering implements Applier
{
    use NormalizesFilterClauses;

    /**
     * Comparison operators allowed to use in `where()` and `having()` methods.
     */
    public const array COMPARISON_OPERATORS = ['=', '<>', '>=', '!=', '>', '<=', '<'];

    /**
     * Apply the filter into builder based on Query rules.
     *
     * @param array<int, FilterClause> $tokens already-parsed via `Parser::transform()`
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     *
     * @throws ValidationException
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query): void
    {
        $filters = $this->coerce($this->build($tokens), $query);

        if ($errors = $this->validate($filters, $query)) {
            throw ValidationException::withMessages($errors);
        }

        $this->applyClauses($filters, $builder);
    }

    /**
     * Rules that declare a field as text, and rules that declare it as a
     * number - what `coerce()` reads to decide which way a value goes.
     */
    private const array STRING_RULES = ['string'];

    private const array NUMERIC_RULES = ['integer', 'numeric', 'decimal'];

    /**
     * The value grammar guesses a type from how a value *looks* (`123` is a
     * number, `true` a boolean), which is right for a field with no declared
     * type and wrong for one that has it: `name:123` reached validation as an
     * integer and failed a `string` rule, so a text column could never be
     * filtered by anything number-shaped - and quoting it didn't help. The
     * field's own `filterBy()` rules settle it instead:
     *
     * - a `string` field always gets a string (`123` -> `'123'`, `true` -> `'true'`);
     * - an `integer`/`numeric`/`decimal` field gets a number for any numeric
     *   string, canonical or not (`007` -> `7`);
     * - a field that declares neither keeps whatever the grammar guessed.
     *
     * `null` is left alone in every case - it is the `IS NULL` keyword, not a
     * value - and so is a field the allow-list doesn't know, which
     * `validate()` rejects right after.
     *
     * @param array<int, FilterClause> $filters
     *
     * @return array<int, FilterClause>
     */
    public function coerce(array $filters, PaginateQuery $query): array
    {
        return array_map(function (array $filter) use ($query) {
            $rules = $this->rulesFor($filter['key'], $query);

            if ($rules !== []) {
                $filter['value'] = $this->coerceValue($filter['value'], $rules);
            }

            return $filter;
        }, $filters);
    }

    /**
     * The rule names (parameters stripped, `decimal:2` -> `decimal`) declared
     * for a field - following a dotted key through `includeBy()` to the
     * related query that owns the leaf field, the same walk
     * `collectRelationFilterErrors()` does.
     *
     * @return array<int, string>
     */
    private function rulesFor(string $key, PaginateQuery $query): array
    {
        $segments = explode('.', $key);
        $field = array_pop($segments);

        $owner = $this->resolveRelationQuery($segments, $query);

        $rules = $owner?->filterBy()[$field] ?? [];

        if (is_string($rules)) {
            $rules = explode('|', $rules); // @codeCoverageIgnore
        }

        return array_map(
            fn (string $rule) => explode(':', $rule, 2)[0],
            array_values(array_filter($rules, is_string(...))),
        );
    }

    /**
     * @param array<int, string> $relations
     */
    private function resolveRelationQuery(array $relations, PaginateQuery $query): ?PaginateQuery
    {
        foreach ($relations as $relation) {
            $queryClass = $query->includeBy()[$relation] ?? null;

            if ($queryClass === null) {
                return null;
            }

            $query = app($queryClass);
            assert($query instanceof PaginateQuery);
        }

        return $query;
    }

    /**
     * @param array<int, string> $rules
     */
    private function coerceValue(mixed $value, array $rules): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->coerceValue($item, $rules), $value);
        }

        if (array_intersect(self::STRING_RULES, $rules) !== []) {
            return match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value), is_float($value) => (string) $value,
                default => $value,
            };
        }

        if (array_intersect(self::NUMERIC_RULES, $rules) !== [] && is_string($value) && is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }

    /**
     * @param array<int, FilterClause> $filters
     *
     * @return array<string, array<string>>
     */
    public function validate(array $filters, PaginateQuery $query): array
    {
        $flatFilters = [];
        $relationFilters = [];

        foreach ($filters as $filter) {
            if (str_contains($filter['key'], '.')) {
                $relationFilters[] = $filter;
            } else {
                $flatFilters[] = $filter;
            }
        }

        $allowedRelations = implode(',', array_keys($query->includeBy()));

        $errors = $this->collectFlatFilterErrors($flatFilters, [
            ...$query->filterBy(),

            // `has` keyword for relations
            'has' => ['string', 'in:' . $allowedRelations],
        ]);

        foreach ($relationFilters as $filter) {
            $errors = Errors::merge($errors, $this->collectRelationFilterErrors($filter, $query));
        }

        return $errors;
    }

    /**
     * Every clause is validated on its own, because two clauses can name the
     * same field and both still reach the builder: `ranking:999 ranking:5`
     * applies two `where`s, and `has:truncate has:posts` two `whereHas`es.
     * Collapsing them into one `key => value` payload (as this used to,
     * via `mapWithKeys()`) let the *last* value alone decide whether the
     * whole set passed - so the earlier clauses were applied having never
     * been validated at all, which turned `has`'s `in:` allow-list into a
     * no-op whenever a second, allowed `has` followed it.
     *
     * @param array<int, array{key: string, value: mixed}> $filters
     * @param array<string, string|array<int, string>> $rules
     *
     * @return array<string, array<string>>
     */
    private function collectFlatFilterErrors(array $filters, array $rules): array
    {
        $errors = [];

        foreach ($filters as $filter) {
            $errors = Errors::merge($errors, $this->collectClauseErrors($filter, $rules));
        }

        // A field named twice with the same problem collects the same message
        // twice here, and that's fine: every consumer funnels these through
        // `ValidationException::withMessages()`, and `MessageBag::add()` only
        // keeps a message it doesn't already hold for that key - so the
        // response carries it once without this method de-duplicating
        // anything itself.
        return $errors;
    }

    /**
     * @param array{key: string, value: mixed} $filter
     * @param array<string, string|array<int, string>> $rules
     *
     * @return array<string, array<string>>
     */
    private function collectClauseErrors(array $filter, array $rules): array
    {
        $data = [$filter['key'] => $filter['value']];

        $validator = Validator::make($data, []);

        foreach ($rules as $attribute => $rulesForAttribute) {
            $validator->sometimes($attribute, $rulesForAttribute, fn ($input) => is_scalar($input->{$attribute}));
            $validator->sometimes($attribute, ['array'], fn ($input) => is_array($input->{$attribute}));
            $validator->sometimes($attribute . '.*', $rulesForAttribute, fn ($input) => is_array($input->{$attribute}));
        }

        $errors = $validator->fails() ? $validator->errors()->toArray() : [];

        $missing = collect($data)->diffKeys($rules)->mapWithKeys(fn ($value, $key) => [$key => 'missing']);
        $message = $missing->mapWithKeys(fn ($value, $key) => [$key . '.missing' => "The field '$key' is not allowed on query filter."]);

        $missingValidator = Validator::make($data, $missing->all(), $message->all());

        if ($missingValidator->fails()) {
            $errors = Errors::merge($errors, $missingValidator->errors()->toArray());
        }

        return $errors;
    }

    /**
     * @param FilterClause $filter
     *
     * @return array<string, array<string>>
     */
    private function collectRelationFilterErrors(array $filter, PaginateQuery $query): array
    {
        $segments = explode('.', $filter['key']);
        $leafField = array_pop($segments);
        $maxDepth = $query->maxFilterDepth();

        // Checked before the chain is walked: `includeBy()` can't bound it,
        // since a chain may revisit a query class it already passed through
        // (`posts.author.posts...`), and every level is another nested
        // `EXISTS` - the same reason `include=` has `maxIncludeDepth()`.
        if (count($segments) > $maxDepth) {
            return ['filter' => ["The field '{$filter['key']}' is nested deeper than the maximum filter depth of {$maxDepth}."]];
        }

        $current = $this->resolveRelationQuery($segments, $query);

        if ($current === null) {
            return [$filter['key'] => ["The field '{$filter['key']}' is not allowed on query filter."]];
        }

        return $this->collectFlatFilterErrors(
            [['key' => $leafField, 'value' => $filter['value']]],
            $current->filterBy()
        );
    }

    /**
     * @param FilterClause $filter
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    protected function applyClause(array $filter, Builder|Relation $builder): void
    {
        $token = new Token($filter);

        app(Pipeline::class)->token(new ApplyToken($token, $builder));
    }

    /**
     * Apply into builder the accepted clauses.
     *
     * @param array<int, FilterClause> $filters
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    protected function applyClauses(array $filters, Builder|Relation $builder): void
    {
        foreach ($filters as $filter) {
            $this->applyClause($filter, $builder);
        }
    }

    /**
     * Drop the free-text `search` clause (if any) - `Parser::transform()`
     * merges filter and search tokens into one array since both come from
     * the same `filter=` string, but a `search` clause is `Searching`'s
     * concern, not a key/value filter. `Searching::build()` picks up the
     * mirror-image half of the same array.
     *
     * A token with no operator at all is rejected here: in a `filter=`
     * string that shape is a modifier chain with no value
     * (`ranking:@nope`, or `@count` expanded to `*:@count`), which the
     * key/value grammar has no meaning for. It used to travel all the way to
     * `Listeners\WhereOperator`, whose `default` arm then threw a
     * `RuntimeException` ("The operator '' is not supported.") - a 500 for
     * what is plain invalid input, and only for fields the allow-list
     * accepts, since validation ran before it.
     *
     * @param array<int, FilterClause> $tokens
     *
     * @return array<int, FilterClause>
     */
    public function build(array $tokens): array
    {
        $filters = array_values(array_filter($tokens, fn (array $token) => $token['operator'] !== 'search'));

        foreach ($filters as $filter) {
            if ($filter['operator'] === null) {
                throw ValidationException::withMessages([
                    'filter' => "The filter expression '{$filter['key']}' is not valid.",
                ]);
            }
        }

        return $filters;
    }

    /**
     * Parse a bare operator+value expression (no `key:` prefix) using the
     * same value grammar as a normal filter clause - wildcards, `a..b`
     * ranges, comma lists, `null`/`filled`, and a leading `-` for negation
     * (standing in for the `-key:` prefix, since there's no key here) - so
     * callers scoping a condition to a single already-known target (e.g.
     * `Aggregating`'s `@having(expr)`, which applies directly to one
     * aggregate expression, not a named field) can reuse it without a `key:`
     * to parse against.
     *
     * @throws ValidationException
     *
     * @return NormalizedClause
     */
    public function parseHavingExpression(string $expr): array
    {
        $negated = str_starts_with($expr, '-');

        if ($negated) {
            $expr = substr($expr, 1);
        }

        if (!preg_match('/^(?P<operator>>=|<=|!=|=|>|<)?(?P<value>.+)$/', $expr, $matches)) {
            throw ValidationException::withMessages([
                'aggregate' => "The having expression '$expr' is not valid.",
            ]);
        }

        return $this->normalizeClause('', $matches['operator'] ?: '=', $matches['value'], $negated);
    }
}
