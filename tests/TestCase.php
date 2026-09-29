<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Tests;

use Dex\Laravel\Curio\Providers\CurioServiceProvider;
use Dex\Laravel\Curio\Workbench\App\Providers\WorkbenchServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Orchestra;

#[WithMigration]
class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Dex\\Laravel\\Curio\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );

        Factory::guessModelNamesUsing(
            fn ($factory) => 'Dex\\Laravel\\Curio\\Models\\' . Str::replaceLast('Factory', '', class_basename($factory))
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            CurioServiceProvider::class,
            WorkbenchServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }
}
