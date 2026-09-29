<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyToken;
use Dex\Laravel\Curio\Language\Concerns\TypedBuilderApplier;
use Dex\Laravel\Curio\Language\Token;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class WhereOperator
{
    use TypedBuilderApplier;

    public const array COMPARISON_OPERATORS = ['=', '<>', '>=', '!=', '>', '<=', '<'];

    public function handle(ApplyToken $apply): void
    {
        $builder = $apply->builder;
        $key = $builder->qualifyColumn($apply->token->key());
        $operator = $apply->token->operator();
        $value = $apply->token->value();
        $hasRelationOperator = $apply->token->param('relation.operator', '>=');
        $hasRelationCount = $apply->token->param('relation.count', 1);

        assert(is_string($hasRelationOperator));
        assert(is_int($hasRelationCount));

        match (true) {
            str_contains($apply->token->key(), '.') => $this->applyWhereRelation($apply),
            in_array($operator, self::COMPARISON_OPERATORS, true) => $builder->where($key, $operator, $value),
            $operator === 'not between' => $this->applyBetween($builder, $key, $value, not: true),
            $operator === 'between' => $this->applyBetween($builder, $key, $value, not: false),
            $operator === 'not in' => $builder->whereNotIn($key, $value),
            $operator === 'in' => $builder->whereIn($key, $value),
            $operator === 'not like' => $this->applyLike($builder, $key, $value, not: true),
            $operator === 'like' => $this->applyLike($builder, $key, $value, not: false),
            $operator === 'has relation' => $this->applyHasRelation($builder, $value, $hasRelationOperator, $hasRelationCount),
            $operator === 'not has relation' => $this->applyHasRelation($builder, $value, '=', 0),
            $operator === 'null' => $builder->whereNull($key),
            $operator === 'filled' => $builder->whereNotNull($key),
            $operator === '@>' => $this->applyJsonContains($builder, $key, $value, not: false),
            $operator === 'not @>' => $this->applyJsonContains($builder, $key, $value, not: true),
            default => throw new RuntimeException("The operator '{$operator}' is not supported."), // @codeCoverageIgnore
        };
    }

    private function applyWhereRelation(ApplyToken $apply): void
    {
        $key = $apply->token->key();

        $segments = explode('.', $key);

        $relation = array_shift($segments);

        $key = implode('.', $segments);

        $token = new Token([
            ...$apply->token->token,
            'key' => $key,
        ]);

        $apply->builder->whereHas($relation, fn (Builder $query) => event(new ApplyToken($token, $query)));
    }
}
