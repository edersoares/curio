<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Illuminate\Validation\ValidationException;

/**
 * `has:` takes exactly one relation name - a list, a range, a wildcard or
 * `null` is a 422, never a clause on a `has` column that doesn't exist.
 */
describe('has: relation value', function () {
    test('a single relation name is still accepted')
        ->expect(fn () => Author::curio()->filter('has:posts'))
        ->toRunQuery('select * from "author" where exists (select * from "post" where "author"."id" = "post"."author_id")');

    test('a list of relations is rejected')
        ->expect(fn () => Author::curio()->filter('has:posts,posts')->toRawSql())
        ->throws(ValidationException::class, "The 'posts,posts' relation filter is not valid - 'has:' takes a single relation name.");

    test('a negated list of relations is rejected')
        ->expect(fn () => Author::curio()->filter('-has:posts,posts')->toRawSql())
        ->throws(ValidationException::class, "The 'posts,posts' relation filter is not valid - 'has:' takes a single relation name.");

    test('a wildcard is rejected')
        ->expect(fn () => Author::curio()->filter('has:*posts*')->toRawSql())
        ->throws(ValidationException::class, "The '*posts*' relation filter is not valid - 'has:' takes a single relation name.");

    test('a range is rejected')
        ->expect(fn () => Author::curio()->filter('has:a..b')->toRawSql())
        ->throws(ValidationException::class, "The 'a..b' relation filter is not valid - 'has:' takes a single relation name.");

    test('`null` is rejected')
        ->expect(fn () => Author::curio()->filter('has:null')->toRawSql())
        ->throws(ValidationException::class, "The 'null' relation filter is not valid - 'has:' takes a single relation name.");

    test('`filled` is rejected')
        ->expect(fn () => Author::curio()->filter('has:filled')->toRawSql())
        ->throws(ValidationException::class, "The 'filled' relation filter is not valid - 'has:' takes a single relation name.");
});
