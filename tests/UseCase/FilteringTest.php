<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Workbench\App\Models\Author;
use Dex\Laravel\Curio\Workbench\App\Models\Post;
use Dex\Laravel\Curio\Workbench\App\Models\User;
use Illuminate\Validation\ValidationException;

describe('filter', function () {
    test('`key:value` / equals')
        ->expect(fn () => Author::curio()->filter('email:edersoares@me.com'))
        ->toRunQuery('select * from "author" where "author"."email" = \'edersoares@me.com\'');

    test('`key:true` / is true')
        ->expect(fn () => Author::curio()->filter('active:true'))
        ->toRunQuery('select * from "author" where "author"."active" = 1');

    test('`key:false` / is false')
        ->expect(fn () => Author::curio()->filter('active:false'))
        ->toRunQuery('select * from "author" where "author"."active" = 0');

    test('`key:>value` / greater than')
        ->expect(fn () => Author::curio()->filter('ranking:>75'))
        ->toRunQuery('select * from "author" where "author"."ranking" > 75');

    test('`key:>=value` / greater than or equal')
        ->expect(fn () => Author::curio()->filter('ranking:>=90'))
        ->toRunQuery('select * from "author" where "author"."ranking" >= 90');

    test('`key:<=value` / less than or equal')
        ->expect(fn () => Author::curio()->filter('ranking:<=25'))
        ->toRunQuery('select * from "author" where "author"."ranking" <= 25');

    test('`key:<value` / less than')
        ->expect(fn () => Author::curio()->filter('ranking:<10'))
        ->toRunQuery('select * from "author" where "author"."ranking" < 10');

    test('`key:a,b` / in')
        ->expect(fn () => Author::curio()->filter('ranking:80,90,100'))
        ->toRunQuery('select * from "author" where "author"."ranking" in (80, 90, 100)');

    test('`key:a..b` / between')
        ->expect(fn () => Author::curio()->filter('ranking:80..100'))
        ->toRunQuery('select * from "author" where "author"."ranking" between 80 and 100');

    test('`key:a..` / range with open end')
        ->expect(fn () => Author::curio()->filter('ranking:80..'))
        ->toRunQuery('select * from "author" where "author"."ranking" >= 80');

    test('`key:..b` / range with open start')
        ->expect(fn () => Author::curio()->filter('ranking:..100'))
        ->toRunQuery('select * from "author" where "author"."ranking" <= 100');

    test('`key:null` / is null')
        ->expect(fn () => Author::curio()->filter('date_of_birth:null'))
        ->toRunQuery('select * from "author" where "author"."date_of_birth" is null');

    test('`key:filled` / is not null')
        ->expect(fn () => Author::curio()->filter('date_of_birth:filled'))
        ->toRunQuery('select * from "author" where "author"."date_of_birth" is not null');

    test('`key:*value*` / contains')
        ->expect(fn () => Author::curio()->filter('name:*lorem*'))
        ->toRunQuery('select * from "author" where "author"."name" like \'%lorem%\'');

    test('`key:value*` / starts with')
        ->expect(fn () => Author::curio()->filter('name:lorem*'))
        ->toRunQuery('select * from "author" where "author"."name" like \'lorem%\'');

    test('`key:*value` / ends with')
        ->expect(fn () => Author::curio()->filter('name:*lorem'))
        ->toRunQuery('select * from "author" where "author"."name" like \'%lorem\'');

    test('`search` / free text search')
        ->expect(fn () => Author::curio()->filter('lorem'))
        ->toRunQuery('select * from "author" where ("author"."name" like \'%lorem%\')');

    test('`"search"` / quoted text search')
        ->expect(fn () => Author::curio()->filter('"lorem ipsum"'))
        ->toRunQuery('select * from "author" where ("author"."name" like \'%lorem ipsum%\')');
});

describe('negative filter', function () {
    test('`-key:value` / different')
        ->expect(fn () => Author::curio()->filter('-email:edersoares@me.com'))
        ->toRunQuery('select * from "author" where "author"."email" != \'edersoares@me.com\'');

    test('`-key:true` / is not true')
        ->expect(fn () => Author::curio()->filter('-active:true'))
        ->toRunQuery('select * from "author" where "author"."active" != 1');

    test('`-key:false` / is not false')
        ->expect(fn () => Author::curio()->filter('-active:false'))
        ->toRunQuery('select * from "author" where "author"."active" != 0');

    test('`-key:>value` / is not greater than')
        ->expect(fn () => Author::curio()->filter('-ranking:>75'))
        ->toRunQuery('select * from "author" where "author"."ranking" <= 75');

    test('`-key:>=value` / is not greater than or equal')
        ->expect(fn () => Author::curio()->filter('-ranking:>=90'))
        ->toRunQuery('select * from "author" where "author"."ranking" < 90');

    test('`-key:<=value` / is not less than or equal')
        ->expect(fn () => Author::curio()->filter('-ranking:<=25'))
        ->toRunQuery('select * from "author" where "author"."ranking" > 25');

    test('`-key:<value` / is not less than')
        ->expect(fn () => Author::curio()->filter('-ranking:<10'))
        ->toRunQuery('select * from "author" where "author"."ranking" >= 10');

    test('`-key:a,b` / not in')
        ->expect(fn () => Author::curio()->filter('-ranking:10,20,30'))
        ->toRunQuery('select * from "author" where "author"."ranking" not in (10, 20, 30)');

    test('`-key:a..b` / between')
        ->expect(fn () => Author::curio()->filter('-ranking:80..100'))
        ->toRunQuery('select * from "author" where "author"."ranking" not between 80 and 100');

    test('`-key:a..` / is not in a range with open end')
        ->expect(fn () => Author::curio()->filter('-ranking:80..'))
        ->toRunQuery('select * from "author" where "author"."ranking" < 80');

    test('`-key:..b` / is not in a range with open start')
        ->expect(fn () => Author::curio()->filter('-ranking:..100'))
        ->toRunQuery('select * from "author" where "author"."ranking" > 100');

    test('`-key:null` / is not null')
        ->expect(fn () => Author::curio()->filter('-date_of_birth:null'))
        ->toRunQuery('select * from "author" where "author"."date_of_birth" is not null');

    test('`-key:filled` / is null')
        ->expect(fn () => Author::curio()->filter('-date_of_birth:filled'))
        ->toRunQuery('select * from "author" where "author"."date_of_birth" is null');

    test('`-key:*value*` / not contains')
        ->expect(fn () => Author::curio()->filter('-name:*lorem*'))
        ->toRunQuery('select * from "author" where "author"."name" not like \'%lorem%\'');

    test('`-key:value*` / is not starts with')
        ->expect(fn () => Author::curio()->filter('-name:lorem*'))
        ->toRunQuery('select * from "author" where "author"."name" not like \'lorem%\'');

    test('`-key:*value` / ends with')
        ->expect(fn () => Author::curio()->filter('-name:*lorem'))
        ->toRunQuery('select * from "author" where "author"."name" not like \'%lorem\'');

    test('`-search` / free text search')
        ->note('does not have negative form')
        ->expect(fn () => Author::curio()->filter('-lorem'))
        ->toRunQuery('select * from "author" where ("author"."name" like \'%-lorem%\')');

    test('`search` / quoted text search')
        ->expect(fn () => Author::curio()->filter('-"lorem ipsum"'))
        ->toRunQuery('select * from "author" where ("author"."name" like \'%-"lorem ipsum"%\')');
});

describe('relation filter', function () {
    test('`relation.key:value` / equals')
        ->expect(fn () => Author::curio()->filter('posts.title:curio'))
        ->toRunQuery('select * from "author" where exists (select * from "post" where "author"."id" = "post"."author_id" and "post"."title" = \'curio\')');
});

describe('relation counter filter', function () {
    test('`has:relation` / has relation')
        ->expect(fn () => Author::curio()->filter('has:posts'))
        ->toRunQuery('select * from "author" where exists (select * from "post" where "author"."id" = "post"."author_id")');

    test('`has:relation:@count(>number)` / has relation greater than')
        ->expect(fn () => Author::curio()->filter('has:posts:@count(>1)'))
        ->toRunQuery('select * from "author" where (select count(*) from "post" where "author"."id" = "post"."author_id") > 1');

    test('`has:relation:@count(>=number)` / has relation greater than or equal')
        ->expect(fn () => Author::curio()->filter('has:posts:@count(>=3)'))
        ->toRunQuery('select * from "author" where (select count(*) from "post" where "author"."id" = "post"."author_id") >= 3');
});

describe('negative relation filter', function () {
    test('`-has:relation` / not has relation')
        ->expect(fn () => Author::curio()->filter('-has:posts'))
        ->toRunQuery('select * from "author" where (select count(*) from "post" where "author"."id" = "post"."author_id") = 0');

    test('`-has:relation:@count(>number)` / not has relation greater than')
        ->expect(fn () => Author::curio()->filter('-has:posts:@count(>10)'))
        ->toRunQuery('select * from "author" where (select count(*) from "post" where "author"."id" = "post"."author_id") <= 10');

    test('`-has:relation:@count(>=number)` / not has relation greater than or equal')
        ->expect(fn () => Author::curio()->filter('-has:posts:@count(>=30)'))
        ->toRunQuery('select * from "author" where (select count(*) from "post" where "author"."id" = "post"."author_id") < 30');

    test('`-has:relation:@count(<number)` / not has relation less than')
        ->expect(fn () => Author::curio()->filter('-has:posts:@count(<10)'))
        ->toRunQuery('select * from "author" where (select count(*) from "post" where "author"."id" = "post"."author_id") >= 10');

    test('`-has:relation:@count(<=number)` / not has relation less than or equal')
        ->expect(fn () => Author::curio()->filter('-has:posts:@count(<=30)'))
        ->toRunQuery('select * from "author" where (select count(*) from "post" where "author"."id" = "post"."author_id") > 30');

    test('`-has:relation:@count(=number)` / not has relation less than or equal')
        ->expect(fn () => Author::curio()->filter('-has:posts:@count(=30)'))
        ->toRunQuery('select * from "author" where (select count(*) from "post" where "author"."id" = "post"."author_id") != 30');
});

describe('json filter', function () {
    test('`key->path:value` / json path')
        ->expect(fn () => Author::curio()->filter('additional->path:value'))
        ->toRunQuery('select * from "author" where json_extract("author"."additional", \'$."path"\') = \'value\'');

    test('`key:json` / json containment')
        ->expect(fn () => Author::curio()->filter('additional:{"path":"value"}'))
        ->toRunQuery('select * from "author" where exists (select 1 from json_each("author"."additional") where "json_each"."value" is \'value\')');
});

describe('negative json filter', function () {
    test('`-key->path:value` / json path')
        ->expect(fn () => Author::curio()->filter('-additional->path:value'))
        ->toRunQuery('select * from "author" where json_extract("author"."additional", \'$."path"\') != \'value\'');

    test('`-key:json` / json containment')
        ->expect(fn () => Author::curio()->filter('-additional:{"path":"value"}'))
        ->toRunQuery('select * from "author" where not exists (select 1 from json_each("author"."additional") where "json_each"."value" is \'value\')');
});

describe('missing configuration', function () {
    test('no `searchBy` configuration')
        ->expect(fn () => User::curio()->filter('lorem ipsum'))
        ->toRunQuery('select * from "users"');
});

describe('unexpected input', function () {
    // TODO: clause is not applied, maybe convert to exception
    test('range with no values')
        ->expect(fn () => Author::curio()->filter('ranking:..'))
        ->toRunQuery('select * from "author"');

    test('invalid operator in a relation filter')
        ->throws(ValidationException::class)
        ->expect(fn () => Author::curio()->filter('has:posts:@3')->get());
});

describe('validation errors', function () {
    test('a field outside the allowed list is rejected')
        ->expect(fn () => Author::curio()->filter('nickname:eder')->toRawSql())
        ->throws(ValidationException::class, "The field 'nickname' is not allowed on query filter.");

    test('a relation-dotted field is left unchecked, even for a model with no allowed filter fields')
        ->expect(fn () => Post::curio()->filter('author.name:eder')->toRawSql())
        ->not->toThrow(ValidationException::class);

    test('a `has:relation` filter is left unchecked, even for a model with no allowed filter fields')
        ->expect(fn () => Post::curio()->filter('has:comments')->toRawSql())
        ->not->toThrow(ValidationException::class);

    test('throws a error with not allowed operator')
        ->expect(fn () => Post::curio()->filter('has:posts:@count(!=0)')->toRawSql())
        ->throws(ValidationException::class, "The operator/count '!=0' is not allowed on the 'posts' relation.");

    test('a relation-dotted field naming an unknown relation is rejected')
        ->expect(fn () => Author::curio()->filter('bogus.field:value')->toRawSql())
        ->throws(ValidationException::class, "The field 'bogus.field' is not allowed on query filter.");

    /**
     * Both `has` clauses are applied to the builder, so both have to be
     * validated: while the validation payload was keyed by field name, the
     * trailing allowed relation overwrote the disallowed one and `has`'s
     * `in:` allow-list passed - letting `whereHas('truncate')` through, which
     * Eloquent resolves by calling the name on the model.
     */
    test('a disallowed `has:relation` is still rejected when a second, allowed one follows it')
        ->expect(fn () => Author::curio()->filter('has:truncate has:posts')->toRawSql())
        ->throws(ValidationException::class);

    test('an allowed field carrying a modifier chain instead of a value is rejected')
        ->expect(fn () => Author::curio()->filter('ranking:@nope')->toRawSql())
        ->throws(ValidationException::class, "The filter expression 'ranking' is not valid.");

    /**
     * Validating each clause separately means a field named three times
     * collects three sets of messages - so identical ones are reported once,
     * and the surviving list is re-indexed (`array_values`) so the JSON
     * response still carries `errors.ranking` as an array rather than an
     * object keyed `{"0": ..., "2": ...}`.
     */
    test('a repeated field reports each distinct message once, as a list')
        ->expect(function () {
            $model = new class() extends Author {
                public function filterBy(): array
                {
                    return ['ranking' => ['integer', 'lte:100']];
                }
            };

            try {
                $model::curio($model->newQuery())->filter('ranking:999 ranking:999 ranking:abc')->toRawSql();
            } catch (ValidationException $exception) {
                return $exception->errors();
            }
        })
        ->toBe([
            'ranking' => [
                'The ranking field must be less than or equal to 100.',
                'The ranking field must be an integer.',
            ],
        ]);

    test('a repeated field is validated on every occurrence, not just the last')
        ->expect(fn () => Author::curio()->filter('ranking:notAnInteger ranking:5')->toRawSql())
        ->throws(ValidationException::class);
});
