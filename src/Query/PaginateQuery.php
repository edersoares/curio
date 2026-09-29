<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

use Dex\Laravel\Curio\Contracts\Searchable;

abstract class PaginateQuery implements Searchable
{
    use AggregateBy;
    use CastBy;
    use FilterBy;
    use IncludeBy;
    use PaginateBy;
    use ReplaceBy;
    use SearchBy;
    use SelectBy;
    use SortBy;
}
