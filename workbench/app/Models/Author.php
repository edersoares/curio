<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Models;

use Carbon\Carbon;
use Dex\Laravel\Curio\Contracts\Searchable;
use Dex\Laravel\Curio\Query\SearchBy;
use Dex\Laravel\Curio\Workbench\App\Http\Queries\PostQuery;
use Dex\Laravel\Curio\Workbench\Database\Factories\AuthorFactory;
use Dex\Laravel\Curio\YourCuriosity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Author extends Model implements Searchable
{
    use HasFactory;
    use SearchBy;
    use YourCuriosity;

    public function sortBy(): array
    {
        return [
            'name',
            'email',
            'ranking',
            'latestPost.title',
        ];
    }

    public function selectBy(): array
    {
        return [
            'id',
            'name',
            'ranking',
            'created_at',
            'posts.title',
        ];
    }

    public function filterBy(): array
    {
        return [
            'name' => ['string'],
            'email' => ['string'],
            'active' => ['boolean'],
            'ranking' => ['integer'],
            'date_of_birth' => ['date'],
            'additional' => [],
            'additional->path' => [], // TODO: change approach
        ];
    }

    public function aggregateBy(): array
    {
        return [
            'id',
            'name',
            'ranking',
            'created_at',
            'posts',
            'comments',
        ];
    }

    public function searchBy(): array
    {
        return [
            'name',
        ];
    }

    public function includeBy(): array
    {
        return [
            'posts' => PostQuery::class,
        ];
    }

    protected $table = 'author';

    protected $fillable = [
        'name',
        'email',
        'active',
        'date_of_birth',
        'ranking',
    ];

    protected static function newFactory(): AuthorFactory
    {
        return new AuthorFactory();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'author_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    public function latestPost(): HasOne
    {
        return $this->hasOne(Post::class, 'author_id')->latest();
    }

    public function castBy(): array
    {
        return ['ranking', 'date_of_birth'];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function castMutator(): array
    {
        return [
            'rankingTier' => [10 => 'bronze', 20 => 'silver'],
            'yearOfBirth' => fn ($value) => (int) Carbon::parse($value)->format('Y'),
        ];
    }
}
