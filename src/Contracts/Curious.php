<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Contracts;

use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Support\Collection;

/**
 * The method surface `YourCuriosity` mixes into a model - expressed as an
 * interface (rather than the trait itself) so it can be intersected with
 * `Model` in a type hint, which PHPStan accepts for a trait only via this
 * indirection.
 */
interface Curious
{
    /**
     * @return array<string, class-string<PaginateQuery>>
     */
    public function includeBy(): array;

    /**
     * @return array<string, string|array<int, string>>
     */
    public function filterBy(): array;

    /**
     * @return array<string>
     */
    public function selectBy(): array;

    /**
     * @return array<string>
     */
    public function aggregateBy(): array;

    /**
     * @return array<string>
     */
    public function sortBy(): array;

    /**
     * @return array<string>
     */
    public function castBy(): array;

    /**
     * @return array<string, array<mixed, mixed>|Collection<int|string, mixed>|(callable(mixed): mixed)>
     */
    public function castMutator(): array;

    public function maxFilterClauses(): int;

    public function maxFilterDepth(): int;

    public function maxSortFields(): int;

    public function maxAggregateItems(): int;

    public function maxIncludeDepth(): int;

    public function maxIncludeRelations(): int;
}
