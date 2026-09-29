<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class PostResourceCollection extends ResourceCollection
{
    public $collects = PostResource::class;
}
