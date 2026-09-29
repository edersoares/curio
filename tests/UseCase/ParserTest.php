<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Language\Parser;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->parser = new Parser();
});

describe('aggregate', function () {
    test('`ranking:@group`')
        ->expect(fn () => $this->parser->transform('ranking:@group', 'aggregate'))
        ->toFirstBe([
            'type' => 'aggregate',
            'key' => 'ranking',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'group' => [],
            ],
        ]);

    test('`ranking:@group:@alias(rank)`')
        ->expect(fn () => $this->parser->transform('ranking:@group:@alias(rank)', 'aggregate'))
        ->toFirstBe([
            'type' => 'aggregate',
            'key' => 'ranking',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'group' => [],
                'alias' => ['rank'],
            ],
        ]);

    test('`created_at:@month`')
        ->expect(fn () => $this->parser->transform('created_at:@month', 'aggregate'))
        ->toFirstBe([
            'type' => 'aggregate',
            'key' => 'created_at',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'month' => [],
            ],
        ]);

    test('`created_at:@month:@group`')
        ->expect(fn () => $this->parser->transform('created_at:@month:@group', 'aggregate'))
        ->toFirstBe([
            'type' => 'aggregate',
            'key' => 'created_at',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'month' => [],
                'group' => [],
            ],
        ]);

    test('`ranking:@sum:@having(>=100)`')
        ->expect(fn () => $this->parser->transform('ranking:@sum:@having(>=100)', 'aggregate'))
        ->toFirstBe([
            'type' => 'aggregate',
            'key' => 'ranking',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'sum' => [],
                'having' => ['>=100'],
            ],
        ]);
});

describe('filter', function () {
    test('`key:value` / equals')
        ->expect(fn () => $this->parser->transform('email:edersoares@me.com', 'filter'))
        ->toFirstBe([
            'key' => 'email',
            'operator' => '=',
            'value' => 'edersoares@me.com',
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:true` / is true')
        ->expect(fn () => $this->parser->transform('active:true', 'filter'))
        ->toFirstBe([
            'key' => 'active',
            'operator' => '=',
            'value' => true,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:false` / is false')
        ->expect(fn () => $this->parser->transform('active:false', 'filter'))
        ->toFirstBe([
            'key' => 'active',
            'operator' => '=',
            'value' => false,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:>value` / greater than')
        ->expect(fn () => $this->parser->transform('ranking:>75', 'filter'))
        ->toFirstBe([
            'key' => 'ranking',
            'operator' => '>',
            'value' => 75,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:>=value` / greater than or equal')
        ->expect(fn () => $this->parser->transform('ranking:>=90', 'filter'))
        ->toFirstBe([
            'key' => 'ranking',
            'operator' => '>=',
            'value' => 90,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:<=value` / less than or equal')
        ->expect(fn () => $this->parser->transform('ranking:<=25', 'filter'))
        ->toFirstBe([
            'key' => 'ranking',
            'operator' => '<=',
            'value' => 25,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:<value` / less than')
        ->expect(fn () => $this->parser->transform('ranking:<10', 'filter'))
        ->toFirstBe([
            'key' => 'ranking',
            'operator' => '<',
            'value' => 10,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:a,b` / in')
        ->expect(fn () => $this->parser->transform('ranking:80,90,100', 'filter'))
        ->toFirstBe([
            'key' => 'ranking',
            'operator' => 'in',
            'value' => [80, 90, 100],
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:a..b` / between')
        ->expect(fn () => $this->parser->transform('ranking:80..100', 'filter'))
        ->toFirstBe([
            'key' => 'ranking',
            'operator' => 'between',
            'value' => [80, 100],
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:a..` / range with open end')
        ->expect(fn () => $this->parser->transform('ranking:80..', 'filter'))
        ->toFirstBe([
            'key' => 'ranking',
            'operator' => '>=',
            'value' => 80,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:..b` / range with open start')
        ->expect(fn () => $this->parser->transform('ranking:..100', 'filter'))
        ->toFirstBe([
            'key' => 'ranking',
            'operator' => '<=',
            'value' => 100,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:null` / is null')
        ->expect(fn () => $this->parser->transform('date_of_birth:null', 'filter'))
        ->toFirstBe([
            'key' => 'date_of_birth',
            'operator' => '=',
            'value' => null,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:filled` / is not null')
        ->expect(fn () => $this->parser->transform('date_of_birth:filled', 'filter'))
        ->toFirstBe([
            'key' => 'date_of_birth',
            'operator' => 'filled', // TODO: should be different
            'value' => null,
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:*value*` / contains')
        ->expect(fn () => $this->parser->transform('name:*lorem*', 'filter'))
        ->toFirstBe([
            'key' => 'name',
            'operator' => 'like',
            'value' => '%lorem%',
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:value*` / starts with')
        ->expect(fn () => $this->parser->transform('name:lorem*', 'filter'))
        ->toFirstBe([
            'key' => 'name',
            'operator' => 'like',
            'value' => 'lorem%',
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`key:*value` / ends with')
        ->expect(fn () => $this->parser->transform('name:*lorem', 'filter'))
        ->toFirstBe([
            'key' => 'name',
            'operator' => 'like',
            'value' => '%lorem',
            'type' => 'filter',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('a bare word with no `key:` prefix is free text search, not a key')
        ->expect(fn () => $this->parser->transform('lorem', 'filter'))
        ->toFirstBe([
            'type' => 'filter',
            'key' => 'search',
            'operator' => 'search',
            'value' => 'lorem',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('a quoted phrase is free text search, with the quotes stripped')
        ->expect(fn () => $this->parser->transform('"lorem ipsum"', 'filter'))
        ->toFirstBe([
            'type' => 'filter',
            'key' => 'search',
            'operator' => 'search',
            'value' => 'lorem ipsum',
            'negated' => false,
            'modifiers' => [],
        ]);

    test('multiple free-text words/phrases scattered through the string merge into one search clause')
        ->expect(fn () => $this->parser->transform('lorem ranking:5 "ipsum dolor" active:true sit', 'filter'))
        ->toBe([
            [
                'key' => 'ranking',
                'operator' => '=',
                'value' => 5,
                'type' => 'filter',
                'negated' => false,
                'modifiers' => [],
            ],
            [
                'key' => 'active',
                'operator' => '=',
                'value' => true,
                'type' => 'filter',
                'negated' => false,
                'modifiers' => [],
            ],
            [
                'type' => 'filter',
                'key' => 'search',
                'operator' => 'search',
                'value' => 'lorem ipsum dolor sit',
                'negated' => false,
                'modifiers' => [],
            ],
        ]);
});

describe('include', function () {
    test('`key`')
        ->expect(fn () => $this->parser->transform('posts', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`posts.comments`')
        ->expect(fn () => $this->parser->transform('posts.comments', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts.comments',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`posts:@count`')
        ->expect(fn () => $this->parser->transform('posts:@count', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'count' => [],
            ],
        ]);

    test('`posts:@count(distinct:id)`')
        ->expect(fn () => $this->parser->transform('posts:@count(distinct:id)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'count' => ['distinct:id'],
            ],
        ]);

    test('`posts:@sum(id)`')
        ->expect(fn () => $this->parser->transform('posts:@sum(id)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'sum' => ['id'],
            ],
        ]);

    test('`posts:@avg(id)`')
        ->expect(fn () => $this->parser->transform('posts:@avg(id)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'avg' => ['id'],
            ],
        ]);

    test('`posts:@min(id)`')
        ->expect(fn () => $this->parser->transform('posts:@min(id)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'min' => ['id'],
            ],
        ]);

    test('`posts:@max(id)`')
        ->expect(fn () => $this->parser->transform('posts:@max(id)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'max' => ['id'],
            ],
        ]);

    test('`posts:@count:@alias(total)`')
        ->expect(fn () => $this->parser->transform('posts:@count:@alias(total)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'count' => [],
                'alias' => ['total'],
            ],
        ]);

    test('`posts:@only(status:draft)`')
        ->expect(fn () => $this->parser->transform('posts:@only(status:draft)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'only' => ['status:draft'],
            ],
        ]);

    test('`posts:@filter(status:draft)`')
        ->expect(fn () => $this->parser->transform('posts:@filter(status:draft)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'filter' => ['status:draft'],
            ],
        ]);

    test('`posts:@filter(status:draft created_at:2026-01-01)`')
        ->expect(fn () => $this->parser->transform('posts:@filter(status:draft created_at:2026-01-01)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'filter' => ['status:draft created_at:2026-01-01'],
            ],
        ]);

    test('`posts:@filter(status:draft active:true)`')
        ->expect(fn () => $this->parser->transform('posts:@filter(status:draft active:true)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'filter' => ['status:draft active:true'],
            ],
        ]);

    test('`posts:@limit(3)`')
        ->expect(fn () => $this->parser->transform('posts:@limit(3)', 'include'))
        ->toFirstBe([
            'type' => 'include',
            'key' => 'posts',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'limit' => ['3'],
            ],
        ]);
});

describe('sort', function () {
    test('`author.name`')
        ->expect(fn () => $this->parser->transform('author.name', 'sort'))
        ->toFirstBe([
            'type' => 'sort',
            'key' => 'author.name',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`-author.name`')
        ->expect(fn () => $this->parser->transform('-author.name', 'sort'))
        ->toFirstBe([
            'type' => 'sort',
            'key' => 'author.name',
            'operator' => null,
            'value' => null,
            'negated' => true,
            'modifiers' => [],
        ]);

    test('`latestPost.title`')
        ->expect(fn () => $this->parser->transform('latestPost.title', 'sort'))
        ->toFirstBe([
            'type' => 'sort',
            'key' => 'latestPost.title',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [],
        ]);

    test('`-latestPost.title`')
        ->expect(fn () => $this->parser->transform('-latestPost.title', 'sort'))
        ->toFirstBe([
            'type' => 'sort',
            'key' => 'latestPost.title',
            'operator' => null,
            'value' => null,
            'negated' => true,
            'modifiers' => [],
        ]);

    test('`email:@unaccent`')
        ->expect(fn () => $this->parser->transform('email:@unaccent', 'sort'))
        ->toFirstBe([
            'type' => 'sort',
            'key' => 'email',
            'operator' => null,
            'value' => null,
            'negated' => false,
            'modifiers' => [
                'unaccent' => [],
            ],
        ]);

    test('`-email:@unaccent`')
        ->expect(fn () => $this->parser->transform('-email:@unaccent', 'sort'))
        ->toFirstBe([
            'type' => 'sort',
            'key' => 'email',
            'operator' => null,
            'value' => null,
            'negated' => true,
            'modifiers' => [
                'unaccent' => [],
            ],
        ]);

    test('`-email:@unaccent ranking`')
        ->expect(fn () => $this->parser->transform('-email:@unaccent ranking', 'sort'))
        ->toBe([
            [
                'type' => 'sort',
                'key' => 'email',
                'operator' => null,
                'value' => null,
                'negated' => true,
                'modifiers' => [
                    'unaccent' => [],
                ],
            ],
            [
                'type' => 'sort',
                'key' => 'ranking',
                'operator' => null,
                'value' => null,
                'negated' => false,
                'modifiers' => [],
            ],
        ]);

    test('a quoted phrase, which only `filter` treats as free text, fails to parse for any other type')
        ->throws(ValidationException::class, "The sort expression '\"lorem ipsum\"' is not valid.")
        ->expect(fn () => $this->parser->transform('"lorem ipsum"', 'sort'));
});

/**
 * The error bag key is part of the HTTP contract `PaginateRequest` returns to
 * the client, so a malformed token has to be reported under the query
 * parameter that actually carried it - `Parser::transform()` used to report
 * every type under `include`, which sent a client debugging a bad `sort=`
 * looking at the wrong parameter.
 */
describe('malformed token error key', function () {
    test('is reported under the type that was parsed')
        ->expect(function () {
            $errors = [];

            foreach (['select', 'aggregate', 'cast', 'include', 'sort'] as $type) {
                try {
                    $this->parser->transform('"lorem ipsum"', $type);
                } catch (ValidationException $exception) {
                    $errors[$type] = $exception->errors();
                }
            }

            return $errors;
        })
        ->toBe([
            'select' => ['select' => ['The select expression \'"lorem ipsum"\' is not valid.']],
            'aggregate' => ['aggregate' => ['The aggregate expression \'"lorem ipsum"\' is not valid.']],
            'cast' => ['cast' => ['The cast expression \'"lorem ipsum"\' is not valid.']],
            'include' => ['include' => ['The include expression \'"lorem ipsum"\' is not valid.']],
            'sort' => ['sort' => ['The sort expression \'"lorem ipsum"\' is not valid.']],
        ]);
});
