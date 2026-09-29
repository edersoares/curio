<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Sorting;

/**
 * Runs after `IncludeListener`/`AllowSortAggregates` in `Pipeline` - both
 * mutate `$query`'s sortable-column
 * allow-list (`allowSort()`) as a side effect, which this listener's
 * validation reads via `allowedSortColumns()`.
 */
class SortListener
{
    public function __construct(private readonly Sorting $sorting) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'sort'));

        if ($tokens === []) {
            return;
        }

        $this->sorting->apply($event->builder, $tokens, $event->query);
    }
}
