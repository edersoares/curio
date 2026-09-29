<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Including;

class IncludeListener
{
    public function __construct(private readonly Including $including) {}

    public function handle(ApplyPending $event): void
    {
        $tokens = array_values(array_filter($event->tokens, fn (array $token) => $token['type'] === 'include'));

        if ($tokens === []) {
            return;
        }

        $this->including->apply($event->builder, $tokens, $event->query);
    }
}
