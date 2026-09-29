<?php

declare(strict_types=1);

use Dex\Laravel\Curio\Curio;
use Dex\Laravel\Curio\Tests\Postgres\UsesPostgres;
use Dex\Laravel\Curio\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

uses(TestCase::class)->in(__DIR__);

/**
 * `pg_trgm` and `unaccent` are what the `trgm`/`unaccent` search modes and
 * `sort=field:@unaccent` call into. Curio doesn't enable either - that is the
 * application's call - so the suite does it for itself.
 */
uses(UsesPostgres::class)
    ->beforeEach(function () {
        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create extension if not exists unaccent');
    })
    ->in('Postgres');

/**
 * Runs the expectation's closure while listening for every query dispatched
 * to the database, then asserts `$sql` matches at least one of them - a
 * leading `/` treats `$sql` as a raw regex (for full control), otherwise `?`
 * acts as a wildcard matching any single interpolated value (an id, a
 * timestamp, ...), the same way `Str::is()` treats `*` - anything else must
 * match `QueryExecuted::toRawSql()` (the same fully-interpolated format
 * `->toRawSql()` produces everywhere else in this suite) exactly. `*` isn't
 * used as the wildcard token here because almost every query in this suite
 * starts with the literal `select *`, which would collide with it.
 */
expect()->extend('toRunQuery', function (string $sql) {
    $queries = [];

    DB::listen(function (QueryExecuted $event) use (&$queries) {
        $queries[] = $event->toRawSql();
    });

    $value = $this->value;

    $callback = is_callable($value) ? $value() : $value;

    if ($callback instanceof Curio) {
        $callback->get();
    }

    $matches = collect($queries)->contains(fn (string $ran) => match (true) {
        str_starts_with($sql, '/') => (bool) preg_match($sql, $ran),
        default => (bool) preg_match('#^' . str_replace('\?', '.*', preg_quote($sql, '#')) . '\z#su', $ran),
    });

    $message = [
        'Expected query matching:',
        "    $sql",
        '  to run, but it didn\'t. Queries that ran:',
        empty($queries)
            ? '    (none)'
            : collect($queries)->map(fn (string $query) => "    $query")->implode("\n"),
    ];

    expect($matches)->toBeTrue(implode("\n", $message));

    return $this;
});

expect()->extend('toFirstBe', function ($a) {
    return $this->toBe([$a]);
});
