<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

use Illuminate\Support\Collection;

trait CastBy
{
    /**
     * @return array<string>
     */
    public function castBy(): array
    {
        return [];
    }

    /**
     * @return array<string, array<mixed, mixed>|Collection<int|string, mixed>|(callable(mixed): mixed)>
     */
    public function castMutator(): array
    {
        return [];
    }

    public function defaultCast(): string
    {
        return '';
    }
}
