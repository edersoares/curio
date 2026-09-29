<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        $related = $model->isRelation($relation) ? $model->{$relation}() : null;

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
        $desc = $token['negated'];
        $modifiers = $token['modifiers'];

        if (str_contains($key, '.')) {
            [$relation] = explode('.', $key);

            $model = $builder->getModel();
            $table = $model->getTable();

            $related = $this->resolveSortRelation($model, $relation, $key);

            if ($related instanceof BelongsTo) {
                $builder->join(
                    $related->getModel()->getTable(),
                    $related->getQualifiedOwnerKeyName(),
                    '=',
                    $related->getQualifiedForeignKeyName(),
                );
            }

            if ($related instanceof HasOne) {
                $builder->join(
                    $related->getModel()->getTable(),
                    $related->getQualifiedForeignKeyName(),
                    '=',
                    $related->getQualifiedParentKeyName(),
                );
            }

            $key = str_replace($relation, $related->getModel()->getTable(), $key);
            $builder->addSelect($table . '.*');
        }

        if (array_key_exists('unaccent', $modifiers)) {
            $wrapped = $builder->getQuery()->getGrammar()->wrap($key);

            $direction = $desc ? 'desc' : 'asc';

            // @phpstan-ignore-next-line argument.type (orderByRaw() requires literal-string - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
            $builder->orderByRaw('unaccent(lower(' . $wrapped . ')) ' . $direction);
        } elseif ($desc) {
            $builder->orderByDesc($key);
        } else {
            $builder->orderBy($key);
        }
    }
}
