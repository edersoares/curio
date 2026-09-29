<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Aggregating;

/**
 * Every `aggregate=` item selects a real, aliased column
 * (`Aggregating::applyGroupItem()`/`applyDatePartItem()`/`applyAggregateItem()`
 * all build `"<expr> as \"<alias>\""`) - register each one as sortable too,
 * so `sort=<alias>` validates without needing a separate `sortBy()` entry,
 * mirroring how `Including::apply()` does the same for relation-aggregate
 * include aliases. Runs before `SortListener` in `Pipeline`, since
 * `SortListener`'s validation reads the `allowSort()` mutation this makes.
 */
class AllowSortAggregates
{
    public function __construct(private readonly Aggregating $aggregating) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'aggregate'));

        if ($tokens === []) {
            return;
        }

        $columns = collect($this->aggregating->build($tokens))
            ->map(fn (array $item) => $item['alias'] ?? $item['field'])
            ->all();

        $event->query->allowSort(...$columns);
    }
}
