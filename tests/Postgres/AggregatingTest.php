<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;

beforeEach(function () {
    $this->ada = Author::factory()->create(['name' => 'Ada', 'ranking' => 10, 'created_at' => '2025-03-15 10:20:30']);
    $this->bob = Author::factory()->create(['name' => 'Bob', 'ranking' => 10, 'created_at' => '2026-07-01 00:00:00']);
    $this->cid = Author::factory()->create(['name' => 'Cid', 'ranking' => null, 'created_at' => '2026-08-01 00:00:00']);

    Post::factory()->count(3)->create(['author_id' => $this->ada->id, 'status' => 'done', 'title' => 'same']);
    Post::factory()->create(['author_id' => $this->bob->id, 'status' => 'draft', 'title' => 'other']);
});

/**
 * Read through `getAttributes()`: the default alias of a date part is the
 * field's own name, and the model would cast `created_at` back to a date.
 *
 * Numbers are compared as integers because PostgreSQL returns `numeric` for
 * `EXTRACT()` and `COUNT()` as a string - and `EXTRACT(SECOND ...)` with its
 * fraction (`30.000000`), where SQLite's `strftime('%S')` yields `30`.
 */
function rows(Closure $query): array
{
    return $query()->get()->map(fn ($model) => array_map(
        fn ($value) => is_numeric($value) ? (int) $value : $value,
        $model->getAttributes(),
    ))->all();
}

test('groups and counts')
    ->expect(fn () => rows(fn () => Author::curio()->aggregate('ranking:@group *:@count')->sort('-count')))
    ->toBe([
        ['ranking' => 10, 'count' => 2],
        ['ranking' => null, 'count' => 1],
    ]);

test('`@default` replaces a null group key')
    ->expect(fn () => rows(fn () => Author::curio()->aggregate('ranking:@group:@default(0) *:@count')->sort('ranking')))
    ->toBe([
        ['ranking' => 0, 'count' => 1],
        ['ranking' => 10, 'count' => 2],
    ]);

test('extracts every date part', function (string $part, int $expected) {
    $rows = rows(fn () => Author::curio()->aggregate("created_at:@$part:@alias(part)")->whereKey($this->ada->id));

    expect($rows)->toBe([['part' => $expected]]);
})->with([
    ['year', 2025],
    ['month', 3],
    ['day', 15],
    ['hour', 10],
    ['minute', 20],
    ['second', 30],
]);

test('groups by a date part')
    ->expect(fn () => rows(fn () => Author::curio()->aggregate('created_at:@year:@group:@alias(year) *:@count')->sort('year')))
    ->toBe([
        ['year' => 2025, 'count' => 1],
        ['year' => 2026, 'count' => 2],
    ]);

test('selects a date part')
    ->expect(fn () => rows(fn () => Author::curio()->select('created_at:@month:@alias(month)')->whereKey($this->ada->id)))
    ->toBe([['month' => 3]]);

test('aggregates a joined relation, keeping the parents without related rows')
    ->expect(fn () => rows(fn () => Author::curio()->aggregate('name:@group posts:@count:@join')->sort('name')))
    ->toBe([
        ['name' => 'Ada', 'posts_count' => 3],
        ['name' => 'Bob', 'posts_count' => 1],
        ['name' => 'Cid', 'posts_count' => 0],
    ]);

test('`@having` filters the groups')
    ->expect(fn () => rows(fn () => Author::curio()->aggregate('name:@group posts:@count:@join:@having(>=2)')))
    ->toBe([['name' => 'Ada', 'posts_count' => 3]]);

test('aggregates over HTTP', function () {
    $response = $this->getJson('api/author?' . http_build_query([
        'aggregate' => 'ranking:@group *:@count',
        'sort' => '-count',
    ]));

    $response->assertOk();

    expect($response->json('meta.total'))->toBe(2);
});
