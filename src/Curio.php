<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio;

use Dex\Laravel\Curio\Contracts\Curious;
use Dex\Laravel\Curio\Contracts\Searchable;
use Dex\Laravel\Curio\Events\ApplyPending;
use Dex\Laravel\Curio\Language\Filtering;
use Dex\Laravel\Curio\Language\Parser;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder as Eloquent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\ForwardsCalls;

/**
 * Fluent DSL access directly against an Eloquent builder - applies
 * the same `filter=`/`sort=`/`select=`/`aggregate=`/`include=`/`cast=` syntax
 * as the `PaginateQuery`/`PaginateRequest`/`Paginator` pipeline, and shares
 * its exact validation pipeline. Every allow-list -
 * `sortBy()`/`selectBy()`/`aggregateBy()`/`filterBy()`/`includeBy()`/
 * `searchBy()`/`castBy()` - is enforced the same way it would be through a
 * bound `PaginateQuery`; unbound calls source those from the model's own
 * `YourCuriosity` traits instead (adapted via `wrapModelAsQuery()`), which
 * now include `CastBy` directly (`castBy()`/`castMutator()`/`defaultCast()`),
 * so `cast=` validates uniformly on both paths - no exception left.
 *
 * Every DSL method below parses its argument immediately (`Parser::transform()`
 * already tags each resulting token with its own DSL type) and queues the
 * *parsed* tokens into one unified `$pending` array - nothing touches the
 * builder until a query actually needs to run, via any forwarded builder
 * call (`get()`, `paginate()`, `first()`, ...) handled by `__call()`, which
 * dispatches one `ApplyPending` event carrying the whole batch. Each DSL
 * type's own listener (`Listeners\FilterListener`, etc.) self-selects its
 * tokens from that batch and delegates to the matching `Contracts\Applier` -
 * see `applyPendings()`. `cast=` applies the same way the other 5 do - via
 * `Casting::apply()`, whose listener registers a `Builder::afterQuery()`
 * callback instead of an immediate SQL mutation, since `get()`, `first()`,
 * `find()`, `findMany()`, `sole()`, `paginate()`, and `cursor()` all funnel
 * through `Builder::get()` internally, which runs `afterQuery()` callbacks
 * against the hydrated collection before any of those methods narrow or
 * wrap it further.
 *
 * @mixin Eloquent<Model>
 *
 * @phpstan-import-type FilterClause from Filtering
 */
class Curio
{
    use ForwardsCalls;

    /** @var array<int, FilterClause> */
    private array $pending = [];

    /**
     * When bound (via `withQuery()`), every allow-list/default comes from
     * this `PaginateQuery` instead of the model's own `YourCuriosity` traits
     * (see `wrapModelAsQuery()`, used when this is left unbound). Used by
     * `Paginator::execute()` to reuse this same pipeline for the
     * HTTP/`PaginateRequest` path instead of orchestrating the `Language`
     * classes itself.
     */
    private ?PaginateQuery $query = null;

    /**
     * @param Eloquent<Model> $builder
     */
    public function __construct(
        protected Eloquent $builder,
        protected Parser $parser,
    ) {}

    /**
     * The query is cloned, not referenced: the pipeline itself mutates it -
     * `Listeners\AllowSortAggregates`/`AllowSortIncludes` register this
     * request's aggregate and include aliases as sortable via
     * `PaginateQuery::allowSort()` - and the caller's instance may well
     * outlive the request (`PaginateRequest` resolves it from the container,
     * where a `singleton()` binding, or any long-lived worker, would carry
     * those additions into the *next* request and accept a `sort=` key it
     * never asked for). A clone keeps every per-request addition inside this
     * `Curio` instance, while additions the caller made deliberately (e.g.
     * `(new PostQuery())->allowSort(...)` from `getPaginateQuery()`) are
     * copied along with it.
     */
    public function withQuery(PaginateQuery $query): static
    {
        $this->query = clone $query;

        return $this;
    }

    /**
     * @param array<int, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        $this->applyPendings();

        $result = $this->forwardCallTo($this->builder, $method, $parameters);

        // Fluent builder methods (where(), orderBy(), etc.) return the bare
        // builder itself - re-wrap it so DSL methods keep chaining regardless
        // of call order. Anything else (get()'s Collection, first()'s Model,
        // toRawSql()'s string, count()'s int, ...) passes through untouched.
        return $result === $this->builder ? $this : $result;
    }

    public function filter(string $filter): static
    {
        $this->queue($filter, 'filter');

        return $this;
    }

    public function sort(string $sort): static
    {
        $this->queue($sort, 'sort');

        return $this;
    }

    public function select(string $select): static
    {
        $this->queue($select, 'select');

        return $this;
    }

    public function aggregate(string $aggregate): static
    {
        $this->queue($aggregate, 'aggregate');

        return $this;
    }

    public function include(string $include): static
    {
        $this->queue($include, 'include');

        return $this;
    }

    public function cast(string $cast): static
    {
        $this->queue($cast, 'cast');

        return $this;
    }

    private function queue(string $token, string $type): void
    {
        $this->pending = [...$this->pending, ...$this->parser->transform($token, $type)];
    }

    /**
     * Dispatches one `ApplyPending` event carrying every token queued since
     * the last dispatch, then clears the queue. A no-op when nothing is
     * queued, so every `__call()`/`get()`/`paginate()` invocation can call
     * this unconditionally.
     */
    private function applyPendings(): void
    {
        if ($this->pending === []) {
            return;
        }

        event(new ApplyPending($this->pending, $this->builder, $this->query ?? $this->wrapModelAsQuery()));

        $this->pending = [];
    }

    /**
     * Adapts a `YourCuriosity`-using model to look like a `PaginateQuery`, so
     * the unbound path can share the exact same pipeline as the
     * `withQuery()`-bound path instead of duplicating it - every allow-list/
     * default delegates straight to the model's own `YourCuriosity` trait
     * methods, `castBy()`/`castMutator()`/`defaultCast()` included, since
     * `YourCuriosity` uses `CastBy` directly. `searchBy()`/`searchMode()`/
     * `searchSimilarityThreshold()` only delegate when the model itself
     * implements `Searchable`, else fall back to `PaginateQuery`'s own
     * empty/no-op defaults - `Searching::enrich()` already silently drops
     * the clause when `searchBy()` is empty, so this matches the effect of
     * the old `$model instanceof Searchable` guard.
     */
    private function wrapModelAsQuery(): PaginateQuery
    {
        /** @var Model&Curious $model */
        $model = $this->builder->getModel();

        return new class($model) extends PaginateQuery {
            /**
             * @param Model&Curious $model
             */
            public function __construct(protected Model $model) {}

            public function includeBy(): array
            {
                return $this->model->includeBy();
            }

            public function filterBy(): array
            {
                return $this->model->filterBy();
            }

            public function selectBy(): array
            {
                return $this->model->selectBy();
            }

            public function aggregateBy(): array
            {
                return $this->model->aggregateBy();
            }

            public function sortBy(): array
            {
                return $this->model->sortBy();
            }

            public function castBy(): array
            {
                return $this->model->castBy();
            }

            public function castMutator(): array
            {
                return $this->model->castMutator();
            }

            public function searchBy(): array
            {
                return $this->model instanceof Searchable ? $this->model->searchBy() : [];
            }

            public function searchMode(): string
            {
                return $this->model instanceof Searchable ? $this->model->searchMode() : parent::searchMode();
            }

            public function searchSimilarityThreshold(): float
            {
                return $this->model instanceof Searchable ? $this->model->searchSimilarityThreshold() : parent::searchSimilarityThreshold();
            }
        };
    }
}
