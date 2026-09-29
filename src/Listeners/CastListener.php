<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Casting;

class CastListener
{
    public function __construct(private readonly Casting $casting) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'cast'));

        if ($tokens === []) {
            return;
        }

        $this->casting->apply($event->builder, $tokens, $event->query);
    }
}
