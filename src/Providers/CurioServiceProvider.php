<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Providers;

use Illuminate\Support\ServiceProvider;

class CurioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/curio.php', 'curio');
    }
}
