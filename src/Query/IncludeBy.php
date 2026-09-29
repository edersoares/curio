<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

trait IncludeBy
{
    /**
     * @return array<string, class-string<PaginateQuery>>
     */
    public function includeBy(): array
    {
        return [];
    }

    public function defaultInclude(): string
    {
        return '';
    }

    /**
     * How many `.`-separated segments an `include=` item may carry, backed by
     * `config('curio.include.max_depth')` - `includeBy()` alone can't bound
     * this, since a chain is allowed to revisit a query class it already
     * passed through (`posts.author.posts...`), and each level multiplies the
     * response payload by the related-row count.
     */
    public function maxIncludeDepth(): int
    {
        $value = config('curio.include.max_depth');

        assert(is_int($value));

        return $value;
    }
}
