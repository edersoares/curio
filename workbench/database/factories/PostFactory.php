<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\Database\Factories;

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'author_id' => fn () => Author::factory()->create(),
            'title' => fake()->sentence(),
            'content' => fake()->paragraphs(asText: true),
            'published_at' => null,
            'status' => $this->faker->randomElement(['draft', 'review', 'done']),
            'category' => $this->faker->colorName(),
        ];
    }
}
