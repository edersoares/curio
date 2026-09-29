<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Requests;

use Dex\Laravel\Curio\Extensions\PaginateRequest;
use Dex\Laravel\Curio\Workbench\App\Http\Queries\AuthorQuery;
use Illuminate\Foundation\Http\FormRequest;

class AuthorPaginateRequest extends FormRequest
{
    use PaginateRequest;

    public function getPaginateQuery(): string
    {
        return AuthorQuery::class;
    }
}
