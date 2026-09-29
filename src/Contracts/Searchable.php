<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Contracts;

interface Searchable
{
    /**
     * @return array<string>
     */
    public function searchBy(): array;

    public function searchMode(): string;

    public function searchSimilarityThreshold(): float;
}
