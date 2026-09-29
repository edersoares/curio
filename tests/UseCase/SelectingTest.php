<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\User;
use Illuminate\Validation\ValidationException;

describe('model selecting', function () {
    test('`key` selecting')
        ->expect(fn () => Author::curio()->select('name ranking'))
        ->toRunQuery('select "author"."name", "author"."ranking" from "author"');

    test('`relation` selecting')
        ->expect(fn () => Author::curio()->select('name posts.title'))
        ->toRunQuery('select "author"."name" from "author"');

    test('`relation` selecting, without model column')
        ->expect(fn () => Author::curio()->select('posts.title'))
        ->toRunQuery('select * from "author"');

    test('`alias` selecting')
        ->expect(fn () => Author::curio()->select('name:@alias(nickname)'))
        ->toRunQuery('select "author"."name" as "nickname" from "author"');

    test('`year` selecting')
        ->expect(fn () => Author::curio()->select('created_at:@year'))
        ->toRunQuery('select CAST(strftime(\'%Y\', "author"."created_at") as integer) as "created_at" from "author"');

    test('`month` selecting')
        ->expect(fn () => Author::curio()->select('created_at:@month'))
        ->toRunQuery('select CAST(strftime(\'%m\', "author"."created_at") as integer) as "created_at" from "author"');

    test('`day` selecting')
        ->expect(fn () => Author::curio()->select('created_at:@day'))
        ->toRunQuery('select CAST(strftime(\'%d\', "author"."created_at") as integer) as "created_at" from "author"');

    test('`hour` selecting')
        ->expect(fn () => Author::curio()->select('created_at:@hour'))
        ->toRunQuery('select CAST(strftime(\'%H\', "author"."created_at") as integer) as "created_at" from "author"');

    test('`minute` selecting')
        ->expect(fn () => Author::curio()->select('created_at:@minute'))
        ->toRunQuery('select CAST(strftime(\'%M\', "author"."created_at") as integer) as "created_at" from "author"');

    test('`second` selecting')
        ->expect(fn () => Author::curio()->select('created_at:@second'))
        ->toRunQuery('select CAST(strftime(\'%S\', "author"."created_at") as integer) as "created_at" from "author"');
});

describe('validation errors', function () {
    test('an empty select string is a no-op, even with allowed fields configured')
        ->expect(fn () => Author::curio()->select('')->toRawSql())
        ->toBe('select * from "author"');

    test('a model with no allowed select fields rejects any select')
        ->expect(fn () => User::curio()->select('name')->toRawSql())
        ->throws(ValidationException::class, 'There is no allowed fields to select.');

    test('a field outside the allowed list is rejected')
        ->expect(fn () => Author::curio()->select('nickname')->toRawSql())
        ->throws(ValidationException::class, "The fields 'nickname' is not allowed to select.");

    test('a whitespace-only select string is a no-op, before ever checking allowed fields')
        ->expect(fn () => Author::curio()->select(' ')->toRawSql())
        ->toBe('select * from "author"');

    test('a value-shaped select token is rejected')
        ->expect(fn () => Author::curio()->select('name:value')->toRawSql())
        ->throws(ValidationException::class, "The select expression 'name' is not valid.");

    test('a date-part function with arguments is rejected')
        ->expect(fn () => Author::curio()->select('created_at:@month(foo)')->toRawSql())
        ->throws(ValidationException::class, "The select expression 'created_at' is not valid: '@month' does not accept arguments.");

    test('an unrecognized modifier on a plain field is rejected')
        ->expect(fn () => Author::curio()->select('ranking:@bogus')->toRawSql())
        ->throws(ValidationException::class, "The select expression 'ranking' is not valid: '@bogus' is not allowed here.");

    test('invalid alias characters are rejected')
        ->expect(fn () => Author::curio()->select('name:@alias(bad-name)')->toRawSql())
        ->throws(ValidationException::class, "The select expression 'name' is not valid.");
});
