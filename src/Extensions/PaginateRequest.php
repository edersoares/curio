<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Extensions;

use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @mixin FormRequest
 * @phpstan-require-extends FormRequest
 */
// @phpstan-ignore-next-line trait.unused (only consumed by workbench/, outside phpstan's configured `paths` (src/, config/) - correctness verified via composer test instead)
trait PaginateRequest
{
    public function rules(): array
    {
        $query = $this->resolvePaginateQuery();

        return [
            ...$this->additionalRules(),
            ...$this->allowedFilter($query),
            ...$this->allowedInclude($query),
            ...$this->allowedSort($query),
            ...$this->allowedAggregate($query),
            ...$this->allowedCast($query),

            config('curio.query.page') => ['integer', 'min:1'],
            config('curio.query.size') => ['integer', 'min:1', 'max:' . $query->defaultMaxPageSize()],
        ];
    }

    public function additionalRules(): array
    {
        return [];
    }

    public function allowedFilter(PaginateQuery $query): array
    {
        if (empty($query->filterBy()) && empty($query->searchBy())) {
            return [];
        }

        return [
            config('curio.query.filter') => ['string'],
        ];
    }

    public function allowedInclude(PaginateQuery $query): array
    {
        if (empty($query->includeBy())) {
            return [];
        }

        return [
            config('curio.query.include') => ['string'],
        ];
    }

    public function allowedSort(PaginateQuery $query): array
    {
        if (empty($query->allowedSortColumns())) {
            return [];
        }

        return [
            config('curio.query.sort') => ['string'],
        ];
    }

    public function allowedAggregate(PaginateQuery $query): array
    {
        if (empty($query->aggregateBy())) {
            return [];
        }

        return [
            config('curio.query.aggregate') => ['string', 'prohibits:' . config('curio.query.select')],
        ];
    }

    public function allowedCast(PaginateQuery $query): array
    {
        if (empty($query->castBy())) {
            return [];
        }

        return [
            config('curio.query.cast') => ['string'],
        ];
    }

    /**
     * The whole request input is handed over as the variable context, so a
     * `$name` reference in any `default*()` resolves against what the client
     * sent - see `Language\VariableResolver` for what that does and doesn't
     * guarantee.
     */
    public function paginate(Builder $builder): LengthAwarePaginator
    {
        $query = $this->resolvePaginateQuery();

        /**
         * @var Paginator $paginator
         */
        $paginator = $this->container->get(Paginator::class);

        return $paginator->execute($builder, collect($this->all()), $query);
    }

    /**
     * Resolves `getPaginateQuery()`'s result to a `PaginateQuery` instance -
     * a class-string resolves through the container as before; a
     * pre-configured instance (e.g. `(new PostQuery())->allowSort(...)`,
     * built per request from `getPaginateQuery()` itself) is used as-is.
     */
    private function resolvePaginateQuery(): PaginateQuery
    {
        $query = $this->getPaginateQuery();

        if (is_string($query)) {
            $query = $this->container->get($query);
        }

        return $query;
    }

    abstract public function getPaginateQuery(): string|PaginateQuery;
}
