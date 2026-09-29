<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Selecting;

class SelectListener
{
    public function __construct(private readonly Selecting $selecting) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'select'));

        if ($tokens === []) {
            return;
        }

        $this->selecting->apply($event->builder, $tokens, $event->query);
    }
}
