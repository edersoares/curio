<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Http\Resources;

use Dex\Laravel\Curio\Extensions\AggregateResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    use AggregateResource;

    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'author_id' => (int) $this->author_id,
            'title' => $this->title,
            'content' => $this->content,
            'published_at' => $this->published_at,
            'status' => $this->status,
            'category' => $this->category,
            'author' => AuthorResource::make($this->whenLoaded('author')),
            'comments' => CommentResourceCollection::make($this->whenLoaded('comments')),
            'comments_count' => $this->whenCounted('comments'),
            'comments_sum' => $this->whenAggregated('comments', 'author_id', 'sum'),
        ];
    }
}
