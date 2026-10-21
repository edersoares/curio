<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;

/**
 * Only the client's own `*` is a wildcard: a `%` or `_` it typed is matched
 * literally, escaped with `!` and an `ESCAPE '!'` clause - which is only
 * added when a pattern needs one, so ordinary values keep their old SQL.
 */
describe('filter', function () {
    test('`key:*value*` without metacharacters keeps its plain LIKE')
        ->expect(fn () => Author::curio()->filter('name:*eder*'))
        ->toRunQuery('select * from "author" where "author"."name" like \'%eder%\'');

    test('`key:*value*` matches a `%` or `_` literally')
        ->expect(fn () => Author::curio()->filter('name:*50%_off*'))
        ->toRunQuery('select * from "author" where "author"."name" like \'%50!%!_off%\' escape \'!\'');

    test('`-key:*value*` matches a `%` or `_` literally')
        ->expect(fn () => Author::curio()->filter('-name:*a_b*'))
        ->toRunQuery('select * from "author" where "author"."name" not like \'%a!_b%\' escape \'!\'');

    test('a `!` in the value is escaped too')
        ->expect(fn () => Author::curio()->filter('name:*hey!*'))
        ->toRunQuery('select * from "author" where "author"."name" like \'%hey!!%\' escape \'!\'');

    test('only the client\'s `*` is a wildcard', function () {
        Author::factory()->create(['name' => '50% off']);
        Author::factory()->create(['name' => '500 off']);

        expect(Author::curio()->filter('name:*50%*')->get()->pluck('name')->all())->toBe(['50% off']);
    });
});

describe('search', function () {
    test('a `%` or `_` in free text is matched literally')
        ->expect(fn () => Author::curio()->filter('50%_off'))
        ->toRunQuery('select * from "author" where ("author"."name" like \'%50!%!_off%\' escape \'!\')');

    test('a `%` in free text is matched literally in unaccent mode')
        ->defer(fn () => config()->set('curio.search.mode', 'unaccent'))
        ->expect(fn () => Author::curio()->filter('50%')->toRawSql())
        ->toBe('select * from "author" where (unaccent(lower("author"."name")) like unaccent(lower(\'%50!%%\')) escape \'!\')');

    test('searching for `%` no longer matches every row', function () {
        Author::factory()->create(['name' => 'Discount 100%']);
        Author::factory()->create(['name' => 'Eder Soares']);

        expect(Author::curio()->filter('%')->get()->pluck('name')->all())->toBe(['Discount 100%']);
    });
});

describe('having', function () {
    test('`:@having(*value*)` matches a `%` or `_` literally')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(*1_0*)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") like \'%1!_0%\' escape \'!\'');
});
