<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Models;

use Dex\Laravel\Curio\Workbench\App\Http\Queries\AuthorQuery;
use Dex\Laravel\Curio\Workbench\App\Http\Queries\CommentQuery;
use Dex\Laravel\Curio\Workbench\Database\Factories\PostFactory;
use Dex\Laravel\Curio\YourCuriosity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;
    use YourCuriosity;

    protected $table = 'post';

    protected $fillable = ['author_id', 'title', 'content', 'published_at', 'status', 'category'];

    public function sortBy(): array
    {
        return [
            'author.name',
        ];
    }

    public function aggregateBy(): array
    {
        return [
            'status',
            'author',
        ];
    }

    public function includeBy(): array
    {
        return [
            'author' => AuthorQuery::class,
            'comments' => CommentQuery::class,
        ];
    }

    protected static function newFactory(): PostFactory
    {
        return new PostFactory();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
