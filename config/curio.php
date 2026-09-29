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

    'filter' => [
        /**
         * Maximum number of clauses accepted in one `filter=` string, counted
         * after presets are expanded. Every clause is allow-listed, but each
         * one is a `WHERE` of its own - and a relation clause
         * (`posts.title:x`) a whole `EXISTS` subquery - so a request is cheap
         * to write and arbitrarily expensive to run. Override per endpoint
         * with `PaginateQuery::maxFilterClauses()`.
         */
        'max_clauses' => 50,

        /**
         * Maximum number of relations a filter key may go through
         * (`posts.author.name` goes through 2). Relation chains can cycle
         * (`posts.author.posts...`), and every level nests another `EXISTS`.
         * Override per endpoint with `PaginateQuery::maxFilterDepth()`.
         */
        'max_depth' => 3,
    ],

    'sort' => [
        /**
         * Maximum number of fields accepted in `sort=`. Override per
         * endpoint with `PaginateQuery::maxSortFields()`.
         */
        'max_fields' => 5,
    ],

    'aggregate' => [
        /**
         * Maximum number of items accepted in `aggregate=`. Override per
         * endpoint with `PaginateQuery::maxAggregateItems()`.
         */
        'max_items' => 10,
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

        /**
         * Maximum number of relations accepted in `include=`. Override per
         * endpoint with `PaginateQuery::maxIncludeRelations()`.
         */
        'max_relations' => 10,
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
