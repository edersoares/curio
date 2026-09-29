<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio;

use Dex\Laravel\Curio\Contracts\Curious;
use Dex\Laravel\Curio\Language\Parser;
use Dex\Laravel\Curio\Query\AggregateBy;
use Dex\Laravel\Curio\Query\CastBy;
use Dex\Laravel\Curio\Query\FilterBy;
use Dex\Laravel\Curio\Query\IncludeBy;
use Dex\Laravel\Curio\Query\SelectBy;
use Dex\Laravel\Curio\Query\SortBy;
use Illuminate\Database\Eloquent\Builder as Eloquent;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 * @phpstan-require-implements Curious
 */
// @phpstan-ignore-next-line trait.unused (only consumed by workbench/, outside phpstan's configured `paths` (src/, config/) - correctness verified via composer test instead)
trait YourCuriosity
{
    use AggregateBy;
    use CastBy;
    use FilterBy;
    use IncludeBy;
    use SelectBy;
    use SortBy;

    /**
     * @param Eloquent<Model>|null $builder
     */
    public static function curio(?Eloquent $builder = null): Curio
    {
        return new Curio($builder ?? static::query(), new Parser());
    }
}
