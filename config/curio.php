<?php

declare(strict_types=1);

return [

    'query' => [
        'aggregate' => 'aggregate',
        'cast' => 'cast',
        'filter' => 'filter',
        'include' => 'include',
        'page' => 'page',
        'size' => 'size',
        'select' => 'select',
        'sort' => 'sort',
    ],

    'include' => [
        /**
         * Maximum relation nesting depth accepted in `include=`. Relation
         * chains can legitimately cycle (`posts.author.posts`, since each
         * segment is in its own query's `includeBy()`), and every extra level
         * multiplies the serialized payload by the number of related rows
         * per parent - so a handful of extra characters can turn a page into
         * gigabytes of JSON. Override per endpoint with
         * `PaginateQuery::maxIncludeDepth()`.
         */
        'max_depth' => 3,
    ],

    'paginate' => [
        'default_page_number' => 1,
        'default_page_size' => 25,
        'default_max_page_size' => 250,
    ],

    'search' => [
        /**
         * Options: like, trgm (Postgres), unaccent (Postgres)
         */
        'mode' => 'like',
        'similarity_threshold' => 0.3,
    ],

];
