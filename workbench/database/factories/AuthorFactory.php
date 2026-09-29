<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\Database\Factories;

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuthorFactory extends Factory
{
    protected $model = Author::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->email(),
            'date_of_birth' => fake()->boolean() ? fake()->date() : null,
            'ranking' => fake()->boolean() ? fake()->numberBetween(1, 100) : null,
        ];
    }
}
