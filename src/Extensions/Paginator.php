<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Extensions;

use Dex\Laravel\Curio\Curio;
use Dex\Laravel\Curio\Language\Parser;
use Dex\Laravel\Curio\Language\VariableResolver;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class Paginator
{
    public function __construct(
        protected VariableResolver $resolver,
        protected Parser $parser,
    ) {}

    /**
     * Resolves the request `$context` into raw DSL strings and page/size
     * numbers, then delegates the actual parse/build/validate/apply pipeline
     * to `Curio` (bound to `$query` via `withQuery()`) - the same shared
     * implementation `Model::curio()` uses, so this class no longer
     * orchestrates `Filtering`/`Selecting`/`Sorting`/etc. itself.
     *
     * @param Builder<Model> $builder
     * @param Collection<string, mixed> $context
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function execute(Builder $builder, Collection $context, PaginateQuery $query): LengthAwarePaginator
    {
        $filter = $this->resolveParam('filter', $query->defaultFilter(), $context);
        $include = $this->resolveParam('include', $query->defaultInclude(), $context);
        $sort = $this->resolveParam('sort', $context->has('aggregate') ? '' : $query->defaultSort(), $context);
        $aggregate = $this->resolveParam('aggregate', $query->defaultAggregate(), $context);
        $select = $this->resolveParam('select', $query->defaultSelect(), $context);
        $cast = $this->resolveParam('cast', $query->defaultCast(), $context);

        $rawPage = $context->get($this->configKey('page'), $query->defaultPageNumber());
        $rawSize = $context->get($this->configKey('size'), $query->defaultPageSize());

        $page = is_numeric($rawPage) ? (int) $rawPage : $query->defaultPageNumber();
        $size = is_numeric($rawSize) ? (int) $rawSize : $query->defaultPageSize();

        // `PaginateRequest::rules()` already rejects a `size` above
        // `defaultMaxPageSize()` with a 422, but that rule only exists while
        // the consumer keeps it: a `FormRequest` that overrides `rules()`
        // wholesale, or any caller reaching this method directly, would
        // otherwise hand the page size straight to the database. Clamped
        // rather than rejected here, since the request-level rule owns the
        // error message.
        $size = max(1, min($size, $query->defaultMaxPageSize()));

        return new Curio($builder, $this->parser)
            ->withQuery($query)
            ->filter($filter)
            ->select($select)
            ->aggregate($aggregate)
            ->sort($sort)
            ->include($include)
            ->cast($cast)
            ->paginate($size, page: $page);
    }

    /**
     * Read `curio.query.{$name}` (e.g. `filter`, `sort`) from the request
     * context, falling back to the query's own default, then expand any
     * `$variable` references against the same context.
     *
     * @param Collection<string, mixed> $context
     */
    private function resolveParam(string $name, string $default, Collection $context): string
    {
        $value = $context->get($this->configKey($name), $default);

        return $this->resolver->resolve(is_string($value) ? $value : $default, $context);
    }

    /**
     * `curio.query.*` config entries are always strings (see
     * `config/curio.php`), but `config()` itself is typed to return `mixed`.
     */
    private function configKey(string $name): string
    {
        $value = config("curio.query.{$name}");

        assert(is_string($value));

        return $value;
    }
}
