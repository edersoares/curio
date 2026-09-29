<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Controllers;

use Dex\Laravel\Curio\Workbench\App\Http\Requests\PostPaginateRequest;
use Dex\Laravel\Curio\Workbench\App\Http\Resources\PostResourceCollection;
use Dex\Laravel\Curio\Workbench\App\Models\Post;

class PostPaginateController
{
    public function __invoke(PostPaginateRequest $request): PostResourceCollection
    {
        return new PostResourceCollection(
            $request->paginate(Post::query())
        );
    }
}
