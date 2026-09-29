<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Events\ApplyToken;
use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Comment;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * Filters, allow-list validation and `defaultFilter()` used to be listeners
 * on `ApplyPending`/`ApplyToken`. Whenever the event didn't reach them the
 * query ran without any of it and nothing failed - so a filter that is
 * silently not applied returned every row. `Pipeline` now calls those steps
 * directly; the events are only a hook dispatched before they run.
 */
describe('applying does not depend on the event dispatcher', function () {
    test('`Event::fake()` does not drop a filter')
        ->defer(fn () => Event::fake())
        ->expect(fn () => Author::curio()->filter('ranking:999')->toRawSql())
        ->toBe('select * from "author" where "author"."ranking" = 999');

    test('`Event::fake()` does not drop a negated filter')
        ->defer(fn () => Event::fake())
        ->expect(fn () => Author::curio()->filter('-ranking:>10')->toRawSql())
        ->toBe('select * from "author" where "author"."ranking" <= 10');

    test('`Event::fake()` does not drop a relation filter')
        ->defer(fn () => Event::fake())
        ->expect(fn () => Author::curio()->filter('posts.title:draft')->toRawSql())
        ->toBe('select * from "author" where exists (select * from "post" where "author"."id" = "post"."author_id" and "post"."title" = \'draft\')');

    test('`Event::fake()` does not drop a filter scoped to an include', function () {
        Event::fake();

        $author = Author::factory()->create();
        Post::factory()->create(['author_id' => $author->id, 'title' => 'kept']);
        Post::factory()->create(['author_id' => $author->id, 'title' => 'dropped']);

        $posts = Author::curio()->include('posts:@filter(title:kept)')->whereKey($author->id)->first()->posts;

        expect($posts->pluck('title')->all())->toBe(['kept']);
    });

    test('`Event::fake()` does not drop sort, select or search')
        ->defer(fn () => Event::fake())
        ->expect(fn () => Author::curio()->filter('ada')->select('id name')->sort('-name')->toRawSql())
        ->toBe('select "author"."id", "author"."name" from "author" where ("author"."name" like \'%ada%\') order by "name" desc');

    test('`Event::fake()` does not skip the allow-list validation')
        ->defer(fn () => Event::fake())
        ->expect(fn () => Author::curio()->filter('password:secret')->toRawSql())
        ->throws(ValidationException::class, "The field 'password' is not allowed on query filter.");

    test('`Event::fake()` does not drop a `defaultFilter()` on the HTTP path', function () {
        Event::fake();

        Comment::factory()->create(['excluded' => false, 'title' => 'Visible']);
        Comment::factory()->create(['excluded' => true, 'title' => 'Hidden']);

        $response = $this->getJson('api/comment');

        $response->assertOk();

        expect(collect($response->json('data'))->pluck('title')->all())->toBe(['Visible']);
    });

    test('a listener returning `false` does not halt the pipeline', function () {
        Event::listen(ApplyPending::class, fn () => false);
        Event::listen(ApplyToken::class, fn () => false);

        expect(Author::curio()->filter('ranking:999')->toRawSql())
            ->toBe('select * from "author" where "author"."ranking" = 999');
    });
});

describe('the events are a hook dispatched before applying', function () {
    test('`ApplyPending` and `ApplyToken` are still dispatched', function () {
        Event::fake();

        Author::curio()->filter('ranking:999')->sort('name')->toRawSql();

        Event::assertDispatched(ApplyPending::class, fn (ApplyPending $event) => array_column($event->tokens, 'type') === ['filter', 'sort']);
        Event::assertDispatched(ApplyToken::class, fn (ApplyToken $event) => $event->token->key() === 'ranking');
    });

    test('an `ApplyPending` listener sees the tokens before aliases are resolved', function () {
        $keys = [];

        Event::listen(ApplyPending::class, function (ApplyPending $event) use (&$keys) {
            $keys = array_column($event->tokens, 'key');
        });

        $this->getJson('api/author?' . http_build_query(['sort' => 'date-of-birth']))->assertOk();

        expect($keys)->toBe(['date-of-birth']);
    });

    test('an `ApplyPending` listener can rewrite the tokens that get applied', function () {
        Event::listen(ApplyPending::class, function (ApplyPending $event) {
            $event->tokens = array_map(fn (array $token) => [...$token, 'value' => 1], $event->tokens);
        });

        expect(Author::curio()->filter('ranking:999')->toRawSql())
            ->toBe('select * from "author" where "author"."ranking" = 1');
    });

    test('a rewritten token is still validated against the allow-list', function () {
        Event::listen(ApplyPending::class, function (ApplyPending $event) {
            $event->tokens = array_map(fn (array $token) => [...$token, 'key' => 'password'], $event->tokens);
        });

        expect(fn () => Author::curio()->filter('ranking:999')->toRawSql())
            ->toThrow(ValidationException::class, "The field 'password' is not allowed on query filter.");
    });

    test('an `ApplyToken` listener can rewrite a single token', function () {
        Event::listen(ApplyToken::class, function (ApplyToken $event) {
            $event->token->replace(['value' => 1]);
        });

        expect(Author::curio()->filter('ranking:999')->toRawSql())
            ->toBe('select * from "author" where "author"."ranking" = 1');
    });
});
