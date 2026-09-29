<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Resources;

use Dex\Laravel\Curio\Extensions\AggregateResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorResource extends JsonResource
{
    use AggregateResource;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'date_of_birth' => $this->date_of_birth,
            'ranking' => $this->ranking,
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            'latestPost' => PostResource::make($this->whenLoaded('latestPost')),
            ...$this->aggregates(),
        ];
    }
}
