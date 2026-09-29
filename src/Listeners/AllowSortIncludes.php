<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Including;

class AllowSortIncludes
{
    public function __construct(private readonly Including $including) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'include'));

        if ($tokens === []) {
            return;
        }

        $parsedItems = $this->including->build($tokens);

        $aggregationAliases = [];

        foreach ($parsedItems as $item) {
            if ($item['mode'] === 'aggregation') {
                $aggregationAliases[] = $item['alias'];
            }
        }

        $event->query->allowSort(...$aggregationAliases);
    }
}
