<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Curio;
use Dex\Laravel\Curio\Language\Parser;
use Dex\Laravel\Curio\Language\Preset;
use Dex\Laravel\Curio\Workbench\App\Http\Queries\AuthorQuery;
use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\YourCuriosity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * An allow-list bounds which fields a request may name, not how many times
 * it names them: every clause below is valid on its own. What is being
 * limited is how much one request can ask the database to do.
 */
function repeated(string $item, int $times): string
{
    return implode(' ', array_fill(0, $times, $item));
}

function errorsOf(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

afterEach(fn () => Preset::clear());

describe('filter clauses', function () {
    test('as many clauses as the limit are accepted')
        ->expect(fn () => errorsOf(fn () => Author::curio()->filter(repeated('ranking:1', 50))->toRawSql()))
        ->toBe([]);

    test('one clause over the limit is rejected')
        ->expect(fn () => errorsOf(fn () => Author::curio()->filter(repeated('ranking:1', 51))->toRawSql()))
        ->toBe(['filter' => ['The filter may not have more than 50 clauses.']]);

    test('clauses are counted after a preset is expanded', function () {
        Preset::register('many', repeated('ranking:1', 51));

        expect(errorsOf(fn () => Author::curio()->filter('@many')->toRawSql()))
            ->toBe(['filter' => ['The filter may not have more than 50 clauses.']]);
    });

    test('free text is a single clause, however many words it has')
        ->expect(fn () => errorsOf(fn () => Author::curio()->filter(repeated('word', 60) . ' ' . repeated('ranking:1', 49))->toRawSql()))
        ->toBe([]);

    test('a filter scoped to an include has a budget of its own')
        ->expect(fn () => errorsOf(fn () => Author::curio()->filter(repeated('ranking:1', 50))->include('posts:@filter(' . repeated('id:1', 50) . ')')->get()))
        ->toBe([]);

    test('a filter scoped to an include is limited too')
        ->expect(fn () => errorsOf(fn () => Author::curio()->include('posts:@filter(' . repeated('id:1', 51) . ')')->get()))
        ->toBe(['filter' => ['The filter may not have more than 50 clauses.']]);

    test('a request that used to exhaust the database is rejected up front', function () {
        $response = $this->getJson('api/author?' . http_build_query(['filter' => repeated('ranking:1', 1000)]));

        $response->assertStatus(422);

        expect($response->json('errors'))->toBe(['filter' => ['The filter may not have more than 50 clauses.']]);
    });
});

describe('filter depth', function () {
    test('a key going through as many relations as the limit is accepted')
        ->expect(fn () => errorsOf(fn () => Author::curio()->filter('posts.author.posts.title:draft')->toRawSql()))
        ->toBe([]);

    test('a key going through one relation over the limit is rejected')
        ->expect(fn () => errorsOf(fn () => Author::curio()->filter('posts.author.posts.author.name:ada')->toRawSql()))
        ->toBe(['filter' => ["The field 'posts.author.posts.author.name' is nested deeper than the maximum filter depth of 3."]]);

    test('a cyclic chain is rejected on the HTTP path', function () {
        $key = implode('.', array_fill(0, 12, 'posts.author')) . '.name';

        $response = $this->getJson('api/author?' . http_build_query(['filter' => "$key:ada"]));

        $response->assertStatus(422);

        expect($response->json('errors.filter.0'))->toBe("The field '$key' is nested deeper than the maximum filter depth of 3.");
    });
});

describe('sort fields', function () {
    test('as many fields as the limit are accepted')
        ->expect(fn () => errorsOf(fn () => Author::curio()->sort(repeated('name', 5))->toRawSql()))
        ->toBe([]);

    test('one field over the limit is rejected')
        ->expect(fn () => errorsOf(fn () => Author::curio()->sort(repeated('name', 6))->toRawSql()))
        ->toBe(['sort' => ['The sort may not have more than 5 fields.']]);
});

describe('aggregate items', function () {
    test('as many items as the limit are accepted')
        ->expect(fn () => errorsOf(fn () => Author::curio()->aggregate(repeated('ranking:@group', 10))->toRawSql()))
        ->toBe([]);

    test('one item over the limit is rejected')
        ->expect(fn () => errorsOf(fn () => Author::curio()->aggregate(repeated('ranking:@group', 11))->toRawSql()))
        ->toBe(['aggregate' => ['The aggregate may not have more than 10 items.']]);
});

describe('include relations', function () {
    test('as many relations as the limit are accepted')
        ->expect(fn () => errorsOf(fn () => Author::curio()->include(repeated('posts', 10))->toRawSql()))
        ->toBe([]);

    test('one relation over the limit is rejected')
        ->expect(fn () => errorsOf(fn () => Author::curio()->include(repeated('posts', 11))->toRawSql()))
        ->toBe(['include' => ['The include may not have more than 10 relations.']]);
});

describe('where a limit comes from', function () {
    test('every limit exceeded by a request is reported at once')
        ->expect(fn () => errorsOf(fn () => Author::curio()->filter(repeated('ranking:1', 51))->sort(repeated('name', 6))->toRawSql()))
        ->toBe([
            'filter' => ['The filter may not have more than 50 clauses.'],
            'sort' => ['The sort may not have more than 5 fields.'],
        ]);

    test('the config sets the default for every query', function () {
        config()->set('curio.filter.max_clauses', 1);
        config()->set('curio.filter.max_depth', 1);
        config()->set('curio.sort.max_fields', 1);
        config()->set('curio.aggregate.max_items', 1);
        config()->set('curio.include.max_relations', 1);

        expect(errorsOf(fn () => Author::curio()->filter('ranking:1 ranking:2')->toRawSql()))
            ->toBe(['filter' => ['The filter may not have more than 1 clauses.']])
            ->and(errorsOf(fn () => Author::curio()->filter('posts.author.name:ada')->toRawSql()))
            ->toBe(['filter' => ["The field 'posts.author.name' is nested deeper than the maximum filter depth of 1."]])
            ->and(errorsOf(fn () => Author::curio()->sort('name email')->toRawSql()))
            ->toBe(['sort' => ['The sort may not have more than 1 fields.']])
            ->and(errorsOf(fn () => Author::curio()->aggregate('ranking:@group name:@group')->toRawSql()))
            ->toBe(['aggregate' => ['The aggregate may not have more than 1 items.']])
            ->and(errorsOf(fn () => Author::curio()->include('posts posts')->toRawSql()))
            ->toBe(['include' => ['The include may not have more than 1 relations.']]);
    });

    test('a `PaginateQuery` overrides the config for its own endpoint', function () {
        $query = new class() extends AuthorQuery {
            public function maxFilterClauses(): int
            {
                return 2;
            }

            public function maxSortFields(): int
            {
                return 100;
            }
        };

        $curio = fn () => (new Curio(Author::query(), new Parser()))->withQuery($query);

        expect(errorsOf(fn () => $curio()->filter('ranking:1 ranking:2 ranking:3')->toRawSql()))
            ->toBe(['filter' => ['The filter may not have more than 2 clauses.']])
            ->and(errorsOf(fn () => $curio()->sort(repeated('name', 6))->toRawSql()))
            ->toBe([]);
    });

    test('a model overrides the config on the fluent path', function () {
        $model = new class() extends Model {
            use YourCuriosity;

            protected $table = 'author';

            public function sortBy(): array
            {
                return ['name'];
            }

            public function maxSortFields(): int
            {
                return 1;
            }
        };

        expect(errorsOf(fn () => $model::curio($model->newQuery())->sort('name name')->toRawSql()))
            ->toBe(['sort' => ['The sort may not have more than 1 fields.']]);
    });
});
