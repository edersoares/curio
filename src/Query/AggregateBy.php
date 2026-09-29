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
}
