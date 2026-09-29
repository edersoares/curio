<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

use function Orchestra\Testbench\workbench_path;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        }

        config([
            'cors.paths' => ['*'],
        ]);

        if ($this->app->runningInConsole()) {
            require workbench_path('routes/console.php');
            $this->aliases();
            $this->loadMigrationsFrom(__DIR__ . '/../../../vendor/laravel/telescope/database/migrations');
        }
    }

    private function aliases(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $namespace = 'Dex\\Laravel\\Curio\\Workbench\\App\\Models\\';
        $path = workbench_path('app/Models/');
        $files = glob("$path*.php");

        foreach ($files as $file) {
            $file = str_replace([$path, '.php'], ['', ''], $file);

            class_alias($namespace . $file, $file);
        }
    }
}
