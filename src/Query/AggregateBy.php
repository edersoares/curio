<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

trait AggregateBy
{
    /**
     * @return array<string>
     */
    public function aggregateBy(): array
    {
        return [];
    }

    public function defaultAggregate(): string
    {
        return '';
    }

    /**
     * How many items `aggregate=` may carry, backed by
     * `config('curio.aggregate.max_items')`.
     */
    public function maxAggregateItems(): int
    {
        $value = config('curio.aggregate.max_items');

        assert(is_int($value));

        return $value;
    }
}
