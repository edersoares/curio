<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\Database\Seeders;

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Comment;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $authors = Author::factory()->count(100)->create();

        $sequence = new Sequence(
            fn (Sequence $sequence) => ['author_id' => $authors->random()],
        );

        for ($i = 0; $i < 10; $i++) {
            Post::factory()
                ->state($sequence)
                ->has(Comment::factory()->state($sequence)->count(random_int(1, 10)))
                ->has(Comment::factory()->state($sequence)->state([
                    'excluded' => true,
                ])->count(random_int(1, 10)))
                ->create();
        }
    }
}
