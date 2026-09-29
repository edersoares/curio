<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;

describe('searching', function () {
    test('free text search matches against searchBy fields with the default like mode')
        ->expect(fn () => Author::curio()->filter('eder'))
        ->toRunQuery('select * from "author" where ("author"."name" like \'%eder%\')');

    test('free text search uses trgm similarity when configured')
        ->defer(fn () => config()->set('curio.search.mode', 'trgm'))
        ->expect(fn () => Author::curio()->filter('eder')->toRawSql())
        ->toBe('select * from "author" where (similarity(unaccent("author"."name"), unaccent(\'eder\')) > 0.3)');

    test('free text search is accent-insensitive when unaccent mode is configured')
        ->defer(fn () => config()->set('curio.search.mode', 'unaccent'))
        ->expect(fn () => Author::curio()->filter('eder')->toRawSql())
        ->toBe('select * from "author" where (unaccent(lower("author"."name")) like unaccent(lower(\'%eder%\')))');
});
