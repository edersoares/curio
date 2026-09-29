<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;

beforeEach(function () {
    Author::factory()->create(['name' => 'José Álvares', 'email' => 'jose@example.com']);
    Author::factory()->create(['name' => 'Maria Souza', 'email' => 'maria@example.com']);
});

describe('`trgm` mode', function () {
    beforeEach(fn () => config()->set('curio.search.mode', 'trgm'));

    test('matches by similarity, ignoring accents and case')
        ->expect(fn () => Author::curio()->filter('jose alvares')->get()->pluck('name')->all())
        ->toBe(['José Álvares']);

    test('matches a misspelled term')
        ->expect(fn () => Author::curio()->filter('"joze alvarez"')->get()->pluck('name')->all())
        ->toBe(['José Álvares']);

    test('does not match a term below the similarity threshold')
        ->expect(fn () => Author::curio()->filter('zzzzzz')->get()->pluck('name')->all())
        ->toBe([]);

    test('honours the configured similarity threshold', function () {
        config()->set('curio.search.similarity_threshold', 0.99);

        expect(Author::curio()->filter('"joze alvarez"')->get()->pluck('name')->all())->toBe([]);
    });
});

describe('`unaccent` mode', function () {
    beforeEach(fn () => config()->set('curio.search.mode', 'unaccent'));

    test('matches a substring, ignoring accents and case')
        ->expect(fn () => Author::curio()->filter('ALVARES')->get()->pluck('name')->all())
        ->toBe(['José Álvares']);

    test('does not match a term that is not a substring')
        ->expect(fn () => Author::curio()->filter('alvarez')->get()->pluck('name')->all())
        ->toBe([]);
});

describe('`like` mode', function () {
    beforeEach(fn () => config()->set('curio.search.mode', 'like'));

    test('ignores case')
        ->expect(fn () => Author::curio()->filter('MARIA')->get()->pluck('name')->all())
        ->toBe(['Maria Souza']);

    test('does not ignore accents')
        ->expect(fn () => Author::curio()->filter('jose')->get()->pluck('name')->all())
        ->toBe([]);
});

test('free text combines with key/value clauses over HTTP', function () {
    config()->set('curio.search.mode', 'trgm');

    $response = $this->getJson('api/author?' . http_build_query(['filter' => 'jose email:jose@example.com']));

    $response->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['José Álvares']);
});
