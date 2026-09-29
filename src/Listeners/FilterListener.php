<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Filtering;

class FilterListener
{
    public function __construct(private readonly Filtering $filtering) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'filter'));

        if ($tokens === []) {
            return;
        }

        $this->filtering->apply($event->builder, $tokens, $event->query);
    }
}
