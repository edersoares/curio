<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;

/**
 * Every DSL parameter is attacker-controlled, so no value may reach a
 * `RuntimeException`, a `BadMethodCallException`, or a raw PHP error: invalid
 * input is a 422, never a 500 (which leaks a stack trace whenever
 * `APP_DEBUG` is on, and is trivially repeatable as log pressure).
 *
 * Two shapes are deliberately left out, because there the database - not
 * validation - is what rejects an otherwise well-formed query, and only on
 * some drivers: `filter=<json column>:{"a":1}` against a column that isn't
 * JSON (`whereJsonContains()` behaves the same way outside Curio), and
 * `sort=field:@unaccent` on a driver with no `unaccent()` function (a
 * documented PostgreSQL-only modifier, same as `aggregate=`'s date parts).
 */
test('no DSL input answers with a 500', function () {
    $author = Author::factory()->create();
    Post::factory()->count(2)->create(['author_id' => $author->id]);

    $values = [
        '', ' ', '*', '..', '-', '@', '@@', '$', '$x!', '"', '((', '))', ':@', ':@x(', 'a:@b(c',
        'truncate', 'truncate.x', 'delete.x', 'has:truncate', 'has:truncate has:posts',
        'name:', 'name:a b', '-name:*a*', 'name:a..b', 'name:,,',
        'posts:@count:@alias(a b)', 'posts:@count:@alias(x")', 'posts:@count:@alias(truncate.x)',
        'posts:@limit(99999999999999999999)', 'posts:@filter(has:truncate)', 'posts:@only()',
        'posts:@count(distinct:)', 'ranking:@group:@default()', 'ranking:@group:@having(>)',
        '*:@count:@having(a..)', 'name:@sum:@join', 'posts:@sum(id):@join posts:@group(status):@join',
        'ranking:@rankingTier:@alias(x y)', 'ranking:@nope', 'x:@unaccent',
        'additional->path:1', 'additional->:1', 'posts.author.posts.author.posts:@count',
        str_repeat('a', 2000), str_repeat('posts.', 20) . 'author',
    ];

    $failures = [];

    foreach (['filter', 'sort', 'select', 'aggregate', 'include', 'cast'] as $parameter) {
        foreach ($values as $value) {
            $response = $this->getJson('api/author?' . http_build_query([$parameter => $value]));

            if ($response->getStatusCode() >= 500) {
                $failures[] = "{$parameter}={$value} -> {$response->getStatusCode()}";
            }
        }
    }

    expect($failures)->toBe([]);
});
