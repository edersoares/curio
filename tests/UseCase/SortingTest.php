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
        ->toBe('select "post".* from "post" inner join "author" on "author"."id" = "post"."author_id" order by "author"."name" asc');

    test('`desc` sort')
        ->expect(fn () => Post::curio()->sort('-author.name')->toRawSql())
        ->toBe('select "post".* from "post" inner join "author" on "author"."id" = "post"."author_id" order by "author"."name" desc');
});

describe('has one to relation', function () {
    test('`asc` sort')
        ->expect(fn () => Author::curio()->sort('latestPost.title')->toRawSql())
        ->toBe('select "author".* from "author" inner join "post" on "post"."author_id" = "author"."id" order by "post"."title" asc');

    test('`desc` sort')
        ->expect(fn () => Author::curio()->sort('-latestPost.title')->toRawSql())
        ->toBe('select "author".* from "author" inner join "post" on "post"."author_id" = "author"."id" order by "post"."title" desc');
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
