<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Searching;

/**
 * Free-text search rides inside the same `filter=` tokens (tagged
 * `type === 'filter'` by `Parser::transform()`, distinguished only by
 * `operator === 'search'`) rather than its own DSL param - so this filters
 * the same `type` as `FilterListener` and lets `Searching::build()` pick out
 * just the search-operator token, mirroring `Filtering::build()`'s opposite.
 */
class SearchListener
{
    public function __construct(private readonly Searching $searching) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'filter'));

        if ($tokens === []) {
            return;
        }

        $this->searching->apply($event->builder, $tokens, $event->query);
    }
}
