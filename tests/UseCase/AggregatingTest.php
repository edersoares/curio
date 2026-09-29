<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Dex\Laravel\Curio\Workbench\App\Models\User;
use Illuminate\Validation\ValidationException;

describe('aggregating', function () {
    test('`*:@count`')
        ->expect(fn () => Author::curio()->aggregate('*:@count'))
        ->toRunQuery('select COUNT(*) as "count" from "author"');

    test('`@count`')
        ->expect(fn () => Author::curio()->aggregate('@count'))
        ->toRunQuery('select COUNT(*) as "count" from "author"');

    test('`@count(distinct:id)`')
        ->expect(fn () => Author::curio()->aggregate('@count(distinct:id)'))
        ->toRunQuery('select COUNT(DISTINCT "author"."id") as "id_count_distinct" from "author"');

    test('`ranking:@group`')
        ->expect(fn () => Author::curio()->aggregate('ranking:@group'))
        ->toRunQuery('select "author"."ranking" from "author" group by "author"."ranking"');

    test('`ranking:@sum`')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum'))
        ->toRunQuery('select SUM("author"."ranking") as "ranking_sum" from "author"');

    test('`posts:@count:@join:@alias(total)`')
        ->expect(fn () => Author::curio()->aggregate('posts:@count:@join:@alias(total)'))
        ->toRunQuery('select COUNT("post"."id") as "total" from "author" left join "post" on "post"."author_id" = "author"."id"');

    test('`name:@group posts:@count:@join`')
        ->expect(fn () => Author::curio()->aggregate('name:@group posts:@count:@join'))
        ->toRunQuery('select "author"."name", COUNT("post"."id") as "posts_count" from "author" left join "post" on "post"."author_id" = "author"."id" group by "author"."name"');

    test('`author:@count:@join`')
        ->expect(fn () => Post::curio()->aggregate('author:@count:@join'))
        ->toRunQuery('select COUNT("author"."id") as "author_count" from "post" left join "author" on "author"."id" = "post"."author_id"');

    test('`ranking:@group @count`')
        ->expect(fn () => Author::curio()->aggregate('ranking:@group @count'))
        ->toRunQuery('select "author"."ranking", COUNT(*) as "count" from "author" group by "author"."ranking"');

    test('`ranking:@group @count:@having(>=2)`')
        ->expect(fn () => Author::curio()->aggregate('ranking:@group @count:@having(>=2)'))
        ->toRunQuery('select "author"."ranking", COUNT(*) as "count" from "author" group by "author"."ranking" having COUNT(*) >= 2');

    test('`created_at:@group:@year @count`')
        ->expect(fn () => Author::curio()->aggregate('created_at:@group:@year @count'))
        ->toRunQuery('select CAST(strftime(\'%Y\', "author"."created_at") as integer) as "created_at", COUNT(*) as "count" from "author" group by CAST(strftime(\'%Y\', "author"."created_at") as integer)');
});

describe('joins', function () {
    test('`:@join` with distinct column generates COUNT(DISTINCT related.column)')
        ->expect(fn () => Author::curio()->aggregate('posts:@count(distinct:title):@join')->toRawSql())
        ->toBe('select COUNT(DISTINCT "post"."title") as "posts_count_distinct" from "author" left join "post" on "post"."author_id" = "author"."id"');

    test('`:@join` composes with `:@having`')
        ->expect(fn () => Author::curio()->aggregate('name:@group posts:@count:@join:@having(>=2)')->toRawSql())
        ->toBe('select "author"."name", COUNT("post"."id") as "posts_count" from "author" left join "post" on "post"."author_id" = "author"."id" group by "author"."name" having COUNT("post"."id") >= 2');

    test('groups by a joined relation column while aggregating COUNT(*)')
        ->expect(fn () => Author::curio()->aggregate('posts:@group(status):@join @count')->toRawSql())
        ->toBe('select "post"."status", COUNT(*) as "count" from "author" left join "post" on "post"."author_id" = "author"."id" group by "post"."status"');

    test('`:@join` on an allowed field that is not a relation is rejected, not invoked')
        ->expect(fn () => Author::curio()->aggregate('name:@sum(ranking):@join')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression 'name' is not valid: ':@join' requires a relation name.");

    test('reuses a single join when group and aggregate items reference the same relation')
        ->expect(fn () => Author::curio()->aggregate('posts:@group(status):@join posts:@sum(id):@join')->toRawSql())
        ->toBe('select "post"."status", SUM("post"."id") as "posts_sum" from "author" left join "post" on "post"."author_id" = "author"."id" group by "post"."status"');
});

describe('group defaults', function () {
    test('`:@default(0)` wraps the column in COALESCE with an int literal')
        ->expect(fn () => Author::curio()->aggregate('ranking:@group:@default(0)')->toRawSql())
        ->toBe('select COALESCE("author"."ranking", 0) as "ranking" from "author" group by "author"."ranking"');

    test('`:@default(unknown)` wraps the column in COALESCE with a string literal')
        ->expect(fn () => Author::curio()->aggregate('posts:@group(status):@join:@default(unknown) @count')->toRawSql())
        ->toBe('select COALESCE("post"."status", \'unknown\') as "status", COUNT(*) as "count" from "author" left join "post" on "post"."author_id" = "author"."id" group by "post"."status"');

    test('`:@default` composes with `:@alias`')
        ->expect(fn () => Author::curio()->aggregate('ranking:@group:@default(0):@alias(rank)')->toRawSql())
        ->toBe('select COALESCE("author"."ranking", 0) as "rank" from "author" group by "author"."ranking"');
});

describe('having', function () {
    test('`:@having(null)` produces IS NULL, not = NULL')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(null)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") is null');

    test('`:@having(filled)` produces IS NOT NULL')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(filled)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") is not null');

    test('`:@having(-null)` produces IS NOT NULL via negation')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(-null)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") is not null');

    test('`:@having(a..b)` produces BETWEEN')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(100..200)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") between 100 and 200');

    test('`:@having(-a..b)` produces NOT BETWEEN')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(-100..200)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") not between 100 and 200');

    test('`:@having(a,b)` produces IN')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(100,200)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") in (100, 200)');

    test('`:@having(-a,b)` produces NOT IN')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(-100,200)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") not in (100, 200)');

    test('`:@having(*value*)` produces LIKE')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(*bar*)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") like \'%bar%\'');

    test('`:@having(-*value*)` produces NOT LIKE')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(-*bar*)')->toRawSql())
        ->toBe('select SUM("author"."ranking") as "ranking_sum" from "author" having SUM("author"."ranking") not like \'%bar%\'');

    test('`:@join` composed with `:@having`')
        ->expect(fn () => Author::curio()->aggregate('posts:@count:@join:@having(>=2)')->toRawSql())
        ->toBe('select COUNT("post"."id") as "posts_count" from "author" left join "post" on "post"."author_id" = "author"."id" having COUNT("post"."id") >= 2');
});

describe('validation errors', function () {
    test('an empty aggregate string is a no-op, even with allowed fields configured')
        ->expect(fn () => Author::curio()->aggregate('')->toRawSql())
        ->toBe('select * from "author"');

    test('a whitespace-only aggregate string is a no-op')
        ->expect(fn () => Author::curio()->aggregate(' ')->toRawSql())
        ->toBe('select * from "author"');

    test('a model with no allowed aggregate fields rejects any aggregate')
        ->expect(fn () => User::curio()->aggregate('name:@group')->toRawSql())
        ->throws(ValidationException::class, "The field 'name' is not allowed on query aggregate.");

    test('a field outside the allowed list is rejected')
        ->expect(fn () => Author::curio()->aggregate('email:@group')->toRawSql())
        ->throws(ValidationException::class, "The field 'email' is not allowed on query aggregate.");

    test('a bare field with no role token is rejected')
        ->expect(fn () => Author::curio()->aggregate('ranking')->toRawSql())
        ->throws(ValidationException::class);

    test('`@count:@join` with no relation name is rejected')
        ->expect(fn () => Author::curio()->aggregate('@count:@join')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression '*' is not valid: ':@join' requires an explicit relation name.");

    test('the wildcard combined with a non-count function is rejected')
        ->expect(fn () => Author::curio()->aggregate('*:@sum')->toRawSql())
        ->throws(ValidationException::class, "The wildcard '*' can only be used with the @count function.");

    test('`distinct:column` on a real field (not the wildcard) is rejected')
        ->expect(fn () => Author::curio()->aggregate('ranking:@count(distinct:id)')->toRawSql())
        ->throws(ValidationException::class);

    test('a non-count function with arguments is rejected')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum(foo)')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression 'ranking' is not valid: '@sum' does not accept arguments.");

    test('a non-count joined function with no column is rejected')
        ->expect(fn () => Author::curio()->aggregate('posts:@sum:@join')->toRawSql())
        ->throws(ValidationException::class);

    test('an invalid @count argument is rejected')
        ->expect(fn () => Author::curio()->aggregate('@count(bogus)')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression '*' is not valid: '@count' only accepts no arguments or 'distinct:column'.");

    test('a date-part function with arguments is rejected')
        ->expect(fn () => Author::curio()->aggregate('created_at:@month(foo)')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression 'created_at' is not valid: '@month' does not accept arguments.");

    test('`:@group` with arguments but no `:@join` is rejected')
        ->expect(fn () => Author::curio()->aggregate('ranking:@group(foo)')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression 'ranking' is not valid: '@group' does not accept arguments unless combined with ':@join'.");

    test('a joined `:@group` with no related column is rejected')
        ->expect(fn () => Author::curio()->aggregate('posts:@group:@join')->toRawSql())
        ->throws(ValidationException::class);

    test('a relation-dotted field name is rejected')
        ->expect(fn () => Author::curio()->aggregate('posts.title:@group')->toRawSql())
        ->throws(ValidationException::class);

    test('an unrecognized modifier is rejected')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@bogus')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression 'ranking' is not valid: '@bogus' is not allowed here.");

    test('invalid alias characters are rejected')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@alias(bad-name)')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression 'ranking' is not valid.");

    test('an empty `:@having` value is rejected')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having()')->toRawSql())
        ->throws(ValidationException::class, "The aggregate expression 'ranking' is not valid: '@having' requires a value.");

    test('a `:@having` value that is only a negation sign is rejected')
        ->expect(fn () => Author::curio()->aggregate('ranking:@sum:@having(-)')->toRawSql())
        ->throws(ValidationException::class, "The having expression '' is not valid.");

    test('joining two different relations is rejected')
        ->expect(fn () => Author::curio()->aggregate('posts:@count:@join comments:@count:@join')->toRawSql())
        ->throws(ValidationException::class, "Only one relation can be joined via ':@join' per query aggregate.");
});
