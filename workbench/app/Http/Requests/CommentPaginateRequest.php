<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Requests;

use Dex\Laravel\Curio\Extensions\PaginateRequest;
use Dex\Laravel\Curio\Workbench\App\Http\Queries\CommentQuery;
use Illuminate\Foundation\Http\FormRequest;

class CommentPaginateRequest extends FormRequest
{
    use PaginateRequest;

    public function getPaginateQuery(): string
    {
        return CommentQuery::class;
    }
}
