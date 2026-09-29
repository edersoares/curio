<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Controllers;

use Dex\Laravel\Curio\Workbench\App\Http\Requests\AuthorPaginateRequest;
use Dex\Laravel\Curio\Workbench\App\Http\Resources\AuthorResourceCollection;
use Dex\Laravel\Curio\Workbench\App\Models\Author;

class AuthorPaginateController
{
    public function __invoke(AuthorPaginateRequest $request): AuthorResourceCollection
    {
        return new AuthorResourceCollection(
            $request->paginate(Author::query())
        );
    }
}
