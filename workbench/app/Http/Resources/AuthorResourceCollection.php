<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class AuthorResourceCollection extends ResourceCollection
{
    public $collects = AuthorResource::class;
}
