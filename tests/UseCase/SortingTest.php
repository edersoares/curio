<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Dex\Laravel\Curio\Workbench\App\Models\User;
use Illuminate\Validation\ValidationException;

describe('model sorting', function () {
    test('`asc` sort')
        ->expect(fn () => Author::curio()->sort('email')->toRawSql())
        ->toBe('select * from "author" order by "email" asc');

    test('`desc` sort')
        ->expect(fn () => Author::curio()->sort('-email')->toRawSql())
        ->toBe('select * from "author" order by "email" desc');
});

describe('belongs to relation', function () {
    test('`asc` sort')
        ->expect(fn () => Post::curio()->sort('author.name')->toRawSql())
        ->toBe('select * from "post" order by (select "author"."name" from "author" where "post"."author_id" = "author"."id" limit 1) asc');

    test('`desc` sort')
        ->expect(fn () => Post::curio()->sort('-author.name')->toRawSql())
        ->toBe('select * from "post" order by (select "author"."name" from "author" where "post"."author_id" = "author"."id" limit 1) desc');
});

describe('has one to relation', function () {
    test('`asc` sort')
        ->expect(fn () => Author::curio()->sort('latestPost.title')->toRawSql())
        ->toBe('select * from "author" order by (select "post"."title" from "post" where "author"."id" = "post"."author_id" order by "created_at" desc limit 1) asc');

    test('`desc` sort')
        ->expect(fn () => Author::curio()->sort('-latestPost.title')->toRawSql())
        ->toBe('select * from "author" order by (select "post"."title" from "post" where "author"."id" = "post"."author_id" order by "created_at" desc limit 1) desc');
});

describe(':@unaccent modifier', function () {
    test('`asc` per-field unaccent, without touching the global sort mode')
        ->expect(fn () => Author::curio()->sort('email:@unaccent')->toRawSql())
        ->toBe('select * from "author" order by unaccent(lower("email")) asc');

    test('`desc` per-field unaccent')
        ->expect(fn () => Author::curio()->sort('-email:@unaccent')->toRawSql())
        ->toBe('select * from "author" order by unaccent(lower("email")) desc');

    test('only the field carrying `:@unaccent` is affected, the rest sort naturally')
        ->expect(fn () => Author::curio()->sort('email:@unaccent -ranking')->toRawSql())
        ->toBe('select * from "author" order by unaccent(lower("email")) asc, "ranking" desc');
});

describe('validation errors', function () {
    test('an empty sort string is a no-op, even with allowed fields configured')
        ->expect(fn () => Author::curio()->sort('')->toRawSql())
        ->toBe('select * from "author"');

    test('a model with no allowed sort fields rejects any sort')
        ->expect(fn () => User::curio()->sort('name')->toRawSql())
        ->throws(ValidationException::class, 'There is no allowed fields to sort.');

    test('a field outside the allowed list is rejected')
        ->expect(fn () => Author::curio()->sort('nickname')->toRawSql())
        ->throws(ValidationException::class, "The fields 'nickname' is not allowed to sort.");

    /**
     * An allowed dotted key still has to name a real relation: `Model::__call()`
     * forwards an unknown name to the query builder, so resolving the relation
     * half by calling it blindly would invoke whatever builder method the name
     * happens to match (`truncate()`, ...). The allow-list here is stacked in
     * the model itself, which is also how a dynamically registered sortable
     * column (`PaginateQuery::allowSort()`) could ever carry such a key.
     */
    test('an allowed dotted key whose relation half is not a relation is rejected, not invoked')
        ->expect(function () {
            $model = new class() extends Author {
                public function sortBy(): array
                {
                    return ['truncate.x'];
                }
            };

            return $model::curio($model->newQuery())->sort('truncate.x')->toRawSql();
        })
        ->throws(ValidationException::class, "The field 'truncate.x' is not allowed to sort.");
});

/**
 * Sorting changes the order of the rows and nothing else. The relation sort
 * used to be an `INNER JOIN`, which repeated a parent once per related row,
 * dropped every parent with no related row, and - through the `table.*` it
 * had to add to the `SELECT` - undid a `select=` sent alongside it.
 */
describe('relation sort keeps the result set intact', function () {
    beforeEach(function () {
        $this->ada = Author::factory()->create(['name' => 'Ada']);
        $this->bob = Author::factory()->create(['name' => 'Bob']);
        $this->cid = Author::factory()->create(['name' => 'Cid']);

        Post::factory()->create(['author_id' => $this->ada->id, 'title' => 'Zebra', 'created_at' => '2026-01-01 00:00:00']);
        Post::factory()->create(['author_id' => $this->ada->id, 'title' => 'Mango', 'created_at' => '2026-03-01 00:00:00']);
        Post::factory()->create(['author_id' => $this->ada->id, 'title' => 'Apple', 'created_at' => '2026-02-01 00:00:00']);
        Post::factory()->create(['author_id' => $this->bob->id, 'title' => 'Banana', 'created_at' => '2026-01-01 00:00:00']);
    });

    test('a parent with many related rows is returned once', function () {
        $ids = Author::curio()->sort('latestPost.title')->get()->pluck('id')->sort()->values()->all();

        expect($ids)->toBe([$this->ada->id, $this->bob->id, $this->cid->id]);
    });

    test('a parent with no related row is kept')
        ->expect(fn () => Author::curio()->sort('latestPost.title')->get()->pluck('name')->all())
        ->toContain('Cid');

    test('orders by the row the relation itself resolves to - the latest post, not any post')
        ->expect(fn () => Author::curio()->sort('latestPost.title')->whereKey([$this->ada->id, $this->bob->id])->get()->pluck('name')->all())
        ->toBe(['Bob', 'Ada']); // Banana < Mango (Ada's latest), although Ada also wrote "Apple"

    test('descending order')
        ->expect(fn () => Author::curio()->sort('-latestPost.title')->whereKey([$this->ada->id, $this->bob->id])->get()->pluck('name')->all())
        ->toBe(['Ada', 'Bob']);

    test('the paginator total is the number of parents', function () {
        $response = $this->getJson('api/author?' . http_build_query(['sort' => 'latestPost.title']));

        $response->assertOk();

        expect($response->json('meta.total'))->toBe(3);
        expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
            ->toBe([$this->ada->id, $this->bob->id, $this->cid->id]);
    });

    test('a `select=` sent alongside is respected')
        ->expect(fn () => Author::curio()->select('id name')->sort('latestPost.title')->toRawSql())
        ->toBe('select "author"."id", "author"."name" from "author" order by (select "post"."title" from "post" where "author"."id" = "post"."author_id" order by "created_at" desc limit 1) asc');

    test('`:@unaccent` wraps the subquery')
        ->expect(fn () => Post::curio()->sort('author.name:@unaccent')->toRawSql())
        ->toBe('select * from "post" order by unaccent(lower((select "author"."name" from "author" where "post"."author_id" = "author"."id" limit 1))) asc');

    test('a belongs to sort keeps a child whose parent is missing', function () {
        $orphan = Post::factory()->create(['title' => 'Orphan']);
        $orphan->author()->delete();

        expect(Post::curio()->sort('author.name')->get()->pluck('title')->all())->toContain('Orphan');
    });

    test('a relation column is rejected alongside a grouped aggregate')
        ->expect(fn () => Author::curio()->aggregate('name:@group *:@count')->sort('latestPost.title')->toRawSql())
        ->throws(ValidationException::class, "The field 'latestPost.title' is not allowed to sort: a relation column can't be sorted alongside 'aggregate'.");

    test('a relation column is rejected alongside a joined aggregate')
        ->expect(fn () => Author::curio()->aggregate('name:@group posts:@count:@join')->sort('latestPost.title')->toRawSql())
        ->throws(ValidationException::class, "The field 'latestPost.title' is not allowed to sort: a relation column can't be sorted alongside 'aggregate'.");
});
