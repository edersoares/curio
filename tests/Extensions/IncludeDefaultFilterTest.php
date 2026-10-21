<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Http\Queries\CommentQuery;
use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Comment;
use Dex\Laravel\Curio\Workbench\App\Models\Post;

/**
 * `CommentQuery::defaultFilter()` is `excluded:false`: `api/comment` hides
 * excluded comments unless the caller filters explicitly. Reached through an
 * `include=` of another endpoint, the comments behave the same way - the
 * default applies, and `@filter(...)`/`@only(...)` replace it.
 */
beforeEach(function () {
    $this->author = Author::factory()->create();
    $this->post = Post::factory()->create(['author_id' => $this->author->id]);

    Comment::factory()->create(['author_id' => $this->author->id, 'post_id' => $this->post->id, 'excluded' => false, 'title' => 'Visible']);
    Comment::factory()->create(['author_id' => $this->author->id, 'post_id' => $this->post->id, 'excluded' => true, 'title' => 'Hidden']);
});

function postRow(mixed $test, array $query): array
{
    $response = $test->getJson('api/post?' . http_build_query($query));

    $response->assertOk();

    return collect($response->json('data'))->firstWhere('id', $test->post->id);
}

function commentTitles(array $row): array
{
    return collect($row['comments'])->pluck('title')->sort()->values()->all();
}

test('a plain include applies the related query\'s default filter', function () {
    expect(commentTitles(postRow($this, ['include' => 'comments'])))->toBe(['Visible']);
});

test('`@limit(n)` keeps the default filter', function () {
    expect(commentTitles(postRow($this, ['include' => 'comments:@limit(5)'])))->toBe(['Visible']);
});

test('`@only(expr)` replaces the default filter', function () {
    expect(commentTitles(postRow($this, ['include' => 'comments:@only(excluded:true)'])))->toBe(['Hidden']);
});

test('`@filter(expr)` replaces the default filter, on the parent constraint and on the eager load', function () {
    $response = $this->getJson('api/post?' . http_build_query(['include' => 'comments:@filter(excluded:true)']));

    $response->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect(commentTitles($response->json('data.0')))->toBe(['Hidden']);
});

test('an aggregate counts what the related endpoint would show', function () {
    expect(postRow($this, ['include' => 'comments:@count'])['comments_count'])->toBe(1);
});

test('an aggregate with `@only(expr)` replaces the default filter', function () {
    expect(postRow($this, ['include' => 'comments:@count:@only(excluded:true)'])['comments_count'])->toBe(1)
        ->and(postRow($this, ['include' => 'comments:@count:@only(title:*i*)'])['comments_count'])->toBe(2);
});

test('the leaf of a dotted include applies its query\'s default filter', function () {
    $response = $this->getJson('api/author?' . http_build_query(['include' => 'posts.comments']));

    $response->assertOk();

    $row = collect($response->json('data'))->firstWhere('id', $this->author->id);
    $post = collect($row['posts'])->firstWhere('id', $this->post->id);

    expect(commentTitles($post))->toBe(['Visible']);
});

test('a `$variable` in the related default filter resolves against the request', function () {
    app()->bind(CommentQuery::class, fn () => new class() extends CommentQuery {
        public function defaultFilter(): string
        {
            return 'title:$comment_title!';
        }
    });

    expect(commentTitles(postRow($this, ['include' => 'comments', 'comment_title' => 'Hidden'])))->toBe(['Hidden']);

    $this->getJson('api/post?' . http_build_query(['include' => 'comments']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('comment_title');
});

test('the fluent path applies no default filter, as at the top level', function () {
    $titles = Post::curio()->include('comments')->whereKey($this->post->id)->first()->comments->pluck('title')->sort()->values()->all();

    expect($titles)->toBe(['Hidden', 'Visible']);
});
