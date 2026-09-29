<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Tests\Postgres;

/**
 * Points a test at a real PostgreSQL server instead of the in-memory SQLite
 * the rest of the suite runs on.
 *
 * Most of the suite asserts the SQL Curio *builds* (`toRawSql()`), which
 * never reaches a database - fine for anything portable, and no proof at all
 * for what only PostgreSQL can run: `similarity()`, `unaccent()`, `EXTRACT()`,
 * `jsonb` containment. A misplaced argument or a missing cast there would
 * pass every string assertion. The tests using this trait execute the query.
 *
 * The server comes from `CURIO_POSTGRES_URL`
 * (`pgsql://user:password@host:port/database`), and a test is skipped - not
 * failed - when it is not set, so the suite can be asked for on a machine
 * that has no PostgreSQL without turning red.
 */
trait UsesPostgres
{
    public function getEnvironmentSetUp($app): void
    {
        $url = env('CURIO_POSTGRES_URL');

        if (!is_string($url) || $url === '') {
            $this->markTestSkipped('Set CURIO_POSTGRES_URL (pgsql://user:password@host:port/database) to run the PostgreSQL suite.');
        }

        config()->set('database.connections.postgres', [
            'driver' => 'pgsql',
            'url' => $url,
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ]);

        config()->set('database.default', 'postgres');
    }
}
