<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio;

use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Events\ApplyToken;
use Dex\Laravel\Curio\Listeners\AggregateListener;
use Dex\Laravel\Curio\Listeners\AllowSortAggregates;
use Dex\Laravel\Curio\Listeners\AllowSortIncludes;
use Dex\Laravel\Curio\Listeners\CastListener;
use Dex\Laravel\Curio\Listeners\EnforceLimits;
use Dex\Laravel\Curio\Listeners\FilterListener;
use Dex\Laravel\Curio\Listeners\IncludeListener;
use Dex\Laravel\Curio\Listeners\NegatedOperator;
use Dex\Laravel\Curio\Listeners\ReplaceKeys;
use Dex\Laravel\Curio\Listeners\SearchListener;
use Dex\Laravel\Curio\Listeners\SelectListener;
use Dex\Laravel\Curio\Listeners\SortListener;
use Dex\Laravel\Curio\Listeners\WhereOperator;

/**
 * Runs every step that validates and applies a batch of tokens - directly,
 * in a fixed order, never through the event dispatcher.
 *
 * These steps used to be listeners on `ApplyPending`/`ApplyToken`, which
 * made the dispatcher part of the enforcement: whenever an event didn't
 * reach them the query simply ran without its `WHERE` clauses, its
 * allow-list validation and its `defaultFilter()`, and nothing failed.
 * `Event::fake()` in a consumer's test suite was enough, and so was any
 * earlier listener returning `false`, which halts propagation. A filter that
 * is silently not applied returns more rows than the caller is entitled to,
 * so it can't depend on something the application is free to swap out.
 *
 * Both events are still dispatched - first, as a hook. A listener can
 * observe a batch or rewrite it (`$event->tokens`, `$event->token`) before
 * anything is applied, but whether it runs, and what it returns, no longer
 * decides whether the steps below do.
 */
class Pipeline
{
    /**
     * Order matters: `EnforceLimits` rejects an oversized batch before any
     * step spends anything on it, `ReplaceKeys` resolves `replaceBy()`
     * aliases before any step reads a key, and `AllowSortAggregates`/`AllowSortIncludes`/
     * `IncludeListener` register this request's aggregate and include
     * aliases as sortable before `SortListener` validates against them.
     *
     * @var list<class-string>
     */
    protected const array PENDING = [
        EnforceLimits::class,
        ReplaceKeys::class,
        FilterListener::class,
        SelectListener::class,
        AggregateListener::class,
        AllowSortAggregates::class,
        AllowSortIncludes::class,
        IncludeListener::class,
        SortListener::class,
        SearchListener::class,
        CastListener::class,
    ];

    /**
     * `NegatedOperator` flips a negated comparison (`>` to `<=`, ...) before
     * `WhereOperator` turns the token into a `where*()` call.
     *
     * @var list<class-string>
     */
    protected const array TOKEN = [
        NegatedOperator::class,
        WhereOperator::class,
    ];

    public function pending(ApplyPending $event): void
    {
        event($event);

        $this->run(static::PENDING, $event);
    }

    public function token(ApplyToken $event): void
    {
        event($event);

        $this->run(static::TOKEN, $event);
    }

    /**
     * @param list<class-string> $steps
     */
    private function run(array $steps, ApplyPending|ApplyToken $event): void
    {
        foreach ($steps as $step) {
            $handler = app($step);

            assert(is_object($handler) && method_exists($handler, 'handle'));

            $handler->handle($event);
        }
    }
}
