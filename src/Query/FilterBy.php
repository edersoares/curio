<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

trait FilterBy
{
    /**
     * @return array<string, string|array<int, string>>
     */
    public function filterBy(): array
    {
        return [];
    }

    public function defaultFilter(): string
    {
        return '';
    }

    /**
     * How many clauses one `filter=` string may carry, counted after presets
     * are expanded - backed by `config('curio.filter.max_clauses')`.
     * `filterBy()` bounds which fields can be filtered, not how many times:
     * the same allow-listed clause can be repeated until the URL runs out.
     */
    public function maxFilterClauses(): int
    {
        $value = config('curio.filter.max_clauses');

        assert(is_int($value));

        return $value;
    }

    /**
     * How many relations a filter key may go through (`posts.author.name`
     * goes through 2), backed by `config('curio.filter.max_depth')` - the
     * `filter=` counterpart of `maxIncludeDepth()`, for the same reason: a
     * chain may revisit a query class it already passed through.
     */
    public function maxFilterDepth(): int
    {
        $value = config('curio.filter.max_depth');

        assert(is_int($value));

        return $value;
    }
}
