<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

trait PaginateBy
{
    public function defaultPageNumber(): int
    {
        $value = config('curio.paginate.default_page_number');

        assert(is_int($value));

        return $value;
    }

    public function defaultPageSize(): int
    {
        $value = config('curio.paginate.default_page_size');

        assert(is_int($value));

        return $value;
    }

    public function defaultMaxPageSize(): int
    {
        $value = config('curio.paginate.default_max_page_size');

        assert(is_int($value));

        return $value;
    }
}
