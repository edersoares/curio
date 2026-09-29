<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

trait SearchBy
{
    /**
     * @return array<string>
     */
    public function searchBy(): array
    {
        return [];
    }

    public function searchMode(): string
    {
        $value = config('curio.search.mode', 'like');

        assert(is_string($value));

        return $value;
    }

    public function searchSimilarityThreshold(): float
    {
        $value = config('curio.search.similarity_threshold', 0.3);

        assert(is_numeric($value));

        return (float) $value;
    }
}
