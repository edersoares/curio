<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Controllers;

use Dex\Laravel\Curio\Workbench\App\Http\Requests\UserPaginateRequest;
use Dex\Laravel\Curio\Workbench\App\Http\Resources\UserResourceCollection;
use Dex\Laravel\Curio\Workbench\App\Models\User;

class UserPaginateController
{
    public function __invoke(UserPaginateRequest $request): UserResourceCollection
    {
        return new UserResourceCollection(
            $request->paginate(User::query())
        );
    }
}
