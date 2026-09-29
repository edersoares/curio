<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;

beforeEach(function () {
    $this->bruno = Author::factory()->create(['name' => 'Bruno']);
    $this->alvaro = Author::factory()->create(['name' => 'Álvaro']);
    $this->alice = Author::factory()->create(['name' => 'alice']);
});

describe('`:@unaccent`', function () {
    test('orders ignoring accents and case')
        ->expect(fn () => Author::curio()->sort('name:@unaccent')->get()->pluck('name')->all())
        ->toBe(['alice', 'Álvaro', 'Bruno']);

    test('orders descending')
        ->expect(fn () => Author::curio()->sort('-name:@unaccent')->get()->pluck('name')->all())
        ->toBe(['Bruno', 'Álvaro', 'alice']);
});

describe('relation column', function () {
    beforeEach(function () {
        Post::factory()->create(['author_id' => $this->bruno->id, 'title' => 'Zebra', 'created_at' => '2026-01-01 00:00:00']);
        Post::factory()->create(['author_id' => $this->bruno->id, 'title' => 'Élan', 'created_at' => '2026-02-01 00:00:00']);
        Post::factory()->create(['author_id' => $this->alvaro->id, 'title' => 'delta', 'created_at' => '2026-01-01 00:00:00']);
    });

    test('returns every parent once, the ones without a related row included', function () {
        $ids = Author::curio()->sort('latestPost.title')->get()->pluck('id')->sort()->values()->all();

        expect($ids)->toBe([$this->bruno->id, $this->alvaro->id, $this->alice->id]);
    });

    test('orders by the latest post, ignoring accents and case')
        ->expect(fn () => Author::curio()->sort('latestPost.title:@unaccent')->get()->pluck('name')->take(2)->all())
        ->toBe(['Álvaro', 'Bruno']); // delta < elan

    test('orders a belongs to relation, ignoring accents and case', function () {
        $authors = Post::curio()->sort('author.name:@unaccent')->get()->pluck('author_id')->all();

        expect($authors)->toBe([$this->alvaro->id, $this->bruno->id, $this->bruno->id]);
    });

    test('paginates over HTTP with the total of parents', function () {
        $response = $this->getJson('api/author?' . http_build_query(['sort' => 'latestPost.title']));

        $response->assertOk();

        expect($response->json('meta.total'))->toBe(3);
    });
});
