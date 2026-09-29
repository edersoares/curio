<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\Database\Factories;

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Comment;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

class CommentFactory extends Factory
{
    protected $model = Comment::class;

    public function definition(): array
    {
        return [
            'author_id' => fn () => Author::factory()->create(),
            'post_id' => fn () => Post::factory()->create(),
            'title' => fake()->sentence(),
            'content' => fake()->paragraphs(asText: true),
            'excluded' => false,
        ];
    }
}
