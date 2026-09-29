<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Providers;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Events\ApplyToken;
use Dex\Laravel\Curio\Listeners\AggregateListener;
use Dex\Laravel\Curio\Listeners\AllowSortAggregates;
use Dex\Laravel\Curio\Listeners\AllowSortIncludes;
use Dex\Laravel\Curio\Listeners\CastListener;
use Dex\Laravel\Curio\Listeners\FilterListener;
use Dex\Laravel\Curio\Listeners\IncludeListener;
use Dex\Laravel\Curio\Listeners\NegatedOperator;
use Dex\Laravel\Curio\Listeners\ReplaceKeys;
use Dex\Laravel\Curio\Listeners\SearchListener;
use Dex\Laravel\Curio\Listeners\SelectListener;
use Dex\Laravel\Curio\Listeners\SortListener;
use Dex\Laravel\Curio\Listeners\WhereOperator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CurioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/curio.php', 'curio');
    }

    public function boot(): void
    {
        Event::listen(ApplyToken::class, NegatedOperator::class);
        Event::listen(ApplyToken::class, WhereOperator::class);
        Event::listen(ApplyPending::class, ReplaceKeys::class);
        Event::listen(ApplyPending::class, FilterListener::class);
        Event::listen(ApplyPending::class, SelectListener::class);
        Event::listen(ApplyPending::class, AggregateListener::class);
        Event::listen(ApplyPending::class, AllowSortAggregates::class);
        Event::listen(ApplyPending::class, AllowSortIncludes::class);
        Event::listen(ApplyPending::class, IncludeListener::class);
        Event::listen(ApplyPending::class, SortListener::class);
        Event::listen(ApplyPending::class, SearchListener::class);
        Event::listen(ApplyPending::class, CastListener::class);
    }
}
