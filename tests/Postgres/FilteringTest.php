<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;

beforeEach(function () {
    $this->ada = Author::factory()->create([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'active' => true,
        'ranking' => 10,
        'date_of_birth' => '1990-05-10',
        'additional' => json_encode(['path' => 'x', 'tags' => ['a', 'b'], 'nested' => ['level' => 2]]),
    ]);

    $this->code = Author::factory()->create([
        'name' => '00123',
        'email' => 'code@example.com',
        'active' => false,
        'ranking' => 20,
        'date_of_birth' => '2000-01-01',
        'additional' => json_encode(['path' => 'y']),
    ]);

    Post::factory()->count(2)->create(['author_id' => $this->ada->id, 'title' => 'Engines']);
});

function names(string $filter): array
{
    return Author::curio()->filter($filter)->sort('name')->get()->pluck('name')->all();
}

describe('typed comparisons', function () {
    test('a number-shaped value against a text column')
        ->expect(fn () => names('name:00123'))
        ->toBe(['00123']);

    test('a boolean column')
        ->expect(fn () => names('active:true'))
        ->toBe(['Ada']);

    test('an integer range')
        ->expect(fn () => names('ranking:15..25'))
        ->toBe(['00123']);

    test('a date range')
        ->expect(fn () => names('date_of_birth:1990-01-01..1995-12-31'))
        ->toBe(['Ada']);

    test('an open-ended date range')
        ->expect(fn () => names('date_of_birth:1995-01-01..'))
        ->toBe(['00123']);

    test('a list')
        ->expect(fn () => names('ranking:10,20'))
        ->toBe(['00123', 'Ada']);

    test('a wildcard, ignoring case')
        ->expect(fn () => names('email:*EXAMPLE*'))
        ->toBe(['00123', 'Ada']);

    test('a negated wildcard')
        ->expect(fn () => names('-name:*d*'))
        ->toBe(['00123']);
});

describe('JSON', function () {
    test('a path')
        ->expect(fn () => names('additional->path:x'))
        ->toBe(['Ada']);

    test('containment')
        ->expect(fn () => names('additional:{"nested":{"level":2}}'))
        ->toBe(['Ada']);

    test('containment of an array item')
        ->expect(fn () => names('additional:{"tags":["b"]}'))
        ->toBe(['Ada']);

    test('negated containment')
        ->expect(fn () => names('-additional:{"path":"x"}'))
        ->toBe(['00123']);
});

describe('relations', function () {
    test('through a relation')
        ->expect(fn () => names('posts.title:Engines'))
        ->toBe(['Ada']);

    test('having a relation')
        ->expect(fn () => names('has:posts'))
        ->toBe(['Ada']);

    test('not having a relation')
        ->expect(fn () => names('-has:posts'))
        ->toBe(['00123']);

    test('having a number of related rows')
        ->expect(fn () => names('has:posts:@count(>=3)'))
        ->toBe([]);
});
