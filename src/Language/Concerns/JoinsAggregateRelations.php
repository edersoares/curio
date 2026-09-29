<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * Resolves an Eloquent relation for the `:@join` aggregate modifier and adds
 * the matching `LEFT JOIN` to the builder. Deliberately duplicates (rather
 * than shares) `Sorting`'s relation-join key resolution: `Sorting` only ever
 * needs `BelongsTo`/`HasOne` (single related row per parent, required for a
 * safe `ORDER BY`), while this trait also needs `HasMany` - safe here because
 * the caller always follows the join with a `GROUP BY`, which collapses the
 * duplicated parent rows a one-to-many join produces.
 *
 * @phpstan-type SupportedJoinRelation BelongsTo<Model, Model>|HasOneOrMany<Model, Model, mixed>
 */
trait JoinsAggregateRelations
{
    /**
     * @return SupportedJoinRelation
     */
    private function resolveAggregateJoinRelation(Model $model, string $relationName): BelongsTo|HasOneOrMany
    {
        // `aggregateBy()` lists plain columns and relation names side by
        // side, so `:@join` can name either - and an unknown name would be
        // forwarded by `Model::__call()` to the query builder and invoked
        // there, so this has to be a real runtime check (not `assert()`,
        // which production compiles out) rather than a cast.
        $related = $model->isRelation($relationName) ? $model->{$relationName}() : null;

        if (!$related instanceof Relation) {
            throw ValidationException::withMessages([
                'aggregate' => "The aggregate expression '{$relationName}' is not valid: ':@join' requires a relation name.",
            ]);
        }

        // `MorphTo extends BelongsTo` and `MorphOneOrMany extends HasOneOrMany`,
        // so these checks must run before the generic `BelongsTo`/`HasOneOrMany`
        // ones below - otherwise a polymorphic relation would silently be
        // treated as a plain one, producing a join missing the required
        // `*_type` predicate.
        if ($related instanceof MorphTo || $related instanceof MorphOneOrMany) {
            throw ValidationException::withMessages([
                'aggregate' => "The relation '{$relationName}' is polymorphic and is not supported by ':@join'.",
            ]);
        }

        if ($related instanceof BelongsToMany) {
            throw ValidationException::withMessages([
                'aggregate' => "The relation '{$relationName}' is many-to-many and is not supported by ':@join' (it would require an extra pivot-table join); use 'include={$relationName}:@count' instead.",
            ]);
        }

        if ($related instanceof BelongsTo || $related instanceof HasOneOrMany) {
            return $related;
        }

        throw ValidationException::withMessages([
            'aggregate' => "The relation '{$relationName}' is not supported by ':@join' (only belongsTo/hasOne/hasMany relations are supported).",
        ]);
    }

    /**
     * @param Builder<Model> $builder
     * @param SupportedJoinRelation $related
     */
    private function applyAggregateJoin(Builder $builder, BelongsTo|HasOneOrMany $related): void
    {
        if ($related instanceof BelongsTo) {
            $builder->leftJoin(
                $related->getModel()->getTable(),
                $related->getQualifiedOwnerKeyName(),
                '=',
                $related->getQualifiedForeignKeyName(),
            );

            return;
        }

        $builder->leftJoin(
            $related->getModel()->getTable(),
            $related->getQualifiedForeignKeyName(),
            '=',
            $related->getQualifiedParentKeyName(),
        );
    }

    private function defaultJoinCountColumn(Model $relatedModel): string
    {
        return $relatedModel->getKeyName();
    }
}
