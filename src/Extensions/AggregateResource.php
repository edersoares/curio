<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Extensions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JsonResource
 * @phpstan-require-extends JsonResource
 */
// @phpstan-ignore-next-line trait.unused (only consumed by workbench/, outside phpstan's configured `paths` (src/, config/) - correctness verified via composer test instead)
trait AggregateResource
{
    public function resolve($request = null)
    {
        if ($request->query(config('curio.query.aggregate'))) {
            return $this->resource->getAttributes();
        }

        return parent::resolve($request);
    }

    protected function aggregates(): array
    {
        return collect($this->resource->getAttributes())
            ->filter(function ($value, $key) {
                return preg_match(
                    '/_(count|exists|sum|avg|min|max)(_|$)/',
                    $key
                );
            })
            ->all();
    }

    /**
     * `Casting::applyParsed()` collects every `cast=` result into a single
     * `casts` attribute on the model (never the field's own attribute), so -
     * unlike `aggregates()` - this doesn't need a naming-pattern heuristic to
     * tell cast output apart from real columns.
     */
    protected function casts(): array
    {
        return $this->resource->getAttribute('casts') ?? [];
    }

    public function toAttributes(Request $request): array
    {
        $attributes = parent::toAttributes($request);
        $aggregates = $this->aggregates();
        $casts = $this->casts();

        return [
            ...$attributes,
            'aggregates' => $this->when((bool) count($aggregates), $aggregates),
            'casts' => $this->when((bool) count($casts), $casts),
        ];
    }
}
