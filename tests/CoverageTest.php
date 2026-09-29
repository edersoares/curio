<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Language\Aggregating;
use Dex\Laravel\Curio\Language\Preset;
use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Comment;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Dex\Laravel\Curio\Workbench\App\Models\User;
use Dex\Laravel\Curio\YourCuriosity;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;

/**
 * Closes real gaps only visible with `@codeCoverageIgnore` disabled (see
 * `XDEBUG_MODE=coverage vendor/bin/pest --coverage --disable-coverage-ignore`)
 * - genuinely reachable branches the default coverage run silently hides
 * behind an ignore annotation. Kept separate from the suites under
 * `tests/{UseCase,Extensions,Eloquent}` so these can be reviewed as their own
 * unit before folding into the existing suite structure.
 *
 * Lines checked and left alone (real, not fake, unreachable code - verified
 * by tracing every caller, not assumed from the annotation alone):
 * `Aggregating::scanModifiers()`'s `'join'` match arm (every caller that
 * allows `'join'` also consumes it, so it's always skipped before reaching
 * the match) and its `default` arm (every value `$allowed` can ever contain
 * has its own named arm); `Aggregating::applyHaving()`'s two `default` arms
 * (the surrounding valid arms already cover every operator
 * `Filtering::parseHavingExpression()` can produce); `Including`'s
 * aggregation-function `default` arm (`$type` is constrained to
 * `Aggregating::FUNCTIONS` upstream); `Searching::apply()`'s validation-error
 * throw (`Searching::validate()` is a hardcoded no-op); `Searching::applyClause()`'s
 * empty-fields guard (`enrich()` already drops any clause with empty fields
 * before `applyClauses()` ever sees it); `WhereOperator::handle()`'s `default`
 * arm (`$operator` is constrained to the fixed set `Filtering`'s value
 * grammar can ever produce).
 */
beforeEach(fn () => Preset::clear());
afterEach(fn () => Preset::clear());

describe('Preset registry', function () {
    test('has() reflects registration state')
        ->expect(function () {
            Preset::register('active', 'active:true');

            return [Preset::has('active'), Preset::has('missing')];
        })
        ->toBe([true, false]);

    test('registerMany() registers every preset in one call')
        ->expect(function () {
            Preset::registerMany([
                'active' => 'active:true',
                'inactive' => 'active:false',
            ]);

            return [Preset::has('active'), Preset::has('inactive')];
        })
        ->toBe([true, true]);

    test('all() returns every registered preset, plus the built-in defaults')
        ->expect(function () {
            Preset::register('active', 'active:true');

            return Preset::all();
        })
        ->toHaveKey('active', 'active:true')
        ->toHaveKey('count');

    test('get() returns null for an unregistered name')
        ->expect(fn () => Preset::get('missing'))
        ->toBeNull();

    test('expand() leaves an unknown @name untouched')
        ->expect(fn () => Preset::expand('@missing name:foo'))
        ->toBe('@missing name:foo');

    test('expand() recursively resolves a preset referencing another preset')
        ->expect(function () {
            Preset::register('base', 'active:true');
            Preset::register('wrapper', '@base');

            return Preset::expand('@wrapper');
        })
        ->toBe('active:true');

    test('registering a preset that creates a circular reference throws')
        ->expect(function () {
            Preset::register('a', '@b');
            Preset::register('b', '@a');
        })
        ->throws(InvalidArgumentException::class, 'Circular preset reference detected: b -> a -> b');
});

describe('Aggregating edge cases', function () {
    test('`:@join` on a relation not in aggregateBy() is rejected')
        ->expect(fn () => Post::curio()->aggregate('comments:@count:@join')->toRawSql())
        ->throws(ValidationException::class, "The relation 'comments' is not allowed on query aggregate.");

    test('`:@default(1.5)` wraps the column in COALESCE with a float literal')
        ->expect(fn () => Author::curio()->aggregate('ranking:@group:@default(1.5)')->toRawSql())
        ->toBe('select COALESCE("author"."ranking", 1.5) as "ranking" from "author" group by "author"."ranking"');

    test('`:@having` with a boolean value casts it to an integer bind')
        ->expect(fn () => Author::curio()->aggregate('posts:@count:@join:@having(=true)')->toRawSql())
        ->toBe('select COUNT("post"."id") as "posts_count" from "author" left join "post" on "post"."author_id" = "author"."id" having COUNT("post"."id") = 1');
});

describe('BuildsAggregateExpressions driver dispatch', function () {
    /**
     * `datePartExpression()` only inspects `$builder->getConnection()->getDriverName()`
     * and `getGrammar()`, neither of which opens a real connection (the PDO
     * resolver Laravel builds is lazy) - so a driver-specific `Connection`
     * built directly via `ConnectionFactory` is enough to exercise the
     * per-driver SQL dispatch without a live pgsql/mysql/mariadb server.
     */
    function builderFor(string $driver): EloquentBuilder
    {
        $connection = (new ConnectionFactory(app()))->make([
            'driver' => $driver,
            'database' => 'testing',
            'prefix' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $builder = new EloquentBuilder(new QueryBuilder($connection));
        $builder->setModel(new Author());

        return $builder;
    }

    test('uses EXTRACT() on the pgsql driver')
        ->expect(fn () => app(Aggregating::class)->datePartExpression(builderFor('pgsql'), 'year', 'created_at'))
        ->toBe('EXTRACT(YEAR FROM "author"."created_at")');

    test('uses EXTRACT() on the mysql driver')
        ->expect(fn () => app(Aggregating::class)->datePartExpression(builderFor('mysql'), 'year', 'created_at'))
        ->toBe('EXTRACT(YEAR FROM `author`.`created_at`)');

    test('uses EXTRACT() on the mariadb driver')
        ->expect(fn () => app(Aggregating::class)->datePartExpression(builderFor('mariadb'), 'year', 'created_at'))
        ->toBe('EXTRACT(YEAR FROM `author`.`created_at`)');

    test('throws for an unsupported driver')
        ->expect(fn () => app(Aggregating::class)->datePartExpression(builderFor('sqlsrv'), 'year', 'created_at'))
        ->throws(RuntimeException::class, "The date-part 'year' is not supported on the 'sqlsrv' driver.");
});

describe('JoinsAggregateRelations unsupported relation types', function () {
    /**
     * None of the workbench models declare a polymorphic, many-to-many, or
     * "through" relation, so these relation types are built ad hoc here -
     * `resolveAggregateJoinRelation()` throws before any query executes, so
     * the relation methods below never need to resolve against real rows.
     */
    function modelWithUnsupportedJoinRelations(): Model
    {
        return new class() extends Model {
            use YourCuriosity;

            protected $table = 'author';

            public function aggregateBy(): array
            {
                return ['taggable', 'tags', 'commentsThroughPosts'];
            }

            public function taggable(): MorphTo
            {
                return $this->morphTo();
            }

            public function tags(): BelongsToMany
            {
                return $this->belongsToMany(Post::class);
            }

            public function commentsThroughPosts(): HasManyThrough
            {
                return $this->hasManyThrough(Comment::class, Post::class);
            }
        };
    }

    test('a polymorphic relation is rejected')
        ->expect(function () {
            $model = modelWithUnsupportedJoinRelations();

            return $model::curio($model->newQuery())->aggregate('taggable:@count:@join')->toRawSql();
        })
        ->throws(ValidationException::class, "The relation 'taggable' is polymorphic and is not supported by ':@join'.");

    test('a many-to-many relation is rejected')
        ->expect(function () {
            $model = modelWithUnsupportedJoinRelations();

            return $model::curio($model->newQuery())->aggregate('tags:@count:@join')->toRawSql();
        })
        ->throws(ValidationException::class, "The relation 'tags' is many-to-many and is not supported by ':@join' (it would require an extra pivot-table join); use 'include=tags:@count' instead.");

    test('an unsupported "through" relation is rejected')
        ->expect(function () {
            $model = modelWithUnsupportedJoinRelations();

            return $model::curio($model->newQuery())->aggregate('commentsThroughPosts:@count:@join')->toRawSql();
        })
        ->throws(ValidationException::class, "The relation 'commentsThroughPosts' is not supported by ':@join' (only belongsTo/hasOne/hasMany relations are supported).");
});

/**
 * Coverage was measured against `src/` only (`phpunit.xml`'s `<source>`), so
 * the sample app under `workbench/` - which every test above actually runs
 * against - was never itself checked. Widening the scope
 * (`--coverage-filter=workbench`) showed `api/post` and `api/comment` are
 * never hit by any existing test: `PostQuery`/`CommentQuery`,
 * `Post`/`CommentPaginateController`, `Post`/`CommentPaginateRequest`, and
 * `Post`/`CommentResource` all sat at 0% end-to-end coverage, and
 * `Comment::author()`/`Comment::post()` were dead since nothing ever loaded
 * either relation. `UserFactory` was likewise unused - `api/user` was only
 * ever hit against an empty table.
 */
describe('workbench HTTP endpoints', function () {
    test('api/post supports filter= and a relation sort= field', function () {
        $author = Author::factory()->create(['name' => 'Ada Lovelace']);
        Post::factory()->create(['author_id' => $author->id, 'title' => 'Analytical Engine', 'status' => 'done']);
        Post::factory()->create(['author_id' => $author->id, 'title' => 'Other', 'status' => 'draft']);

        $response = $this->getJson('api/post?' . http_build_query([
            'filter' => 'status:done',
            'sort' => 'author.name',
            'include' => 'author',
        ]));

        $response->assertOk();

        $data = $response->json('data');

        expect($data)->toHaveCount(1);
        expect($data[0]['title'])->toBe('Analytical Engine');
        expect($data[0]['author']['name'])->toBe('Ada Lovelace');
    });

    test('api/post supports a relation aggregate via include=', function () {
        $post = Post::factory()->create();
        Comment::factory()->count(2)->create(['post_id' => $post->id]);

        $response = $this->getJson('api/post?' . http_build_query([
            'include' => 'comments:@count',
        ]));

        $response->assertOk();

        // CommentFactory's default `post_id` (a lazily-created Post via a
        // Closure) is evaluated even though it's overridden below, so this
        // also creates unrelated posts - match by id, not `data.0`.
        $row = collect($response->json('data'))->firstWhere('id', $post->id);

        expect($row['comments_count'])->toBe(2);
    });

    /**
     * `PostQuery::aggregateBy()` only allows `posts`/`posts.status` - fields
     * that describe a `posts` relation `Post` itself doesn't have (its real
     * relations are `author`/`comments`), so no `aggregate=` value can ever
     * pass validation here. This asserts the allow-list is actually enforced
     * (and exercises `PostQuery::aggregateBy()`, otherwise dead) rather than
     * asserting a working aggregate that the current config can't produce.
     */
    test('api/post enforces PostQuery::aggregateBy()', function () {
        $response = $this->getJson('api/post?' . http_build_query([
            'aggregate' => 'status:@group *:@count',
        ]));

        $response->assertStatus(422);
        expect($response->json('errors.aggregate.0'))->toBe("The field 'status' is not allowed on query aggregate.");
    });

    test('api/post resolves a replaceBy() alias for a sort field', function () {
        $first = Author::factory()->create(['name' => 'A First']);
        $last = Author::factory()->create(['name' => 'Z Last']);

        Post::factory()->create(['author_id' => $last->id, 'title' => 'Two']);
        Post::factory()->create(['author_id' => $first->id, 'title' => 'One']);

        $response = $this->getJson('api/post?' . http_build_query(['sort' => 'author']));

        $response->assertOk();
        expect($response->json('data.0.author_id'))->toBe($first->id);
    });

    test('api/post defaults to sorting by title when sort= is omitted', function () {
        Post::factory()->create(['title' => 'Zeta']);
        Post::factory()->create(['title' => 'Alpha']);

        $response = $this->getJson('api/post');

        $response->assertOk();
        expect($response->json('data.0.title'))->toBe('Alpha');
    });

    test('api/comment applies defaultFilter(), supports overriding it, and includes author/post', function () {
        $author = Author::factory()->create();
        $post = Post::factory()->create(['author_id' => $author->id]);

        Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id, 'excluded' => false, 'title' => 'Visible']);
        Comment::factory()->create(['author_id' => $author->id, 'post_id' => $post->id, 'excluded' => true, 'title' => 'Hidden']);

        $default = $this->getJson('api/comment');

        $default->assertOk();
        expect($default->json('data'))->toHaveCount(1);
        expect($default->json('data.0.title'))->toBe('Visible');

        $overridden = $this->getJson('api/comment?' . http_build_query([
            'filter' => 'excluded:true',
            'include' => 'author post',
        ]));

        $overridden->assertOk();
        $overriddenData = $overridden->json('data');

        expect($overriddenData)->toHaveCount(1);
        expect($overriddenData[0]['title'])->toBe('Hidden');
        expect($overriddenData[0]['author']['id'])->toBe($author->id);
        expect($overriddenData[0]['post']['id'])->toBe($post->id);
    });

    test('api/user paginates seeded users', function () {
        User::factory()->count(2)->create();

        $this->getJson('api/user')->assertOk()->assertJsonCount(2, 'data');
    });

    test('api/author includes the inverse Author::comments() relation', function () {
        $author = Author::factory()->create();
        Comment::factory()->create(['author_id' => $author->id]);

        $response = $this->getJson('api/author?' . http_build_query(['include' => 'comments']));

        $response->assertOk();

        // Comment/Post factories default `author_id`/`post_id` to a lazily
        // created related row via a Closure, which Laravel's factory always
        // evaluates during expansion even when the key is then overridden
        // below - so this can create extra, unrelated authors/posts. Match
        // by id rather than assuming `data.0` is the one just created.
        $row = collect($response->json('data'))->firstWhere('id', $author->id);

        expect($row['comments'])->toHaveCount(1);
    });

    test('UserFactory::unverified() clears email_verified_at', function () {
        $user = User::factory()->unverified()->create();

        expect($user->fresh()->email_verified_at)->toBeNull();
    });
});
