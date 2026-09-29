<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Queries;

use Dex\Laravel\Curio\Query\PaginateQuery;

class AuthorQuery extends PaginateQuery
{
    public function includeBy(): array
    {
        return [
            'posts' => PostQuery::class,
            'comments' => CommentQuery::class,
        ];
    }

    public function filterBy(): array
    {
        return [
            'name' => ['string'],
            'title' => ['string'],
            'email' => ['string'],
            'date_of_birth' => ['date', 'after_or_equal:1990-01-01', 'before_or_equal:2025-01-01'],
            'ranking' => ['integer', 'gte:0', 'lte:100'],
            'created_at' => ['date'],
        ];
    }

    public function sortBy(): array
    {
        return [
            'id', 'name', 'email', 'date_of_birth', 'ranking', 'latestPost.title',
        ];
    }

    public function searchBy(): array
    {
        return ['name', 'email'];
    }

    public function aggregateBy(): array
    {
        return [
            'name',
            'ranking',
            'created_at',
            'posts',
            'posts.status',
        ];
    }

    public function castBy(): array
    {
        return ['ranking'];
    }

    public function castMutator(): array
    {
        return [
            'rankingTier' => [10 => 'bronze', 20 => 'silver'],
        ];
    }

    public function replaceBy(): array
    {
        return [
            'latestPost_title' => 'latestPost.title',
            'latest-post-title' => 'latestPost.title',
            'date-of-birth' => 'date_of_birth',
            'dateOfBirth' => 'date_of_birth',
        ];
    }

    public function defaultSort(): string
    {
        return 'name';
    }
}
