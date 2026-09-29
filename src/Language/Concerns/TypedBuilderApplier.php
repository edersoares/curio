<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

trait TypedBuilderApplier
{
    /**
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    private function applyBetween(Builder|Relation $builder, string $key, mixed $value, bool $not): void
    {
        assert(is_array($value));

        $not ? $builder->whereNotBetween($key, $value) : $builder->whereBetween($key, $value);
    }

    /**
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    private function applyLike(Builder|Relation $builder, string $key, mixed $value, bool $not): void
    {
        assert(is_string($value));

        $not ? $builder->whereNotLike($key, $value) : $builder->whereLike($key, $value);
    }

    /**
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    private function applyHasRelation(Builder|Relation $builder, mixed $value, string $operator, int $count): void
    {
        assert(is_string($value));

        $builder->whereHas($value, operator: $operator, count: $count);
    }

    /**
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    private function applyJsonContains(Builder|Relation $builder, string $key, mixed $value, bool $not): void
    {
        assert(is_string($value));

        $decoded = json_decode($value, true);

        $not ? $builder->whereJsonDoesntContain($key, $decoded) : $builder->whereJsonContains($key, $decoded);
    }
}
