<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Query;

trait SortBy
{
    /** @var array<int, string> */
    private array $dynamicSortColumns = [];

    /**
     * @return array<string>
     */
    public function sortBy(): array
    {
        return [];
    }

    public function defaultSort(): string
    {
        return '';
    }

    /**
     * Fluently add sortable columns without overriding `sortBy()` wholesale
     * - e.g. from a `FormRequest::getPaginateQuery()` override, per request.
     * Per-instance state, and the pipeline itself writes to it
     * (`Listeners\AllowSortAggregates`/`AllowSortIncludes` register this
     * request's aliases as sortable) - which is why `Curio::withQuery()`
     * clones the query it is handed, so those additions can't leak into a
     * later request through a container `singleton()` binding or a
     * long-lived worker.
     */
    public function allowSort(string ...$columns): static
    {
        $this->dynamicSortColumns = array_values(array_unique([...$this->dynamicSortColumns, ...$columns]));

        return $this;
    }

    /**
     * `sortBy()` merged with whatever `allowSort()` has added - the allow-list
     * every consumer (`Sorting::apply()`, `PaginateRequest::allowedSort()`)
     * should validate against, instead of `sortBy()` alone.
     *
     * @return array<string>
     */
    public function allowedSortColumns(): array
    {
        return array_values(array_unique([...$this->sortBy(), ...$this->dynamicSortColumns]));
    }
}
