<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Events;

use Dex\Laravel\Curio\Language\Filtering;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Carries every token queued across all 6 fluent DSL methods since the last
 * run - each already parsed via `Parser::transform()` at the point the
 * fluent method was called, and self-tagged with its own DSL type
 * (`$token['type']`) by that same call.
 *
 * Dispatched by `Pipeline::pending()` as a hook, *before* anything is
 * applied: a listener sees the batch exactly as it was parsed and may
 * rewrite `$tokens`. The steps that validate and apply it (`Listeners\*`,
 * one self-selecting step per type) are not listeners on this event - the
 * `Pipeline` calls them directly right after, so they run whether or not
 * the event was delivered.
 *
 * `$tokens` is intentionally not `readonly` - besides a hooked listener,
 * `Listeners\ReplaceKeys` runs first in the `Pipeline` and rewrites every
 * token's `key` via `$query->replaceBy()` in place, so every step after it
 * already sees resolved keys.
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
