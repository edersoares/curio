<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type SortToken array{key: string, negated: bool, modifiers: array<string, list<string>>}
 * @phpstan-import-type FilterClause from Filtering
 */
class Sorting implements Applier
{
    /**
     * @param array<int, FilterClause> $tokens already-parsed via `Parser::transform()`
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query): void
    {
        assert($builder instanceof Builder);

        $tokens = $this->build($tokens);

        if ($errors = $this->validate($tokens, $query->allowedSortColumns())) {
            throw ValidationException::withMessages($errors);
        }

        $this->applyTokens($builder, $tokens);
    }

    /**
     * @param array<int, SortToken> $tokens
     * @param array<string> $allowed
     *
     * @return array<string, array<string>>
     */
    public function validate(array $tokens, array $allowed): array
    {
        if (empty($allowed)) {
            return ['sort' => ['There is no allowed fields to sort.']];
        }

        $reject = array_filter($tokens, fn (array $token) => !in_array($token['key'], $allowed, true));

        if ($reject === []) {
            return [];
        }

        $fields = implode(', ', array_map(fn (array $token) => $token['key'], $reject));

        return ['sort' => ["The fields '$fields' is not allowed to sort."]];
    }

    /**
     * Builds the final `SortToken` shape from already-tokenized fields (via
     * `Parser::transform()`, e.g. `-ranking name:@unaccent`). Splits a
     * leading `-` (descending) from the key and carries any modifiers
     * through untouched - `Sorting` doesn't know what any of them mean;
     * that's up to a `SortTokenApplying` listener at apply time (see
     * `applySort()`).
     *
     * @param array<int, FilterClause> $tokens
     *
     * @return array<int, SortToken>
     */
    public function build(array $tokens): array
    {
        return $tokens;
    }

    /**
     * @param Builder<Model> $builder
     * @param array<int, SortToken> $tokens
     */
    protected function applyTokens(Builder $builder, array $tokens): void
    {
        foreach ($tokens as $token) {
            $this->applySort($builder, $token);
        }
    }

    /**
     * A dotted sort key names a relation and one of its columns, and the
     * relation half is resolved by calling it on the model - but
     * `Model::__call()` forwards any name it doesn't know to the query
     * builder, so a key that only *looks* like a relation would happily
     * invoke a builder method (`truncate`, ...) with side effects of its own.
     * `isRelation()` is the same check Eloquent uses to tell a relation from
     * an attribute (it also covers relations registered via
     * `Model::resolveRelationUsing()`, which `method_exists()` would miss),
     * and the returned value is verified at runtime - not via `assert()`,
     * which is compiled out in production, exactly where this matters.
     *
     * @return Relation<Model, Model, mixed>
     */
    private function resolveSortRelation(Model $model, string $relation, string $key): Relation
    {
        $related = $model->isRelation($relation)
            ? Relation::noConstraints(fn () => $model->{$relation}())
            : null;

        if (!$related instanceof Relation) {
            throw ValidationException::withMessages([
                'sort' => ["The field '$key' is not allowed to sort."],
            ]);
        }

        return $related;
    }

    /**
     * @param Builder<Model> $builder
     * @param SortToken $token
     */
    private function applySort(Builder $builder, array $token): void
    {
        $key = $token['key'];
        $direction = $token['negated'] ? 'desc' : 'asc';
        $unaccent = array_key_exists('unaccent', $token['modifiers']);

        if (str_contains($key, '.')) {
            $this->applyRelationSort($builder, $key, $direction, $unaccent);

            return;
        }

        if ($unaccent) {
            $wrapped = $builder->getQuery()->getGrammar()->wrap($key);

            // @phpstan-ignore-next-line argument.type (orderByRaw() requires literal-string - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
            $builder->orderByRaw('unaccent(lower(' . $wrapped . ')) ' . $direction);

            return;
        }

        $builder->orderBy($key, $direction);
    }

    /**
     * Orders by a related model's column through a correlated subquery, not
     * a `JOIN`. Sorting is supposed to change the order of the rows and
     * nothing else, and a join can't promise that: an `INNER JOIN` on a
     * `HasOne` repeated the parent once per related row (`latestPost` is a
     * `hasOne` over a table holding many posts per author) and dropped every
     * parent with no related row at all, so `sort=` changed both the rows on
     * the page and the paginator's total. It also had to add `table.*` to
     * the `SELECT` to keep the joined table's columns from overwriting the
     * parent's, which silently undid a `select=` sent with it.
     *
     * A subquery limited to one row yields exactly one value per parent
     * (`NULL` when there is no related row), and it carries the relation's
     * own constraints and ordering - which is what makes `latestPost.title`
     * sort by the *latest* post rather than by an arbitrary one.
     *
     * @param Builder<Model> $builder
     * @param 'asc'|'desc' $direction
     */
    private function applyRelationSort(Builder $builder, string $key, string $direction, bool $unaccent): void
    {
        [$relation, $column] = explode('.', $key, 2);

        $this->guardRelationSort($builder, $key, $column);

        $related = $this->resolveSortRelation($builder->getModel(), $relation, $key);

        $subquery = $related
            ->getRelationExistenceQuery($related->getRelated()->newQuery(), $builder, [])
            ->mergeConstraintsFrom($related->getQuery())
            ->toBase();

        // A self-referencing relation is aliased by Eloquent
        // (`"author" as "laravel_reserved_0"`), so the column has to be
        // qualified by whatever the subquery itself calls its table.
        $table = $subquery->from;

        assert(is_string($table));

        $segments = preg_split('/\s+as\s+/i', $table);
        $qualifier = $segments === false ? $table : end($segments);

        $subquery->select($qualifier . '.' . $column);
        $subquery->orders = $related->getQuery()->getQuery()->orders;
        $subquery->limit(1);

        if ($unaccent) {
            // @phpstan-ignore-next-line argument.type (orderByRaw() requires literal-string - Laravel generic bound, not satisfiable by SQL built from a runtime subquery)
            $builder->orderByRaw('unaccent(lower((' . $subquery->toSql() . '))) ' . $direction, $subquery->getBindings());

            return;
        }

        $builder->orderBy($subquery, $direction);
    }

    /**
     * Two shapes a relation sort can't take. A key nested deeper than
     * `relation.column` has no single related table to read the column
     * from. And a query that is already grouped or joined (`aggregate=`)
     * returns one row per group, not per parent, so there is no parent row
     * left for the subquery to correlate with.
     *
     * @param Builder<Model> $builder
     */
    private function guardRelationSort(Builder $builder, string $key, string $column): void
    {
        if (str_contains($column, '.')) {
            throw ValidationException::withMessages([
                'sort' => ["The field '$key' is not allowed to sort: only one relation level is supported."],
            ]);
        }

        $query = $builder->getQuery();

        if (!empty($query->groups) || !empty($query->joins)) {
            throw ValidationException::withMessages([
                'sort' => ["The field '$key' is not allowed to sort: a relation column can't be sorted alongside 'aggregate'."],
            ]);
        }
    }
}
