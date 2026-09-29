<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

trait SelectBy
{
    /**
     * @return array<string>
     */
    public function selectBy(): array
    {
        return [];
    }

    public function defaultSelect(): string
    {
        return '';
    }
}
