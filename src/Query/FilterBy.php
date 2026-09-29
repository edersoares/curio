<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

trait FilterBy
{
    /**
     * @return array<string, string|array<int, string>>
     */
    public function filterBy(): array
    {
        return [];
    }

    public function defaultFilter(): string
    {
        return '';
    }
}
