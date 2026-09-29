<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Queries;

use Dex\Laravel\Curio\Query\PaginateQuery;

class PostQuery extends PaginateQuery
{
    public function includeBy(): array
    {
        return [
            'author' => AuthorQuery::class,
            'comments' => CommentQuery::class,
        ];
    }

    public function filterBy(): array
    {
        return [
            'id' => ['integer'],
            'title' => ['string'],
            'content' => ['string'],
            'published_at' => ['date'],
            'author_id' => ['integer'],
            'status' => [],
        ];
    }

    public function sortBy(): array
    {
        return [
            'id', 'title', 'published_at', 'author.name',
        ];
    }

    public function aggregateBy(): array
    {
        return [
            'posts',
            'posts.status',
        ];
    }

    public function replaceBy(): array
    {
        return [
            'author' => 'author.name',
            'author_name' => 'author.name',
            'author-name' => 'author.name',
            'authorName' => 'author.name',
            'published-at' => 'published_at',
            'publishedAt' => 'published_at',
        ];
    }

    public function defaultSort(): string
    {
        return 'title';
    }
}
