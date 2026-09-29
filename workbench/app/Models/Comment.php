<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Models;

use Dex\Laravel\Curio\Workbench\Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    use HasFactory;

    protected $table = 'comment';

    protected $fillable = [
        'author_id',
        'post_id',
        'title',
        'content',
        'excluded',
    ];

    protected static function newFactory(): CommentFactory
    {
        return new CommentFactory();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
