<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Language\Casting;
use Dex\Laravel\Curio\Language\Parser;
use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->casting = app(Casting::class);
    $this->parser = app(Parser::class);
});

describe('model casting', function () {
    test('`first`')
        ->defer(fn () => Author::factory()->create(['name' => 'Low', 'ranking' => 10]))
        ->expect(fn () => Author::curio()->cast('ranking:@rankingTier')->first()->getAttribute('casts'))
        ->toBe(['ranking' => 'bronze']);

    test('`get`')
        ->defer(fn () => Author::factory()->create(['name' => 'Low', 'ranking' => 10]))
        ->defer(fn () => Author::factory()->create(['name' => 'Medium', 'ranking' => 20]))
        ->defer(fn () => Author::factory()->create(['name' => 'Very low', 'ranking' => 5]))
        ->expect(fn () => Author::curio()->cast('ranking:@rankingTier')->get()->map(fn ($model) => $model->getAttribute('casts')['ranking'])->all())
        ->toBe(['bronze', 'silver', 5]);

    test('`paginate`')
        ->defer(fn () => Author::factory()->create(['name' => 'Low', 'ranking' => 10]))
        ->defer(fn () => Author::factory()->create(['name' => 'Medium', 'ranking' => 20]))
        ->defer(fn () => Author::factory()->create(['name' => 'Very low', 'ranking' => 5]))
        ->expect(fn () => Author::curio()->cast('ranking:@rankingTier')->paginate()->getCollection()->map(fn ($model) => $model->getAttribute('casts')['ranking'])->all())
        ->toBe(['bronze', 'silver', 5]);

    test('`get` with an `@alias`')
        ->defer(fn () => Author::factory()->create(['name' => 'Low', 'ranking' => 10]))
        ->expect(fn () => Author::curio()->cast('ranking:@rankingTier:@alias(tier)')->get()->first()->getAttribute('casts'))
        ->toBe(['tier' => 'bronze']);

    test('a null field value falls back to itself, unmatched')
        ->defer(fn () => Author::factory()->create(['name' => 'No ranking', 'ranking' => null]))
        ->expect(fn () => Author::curio()->cast('ranking:@rankingTier')->first()->getAttribute('casts'))
        ->toBe(['ranking' => null]);

    test('when a mutator is a callable')
        ->defer(fn () => Author::factory()->create(['date_of_birth' => now()]))
        ->expect(fn () => Author::curio()->cast('date_of_birth:@yearOfBirth')->first()->getAttribute('casts'))
        ->toBe(['date_of_birth' => now()->year]);
});

describe('validation errors', function () {
    test('rejects a non existing cast')
        ->expect(fn () => Post::curio()->cast('ranking:@rankingTier')->get())
        ->throws(ValidationException::class);

    test('rejects a malformed cast expression')
        ->expect(fn () => Author::curio()->cast('ranking')->get())
        ->throws(ValidationException::class);

    test('rejects a cast name that does not start with a letter or underscore')
        ->expect(fn () => Author::curio()->cast('ranking:@1cast')->get())
        ->throws(ValidationException::class);

    test('rejects an unknown modifier other than the cast name or @alias')
        ->expect(fn () => Author::curio()->cast('ranking:@rankingTier:@bogus')->get())
        ->throws(ValidationException::class);

    test('rejects invalid alias characters')
        ->expect(fn () => Author::curio()->cast('ranking:@rankingTier:@alias(bad-name)')->get())
        ->throws(ValidationException::class);

    test('rejects a field not declared in castBy')
        ->expect(fn () => Author::curio()->cast('email:@rankingTier')->get())
        ->throws(ValidationException::class, "The field 'email' is not allowed on query cast.");

    test('rejects an unregistered cast name')
        ->expect(fn () => Author::curio()->cast('ranking:@unknownCast')->get())
        ->throws(ValidationException::class, "The cast 'unknownCast' is not registered on this query.");
});
