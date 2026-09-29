<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Events;

use Dex\Laravel\Curio\Language\Filtering;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Dispatched once from `Curio::applyPendings()`, carrying every token queued
 * across all 6 fluent DSL methods since the last dispatch - each already
 * parsed via `Parser::transform()` at the point the fluent method was called,
 * and self-tagged with its own DSL type (`$token['type']`) by that same
 * call. Handled by one self-selecting listener per type (`Listeners\*`),
 * each filtering `$tokens` down to just its own type and delegating to the
 * matching `Contracts\Applier` - no central routing table, so a new listener
 * can hook this same event and act on tokens by any criteria it wants.
 *
 * `$tokens` is intentionally not `readonly` - `Listeners\ReplaceKeys` runs
 * first (see `CurioServiceProvider::boot()`) and rewrites every token's
 * `key` via `$query->replaceBy()` in place, so every listener after it
 * (including type listeners) already sees resolved keys.
 *
 * @phpstan-import-type FilterClause from Filtering
 */
class ApplyPending
{
    /**
     * @param array<int, FilterClause> $tokens
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function __construct(
        public array $tokens,
        public readonly Builder|Relation $builder,
        public readonly PaginateQuery $query,
    ) {}
}
