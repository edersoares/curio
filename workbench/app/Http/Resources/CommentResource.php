<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author_id' => $this->author_id,
            'post_id' => $this->post_id,
            'title' => $this->title,
            'content' => $this->content,
            'excluded' => $this->excluded,
            'author' => AuthorResource::make($this->whenLoaded('author')),
            'post' => PostResource::make($this->whenLoaded('post')),
        ];
    }
}
