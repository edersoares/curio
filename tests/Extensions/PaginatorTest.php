<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Extensions\Paginator;
use Dex\Laravel\Curio\Language\Preset;
use Dex\Laravel\Curio\Workbench\App\Http\Queries\AuthorQuery;
use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Dex\Laravel\Curio\Workbench\Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    Preset::clear();
});

test('test paginator', function () {
    $this->seed(DatabaseSeeder::class);

    $this->get('api/author?sort=name&filter=name:A*')->assertOk();
    $this->get('api/user')->assertOk();
});

test('free text filter searches across searchBy fields end to end', function () {
    $match = Author::factory()->create(['name' => 'Eder Soares', 'email' => 'someone@example.com']);
    Author::factory()->create(['name' => 'Someone Else', 'email' => 'else@example.com']);

    $response = $this->getJson('api/author?' . http_build_query(['filter' => 'eder']));

    $response->assertOk();

    $data = $response->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($match->id);
});

test('aggregate with year filter preset', function () {
    Preset::register('year', fn ($year) => "created_at:{$year}-01-01..{$year}-12-31");

    Author::factory()->count(3)->create(['ranking' => 10, 'created_at' => '2025-03-15']);
    Author::factory()->count(2)->create(['ranking' => 10, 'created_at' => '2025-07-20']);
    Author::factory()->count(4)->create(['ranking' => 20, 'created_at' => '2025-03-10']);
    Author::factory()->count(5)->create(['ranking' => 10, 'created_at' => '2024-01-01']);

    $response = $this->getJson(
        'api/author?' . http_build_query([
            'aggregate' => 'ranking:@group created_at:@month:@group:@alias(month) *:@count:@alias(count)',
            'filter' => '@year:2025',
        ])
    );

    $response->assertOk();

    $data = $response->json('data');

    expect($data)->toHaveCount(3);

    foreach ($data as $row) {
        expect($row)->toHaveKeys(['ranking', 'month', 'count']);
        expect($row['month'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(12);
    }

    $groups = collect($data)->keyBy(fn ($row) => $row['ranking'] . '-' . $row['month']);

    expect($groups->get('10-3')['count'])->toBe(3);
    expect($groups->get('10-7')['count'])->toBe(2);
    expect($groups->get('20-3')['count'])->toBe(4);
});

test('rejects disallowed aggregate field', function () {
    $this->getJson('api/author?' . http_build_query(['aggregate' => 'email:@group']))
        ->assertStatus(422);
});

test('filters grouped results via an inline @having on the aggregate', function () {
    Author::factory()->count(3)->create(['ranking' => 10, 'created_at' => '2025-03-15']);
    Author::factory()->count(2)->create(['ranking' => 10, 'created_at' => '2025-07-20']);
    Author::factory()->count(4)->create(['ranking' => 20, 'created_at' => '2025-03-10']);

    $response = $this->getJson(
        'api/author?' . http_build_query([
            'aggregate' => 'ranking:@group created_at:@month:@group:@alias(month) *:@count:@alias(count):@having(>=3)',
        ])
    );

    $response->assertOk();

    $data = $response->json('data');

    $groups = collect($data)->keyBy(fn ($row) => $row['ranking'] . '-' . $row['month']);

    // The ranking=10/July group only has 2 authors, so `@having(>=3)` excludes it.
    expect($groups->has('10-7'))->toBeFalse();
    expect($groups->get('10-3')['count'])->toBe(3);
    expect($groups->get('20-3')['count'])->toBe(4);
});

test('rejects using select and aggregate together', function () {
    $this->getJson('api/author?' . http_build_query([
        'select' => 'ranking',
        'aggregate' => 'ranking:@group',
    ]))->assertStatus(422);
});

test('sort by an include aggregation alias succeeds when the matching include is present', function () {
    $author = Author::factory()->create();
    Post::factory()->count(3)->create(['author_id' => $author->id]);

    $other = Author::factory()->create();
    Post::factory()->count(1)->create(['author_id' => $other->id]);

    $response = $this->getJson('api/author?' . http_build_query([
        'include' => 'posts:@count',
        'sort' => '-posts_count',
    ]));

    $response->assertOk();

    expect($response->json('data.0.posts_count'))->toBe(3);
});

test('sort by an include aggregation alias fails when the matching include is absent', function () {
    Author::factory()->create();

    $this->getJson('api/author?' . http_build_query(['sort' => 'posts_count']))
        ->assertStatus(422);
});

test('sort by an explicit include aggregation alias still works', function () {
    $author = Author::factory()->create();
    Post::factory()->count(2)->create(['author_id' => $author->id]);

    // `AuthorResource` only surfaces attributes matching `_(count|sum|...)`,
    // so `total` won't appear in the JSON payload - this only checks the
    // explicit alias is accepted as a `sort=` value (no 422), same as the
    // default-alias tests above check for the computed alias.
    $this->getJson('api/author?' . http_build_query([
        'include' => 'posts:@count:@alias(total)',
        'sort' => 'total',
    ]))->assertOk();
});

test('cast nests the resolved value under a casts key, leaving the original field untouched', function () {
    Author::factory()->create(['ranking' => 10]);

    $response = $this->getJson('api/author?' . http_build_query(['cast' => 'ranking:@rankingTier']));

    $response->assertOk();

    expect($response->json('data.0.ranking'))->toBe(10);
    expect($response->json('data.0.casts.ranking'))->toBe('bronze');
});

test('cast nests under the alias key when one is given', function () {
    Author::factory()->create(['ranking' => 10]);

    $response = $this->getJson('api/author?' . http_build_query([
        'cast' => 'ranking:@rankingTier:@alias(rankingTier)',
    ]));

    $response->assertOk();

    expect($response->json('data.0.ranking'))->toBe(10);
    expect($response->json('data.0.casts.rankingTier'))->toBe('bronze');
});

test('cast nests alongside a grouped aggregate', function () {
    Author::factory()->count(2)->create(['ranking' => 10]);
    Author::factory()->count(3)->create(['ranking' => 20]);

    $response = $this->getJson('api/author?' . http_build_query([
        'aggregate' => 'ranking:@group *:@count:@alias(count)',
        'cast' => 'ranking:@rankingTier:@alias(rankingTier)',
    ]));

    $response->assertOk();

    $data = $response->json('data');

    $groups = collect($data)->keyBy('ranking');

    expect($groups->get(10)['count'])->toBe(2);
    expect($groups->get(10)['casts']['rankingTier'])->toBe('bronze');
    expect($groups->get(20)['count'])->toBe(3);
    expect($groups->get(20)['casts']['rankingTier'])->toBe('silver');
});

test('cast falls back to the original value on a lookup miss', function () {
    Author::factory()->create(['ranking' => 42]);

    $response = $this->getJson('api/author?' . http_build_query(['cast' => 'ranking:@rankingTier']));

    $response->assertOk();

    expect($response->json('data.0.ranking'))->toBe(42);
    expect($response->json('data.0.casts.ranking'))->toBe(42);
});

/**
 * `include=`'s `:@alias()` value was only checked for emptiness, and every
 * aggregation alias is registered as sortable by
 * `Listeners\AllowSortIncludes` - so an alias carrying a `.` came back
 * through `sort=` as `relation.column`, which `Sorting::applySort()`
 * resolved by calling `$model->{relation}()`. `Model::__call()` forwards an
 * unknown name to the query builder, so this request ran
 * `Builder::truncate()` against the table and still answered `200 OK`.
 */
test('an include alias cannot smuggle a builder method name into sort=', function () {
    Author::factory()->count(3)->create();

    $response = $this->getJson('api/author?' . http_build_query([
        'include' => 'posts:@count:@alias(truncate.x)',
        'sort' => 'truncate.x',
    ]));

    $response->assertStatus(422);

    expect(Author::count())->toBe(3);
});

/**
 * Both clauses of a repeated field reach the builder, but validation used to
 * key its payload by field name - so only the last value was ever checked,
 * and the rules of every earlier clause (here `lte:100`, and in `has`'s case
 * an `in:` relation allow-list) applied to nothing.
 */
test('every clause of a repeated filter field is validated, not only the last', function () {
    Author::factory()->count(3)->create(['ranking' => 10]);

    $bypass = $this->getJson('api/author?' . http_build_query(['filter' => 'ranking:999 ranking:5']));

    $bypass->assertStatus(422);

    $relation = $this->getJson('api/author?' . http_build_query(['filter' => 'has:truncate has:posts']));

    $relation->assertStatus(422);

    expect(Author::count())->toBe(3);
});

/**
 * `Author` and `Post` include each other, so every level of
 * `posts.author.posts...` is allow-listed and validation used to accept any
 * depth - while each level multiplies the serialized payload by the number of
 * related rows per parent. With 3 authors of 4 posts each, seven repetitions
 * answered with 164 MB of JSON in 11 s; one more repetition exhausts the
 * worker's memory.
 */
test('a cyclic include chain is rejected instead of amplifying the response', function () {
    // Deliberately no rows: the 422 happens during validation, before a single
    // query runs, and seeding the rows that make the amplification measurable
    // would also make every mutant that disables this guard materialize those
    // 164 MB - turning each one into a mutation-run timeout.
    $include = 'posts' . str_repeat('.author.posts', 7);

    $response = $this->getJson('api/author?' . http_build_query(['include' => $include]));

    $response->assertStatus(422);

    expect($response->json('errors.include.0'))
        ->toContain('is nested deeper than the maximum include depth of 3');
});

/**
 * `Listeners\AllowSortAggregates`/`AllowSortIncludes` register the current
 * request's aliases as sortable on the `PaginateQuery` instance. Bound as a
 * container singleton (or simply resolved inside a long-lived worker), that
 * instance outlives the request - so the alias one request introduced stayed
 * sortable for every request after it, including another user's.
 */
test('an alias registered as sortable by one request does not leak into the next', function () {
    app()->singleton(AuthorQuery::class, fn () => new AuthorQuery());

    Author::factory()->count(3)->create();

    $this->getJson('api/author?' . http_build_query([
        'include' => 'posts:@count:@alias(postsTotal)',
    ]))->assertOk();

    $this->getJson('api/author?' . http_build_query([
        'sort' => 'postsTotal',
    ]))->assertStatus(422);
});

/**
 * The `size` ceiling lives in `PaginateRequest::rules()`, so it disappears
 * the moment a consumer overrides `rules()` or calls `Paginator::execute()`
 * itself - which would hand an unbounded page size to the database.
 */
test('the page size is clamped to defaultMaxPageSize() even without the request rule', function () {
    Author::factory()->count(3)->create();

    $paginator = app(Paginator::class)->execute(
        Author::query(),
        collect(['size' => '100000000']),
        new AuthorQuery(),
    );

    expect($paginator->perPage())->toBe((new AuthorQuery())->defaultMaxPageSize());
});

/**
 * The same clamp carries a floor: `LengthAwarePaginator` divides by the page
 * size, so a `size` of `0` (or below) must never reach it - the request rule
 * `min:1` rejects it first, but only while that rule is in effect.
 */
test('the page size is floored at 1', function () {
    Author::factory()->count(3)->create();

    $paginator = app(Paginator::class)->execute(
        Author::query(),
        collect(['size' => '0']),
        new AuthorQuery(),
    );

    expect($paginator->perPage())->toBe(1);
});
