<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Events\ApplyToken;
use Dex\Laravel\Curio\Language\Concerns\NormalizesFilterClauses;
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
        $filters = $this->build($tokens);

        if ($errors = $this->validate($filters, $query)) {
            throw ValidationException::withMessages($errors);
        }

        $this->applyClauses($filters, $builder);
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
        $current = $query;

        foreach ($segments as $segment) {
            $includeBy = $current->includeBy();
            $queryClass = $includeBy[$segment] ?? null;

            if ($queryClass === null) {
                return [$filter['key'] => ["The field '{$filter['key']}' is not allowed on query filter."]];
            }

            $current = app($queryClass);
            assert($current instanceof PaginateQuery);
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

        event(new ApplyToken($token, $builder));
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
