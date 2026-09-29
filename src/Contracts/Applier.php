<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Contracts;

use Dex\Laravel\Curio\Language\Filtering;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Implemented by every `Language` class (`Filtering`, `Selecting`,
 * `Aggregating`, `Including`, `Sorting`, `Searching`, `Casting`) - the shared
 * contract each type's self-selecting `Listeners\*` class dispatches
 * `Events\ApplyPending` against, after filtering the batch down to just the
 * already-parsed tokens matching its own type.
 *
 * @phpstan-import-type FilterClause from Filtering
 */
interface Applier
{
    /**
     * @param array<int, FilterClause> $tokens already-parsed via `Parser::transform()`
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query): void;
}
