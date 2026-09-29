<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Workbench\App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Dex\Laravel\Curio\Contracts\Searchable;
use Dex\Laravel\Curio\Query\SearchBy;
use Dex\Laravel\Curio\Workbench\Database\Factories\UserFactory;
use Dex\Laravel\Curio\YourCuriosity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements Searchable
{
    use HasFactory;
    use Notifiable;
    use SearchBy;
    use YourCuriosity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected static function newFactory(): UserFactory
    {
        return new UserFactory();
    }
}
