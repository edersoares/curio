<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;

beforeEach(function () {
    $this->ada = Author::factory()->create(['name' => 'Ada']);
    $this->bob = Author::factory()->create(['name' => 'Bob']);

    Post::factory()->count(2)->create(['author_id' => $this->ada->id, 'title' => 'same', 'status' => 'done']);
    Post::factory()->create(['author_id' => $this->ada->id, 'title' => 'other', 'status' => 'draft']);
});

function included(string $include): array
{
    return Author::curio()->include($include)->sort('name')->get()->all();
}

test('counts a relation')
    ->expect(fn () => array_map(fn ($author) => $author->posts_count, included('posts:@count')))
    ->toBe([3, 0]);

test('counts the distinct values of a column')
    ->expect(fn () => array_map(fn ($author) => $author->posts_count_distinct_title, included('posts:@count(distinct:title)')))
    ->toBe([2, 0]);

test('limits the related rows of each parent')
    ->expect(fn () => array_map(fn ($author) => $author->posts->count(), included('posts:@limit(2)')))
    ->toBe([2, 0]);

test('`@only` filters the related rows and keeps every parent')
    ->expect(fn () => array_map(fn ($author) => $author->posts->pluck('title')->all(), included('posts:@only(status:draft)')))
    ->toBe([['other'], []]);

test('`@filter` filters the related rows and the parents that have none')
    ->expect(fn () => array_map(fn ($author) => $author->posts->pluck('title')->all(), included('posts:@filter(status:draft)')))
    ->toBe([['other']]);
