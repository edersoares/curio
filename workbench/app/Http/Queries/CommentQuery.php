<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Queries;

use Dex\Laravel\Curio\Query\PaginateQuery;

class CommentQuery extends PaginateQuery
{
    public function includeBy(): array
    {
        return [
            'author' => AuthorQuery::class,
            'post' => PostQuery::class,
        ];
    }

    public function filterBy(): array
    {
        return [
            'title' => ['string'],
            'content' => ['string'],
            'excluded' => ['boolean'],
            'author_id' => ['integer'],
            'post_id' => ['integer'],
        ];
    }

    public function sortBy(): array
    {
        return [
            'id', 'title', 'excluded',
        ];
    }

    public function defaultSort(): string
    {
        return 'id';
    }

    public function defaultFilter(): string
    {
        return 'excluded:false';
    }
}
