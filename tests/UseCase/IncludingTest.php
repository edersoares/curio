<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Http\Queries\PostQuery;
use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Illuminate\Validation\ValidationException;

describe('include', function () {
    test('`relation` / eager load')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts'))
        ->toRunQuery('select * from "author"')
        ->toRunQuery('select * from "post" where "post"."author_id" in (?)');

    test('`relation.nested` / eager load')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts.comments'))
        ->toRunQuery('select * from "author"')
        ->toRunQuery('select * from "post" where "post"."author_id" in (?)')
        ->toRunQuery('select * from "comment" where "comment"."post_id" in (?)');

    // TODO: not allowed
    test('`-relation` / eager load')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('-posts'))
        ->toRunQuery('select * from "author"')
        ->toRunQuery('select * from "post" where "post"."author_id" in (?)');
});

describe('aggregates', function () {
    test('`relation:@count` / count relation')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@count'))
        ->toRunQuery('select "author".*, (select count(*) from "post" where "author"."id" = "post"."author_id") as "posts_count" from "author"');

    test('`relation:@count(distinct:id)` / count relation using distinct column')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@count(distinct:id)'))
        ->toRunQuery('select "author".*, (select count(distinct "post"."id") from "post" where "author"."id" = "post"."author_id") as "posts_count_distinct_id" from "author"');

    test('`relation:@sum(key)` / sum relation')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@sum(id)'))
        ->toRunQuery('select "author".*, (select sum("post"."id") from "post" where "author"."id" = "post"."author_id") as "posts_sum_id" from "author"');

    test('`relation:@avg(key)` / avg relation')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@avg(id)'))
        ->toRunQuery('select "author".*, (select avg("post"."id") from "post" where "author"."id" = "post"."author_id") as "posts_avg_id" from "author"');

    test('`relation:@min(key)` / min relation')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@min(id)'))
        ->toRunQuery('select "author".*, (select min("post"."id") from "post" where "author"."id" = "post"."author_id") as "posts_min_id" from "author"');

    test('`relation:@max(key)` / max relation')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@max(id)'))
        ->toRunQuery('select "author".*, (select max("post"."id") from "post" where "author"."id" = "post"."author_id") as "posts_max_id" from "author"');
});

describe('modifiers', function () {
    test('`relation:@count:@alias(key)` / count relation with alias')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@count:@alias(total)'))
        ->toRunQuery('select "author".*, (select count(*) from "post" where "author"."id" = "post"."author_id") as "total" from "author"');

    test('`relation:@only(filter)` / only relation with filter')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@only(status:draft)'))
        ->toRunQuery('select * from "author"')
        ->toRunQuery('select * from "post" where "post"."author_id" in (?) and "post"."status" = \'draft\'');

    test('`relation:@filter(filter)` / filter relation')
        ->defer(fn () => Post::factory()->create(['status' => 'draft'])) // Needs to have one post in draft
        ->expect(fn () => Author::curio()->include('posts:@filter(status:draft)'))
        ->toRunQuery('select * from "author" where exists (select * from "post" where "author"."id" = "post"."author_id" and "post"."status" = \'draft\')')
        ->toRunQuery('select * from "post" where "post"."author_id" in (?) and "post"."status" = \'draft\'');

    test('`posts:@filter(status:draft published_at:null)')
        ->defer(fn () => Post::factory()->create(['status' => 'draft'])) // Needs to have one post in draft
        ->expect(fn () => Author::curio()->include('posts:@filter(status:draft published_at:null)'))
        ->toRunQuery('select * from "author" where exists (select * from "post" where "author"."id" = "post"."author_id" and "post"."status" = \'draft\' and "post"."published_at" is null)')
        ->toRunQuery('select * from "post" where "post"."author_id" in (?) and "post"."status" = \'draft\' and "post"."published_at" is null');

    test('`relation:@limit(3)` / limit relation')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@limit(3)'))
        ->toRunQuery('select * from (select *, row_number() over (partition by "post"."author_id") as "laravel_row" from "post" where "post"."author_id" in (1)) as "laravel_table" where "laravel_row" <= 3 order by "laravel_row"');

    test('`relation:@count:@only(filter)` / aggregation combined with an @only filter')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@count:@only(status:draft)'))
        ->toRunQuery('select "author".*, (select count(*) from "post" where "author"."id" = "post"."author_id" and "post"."status" = \'draft\') as "posts_count" from "author"');

    test('`relation:@only(filter):@limit(3)` / only filter combined with a limit')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@only(status:draft):@limit(3)'))
        ->toRunQuery('select * from "author"')
        ->toRunQuery('select * from (select *, row_number() over (partition by "post"."author_id") as "laravel_row" from "post" where "post"."author_id" in (1) and "post"."status" = \'draft\') as "laravel_table" where "laravel_row" <= 3 order by "laravel_row"');
});

describe('unexpected input', function () {
    // TODO: alias is not applied, maybe convert to exception
    test('`relation:@alias` / alias is not applied')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@alias'))
        ->toRunQuery('select * from "author"');

    test('`relation:@count:@alias` / a bare `@alias` with no value now throws')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('posts:@count:@alias')->get())
        ->throws(ValidationException::class, "The include expression 'posts' is not valid: '@alias' requires a value.");

    test('`relation:@count:@alias(not an identifier)` is rejected instead of becoming an SQL alias')
        ->expect(fn () => Author::curio()->include('posts:@count:@alias(a b)')->get())
        ->throws(ValidationException::class, "The include expression 'posts' is not valid: '@alias' requires a valid identifier.");

    /**
     * A dotted alias was read back as `relation.column` by `Sorting` once
     * `Listeners\AllowSortIncludes` registered it as sortable, which made
     * `sort=` resolve it by calling `$model->{relation}()` - see
     * `Including::requireAliasValue()`.
     */
    test('`relation:@count:@alias(truncate.x)` / an alias carrying a `.` is rejected')
        ->expect(fn () => Author::curio()->include('posts:@count:@alias(truncate.x)')->get())
        ->throws(ValidationException::class, "The include expression 'posts' is not valid: '@alias' requires a valid identifier.");

    // TODO: why `nope` does not coverage
    test('`nope:@only(x:1)` wrong relation')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Author::curio()->include('nope:@only(x:1)')->get())
        ->throws(ValidationException::class, "The relation 'nope' is not allowed on query include");

    test('`nope` / a plain include naming an unknown relation is rejected, not forwarded to the builder')
        ->expect(fn () => Author::curio()->include('nope')->get())
        ->throws(ValidationException::class, "The relation 'nope' is not allowed on query include");

    test('`has:usage` / a stray filter-shaped token outside `filter=` falls back to a plain include and is still rejected')
        ->expect(fn () => Author::curio()->include('has:usage')->get())
        ->throws(ValidationException::class, "The relation 'has' is not allowed on query include");

    test('`posts.author.posts.author` / a chain deeper than maxIncludeDepth() is rejected')
        ->expect(fn () => Author::curio()->include('posts.author.posts.author')->get())
        ->throws(ValidationException::class, "The relation 'posts.author.posts.author' is nested deeper than the maximum include depth of 3.");

    /**
     * Every offending item is reported, not just the first: an item rejected
     * for its depth must not stop the loop before the ones after it are
     * checked.
     */
    test('a too-deep chain does not hide the items validated after it')
        ->expect(function () {
            try {
                Author::curio()->include('posts.author.posts.author nope')->get();
            } catch (ValidationException $exception) {
                return $exception->errors()['include'];
            }
        })
        ->toBe([
            "The relation 'posts.author.posts.author' is nested deeper than the maximum include depth of 3.",
            "The relation 'nope' is not allowed on query include.",
        ]);

    test('`posts.author.posts` / a chain at exactly maxIncludeDepth() is accepted')
        ->expect(fn () => Author::curio()->include('posts.author.posts')->get())
        ->not->toThrow(ValidationException::class);

    /**
     * `includeBy()` vouches for the name, but it maps names to
     * `PaginateQuery` classes - it cannot know whether the model still
     * declares the relation. `Model::__call()` forwards an unknown name to
     * the query builder, so resolving it blindly would invoke whatever
     * builder method the name matches (here `truncate()`).
     * `relation:@count(distinct:column)` is the one include shape that
     * resolves the relation itself, via `qualifyRelationColumn()`.
     */
    test('`relation:@count(distinct:column)` on an allow-listed name that is not a relation is rejected, not invoked')
        ->expect(function () {
            $model = new class() extends Author {
                public function includeBy(): array
                {
                    return ['truncate' => PostQuery::class];
                }
            };

            return $model::curio($model->newQuery())->include('truncate:@count(distinct:id)')->get();
        })
        ->throws(ValidationException::class, "The relation 'truncate' is not allowed on query include.");

    test('`posts.nope` / a nested plain include naming an unknown relation is rejected')
        ->expect(fn () => Author::curio()->include('posts.nope')->get())
        ->throws(ValidationException::class, "The relation 'posts.nope' is not allowed on query include");

    test('`relation:@count:@bogus` / an unrecognized modifier on an aggregation is rejected')
        ->expect(fn () => Author::curio()->include('posts:@count:@bogus')->get())
        ->throws(ValidationException::class, "The include expression 'posts' is not valid: '@bogus' is not allowed here.");

    test('`relation:@limit(3)` on a `BelongsTo` relation is rejected')
        ->defer(fn () => Post::factory()->create())
        ->expect(fn () => Post::curio()->include('author:@limit(3)')->get())
        ->throws(ValidationException::class, "The relation 'author' does not support ':@limit()' because it does not return multiple rows per parent.");

    test('`relation:@count(bogus)` / an invalid @count argument is rejected')
        ->expect(fn () => Author::curio()->include('posts:@count(bogus)')->get())
        ->throws(ValidationException::class, "The include expression 'posts' is not valid: '@count' only accepts no arguments or 'distinct:column'.");

    test('`relation:@sum` with no column is rejected')
        ->expect(fn () => Author::curio()->include('posts:@sum')->get())
        ->throws(ValidationException::class, "The include expression 'posts' is not valid: '@sum' requires a column, e.g. '@sum(column)'.");

    test('`relation:@sum(distinct:id)` / `distinct` is only supported with `@count`')
        ->expect(fn () => Author::curio()->include('posts:@sum(distinct:id)')->get())
        ->throws(ValidationException::class, "The include expression 'posts' is not valid: 'distinct' is only supported with '@count'.");

    test('`relation:@sum(bad-arg)` / a non-word aggregation argument is rejected')
        ->expect(fn () => Author::curio()->include('posts:@sum(bad-arg)')->get())
        ->throws(ValidationException::class, "The include expression 'posts' is not valid.");
});
