<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Aggregating;

class AggregateListener
{
    public function __construct(private readonly Aggregating $aggregating) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'aggregate'));

        if ($tokens === []) {
            return;
        }

        $this->aggregating->apply($event->builder, $tokens, $event->query);
    }
}
