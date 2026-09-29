<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Controllers;

use Dex\Laravel\Curio\Workbench\App\Http\Requests\CommentPaginateRequest;
use Dex\Laravel\Curio\Workbench\App\Http\Resources\CommentResourceCollection;
use Dex\Laravel\Curio\Workbench\App\Models\Comment;

class CommentPaginateController
{
    public function __invoke(CommentPaginateRequest $request): CommentResourceCollection
    {
        return new CommentResourceCollection(
            $request->paginate(Comment::query())
        );
    }
}
