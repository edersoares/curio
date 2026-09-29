<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type CastItem array{field: string, cast: string, alias: string|null}
 * @phpstan-import-type FilterClause from Filtering
 */
class Casting implements Applier
{
    /**
     * @param array<int, FilterClause> $tokens
     *
     * @return array<int, CastItem>
     */
    public function build(array $tokens): array
    {
        return array_map(fn (array $token) => $this->buildItem($token), $tokens);
    }

    /**
     * Build a single `cast` item from an already-tokenized field, the named
     * cast to apply, and an optional `:@alias(name)` modifier. Unlike
     * `Selecting`/`Aggregating`, the cast name here is never a fixed
     * vocabulary (date-part/aggregate functions) - it's an arbitrary
     * identifier resolved against the `PaginateQuery`'s own `castMutator()`
     * registry at apply time, so it's simply the first modifier the token
     * carries - whatever it's named - with every subsequent one validated
     * as `@alias`. Error messages reference `$token['key']` (the field) since
     * the original raw string isn't available once tokenization already ran.
     *
     * @param FilterClause $token
     *
     * @return CastItem
     */
    private function buildItem(array $token): array
    {
        $modifiers = $token['modifiers'];

        if ($token['operator'] !== null || $modifiers === [] || !preg_match('/^\w+$/', $token['key'])) {
            throw ValidationException::withMessages([
                'cast' => "The cast expression '{$token['key']}' is not valid.",
            ]);
        }

        $castName = array_key_first($modifiers);

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $castName)) {
            throw ValidationException::withMessages([
                'cast' => "The cast expression '{$token['key']}' is not valid.",
            ]);
        }

        $alias = null;

        foreach ($modifiers as $modifier => $values) {
            if ($modifier === $castName) {
                continue;
            }

            match ($modifier) {
                'alias' => $alias = $this->requireAliasValue($values[0] ?? '', $token['key']),
                default => throw ValidationException::withMessages([
                    'cast' => "The cast expression '{$token['key']}' is not valid: '@{$modifier}' is not allowed here.",
                ]),
            };
        }

        return ['field' => $token['key'], 'cast' => $castName, 'alias' => $alias];
    }

    private function requireAliasValue(string $value, string $item): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            throw ValidationException::withMessages([
                'cast' => "The cast expression '$item' is not valid.",
            ]);
        }

        return $value;
    }

    /**
     * @param array<int, CastItem> $parsedItems
     *
     * @return array<string, array<string>>
     */
    public function validate(array $parsedItems, PaginateQuery $query): array
    {
        $allowedFields = $query->castBy();
        $casts = $query->castMutator();
        $messages = [];

        foreach ($parsedItems as $parsed) {
            if (!in_array($parsed['field'], $allowedFields, true)) {
                $messages[] = "The field '{$parsed['field']}' is not allowed on query cast.";
            }

            if (!array_key_exists($parsed['cast'], $casts)) {
                $messages[] = "The cast '{$parsed['cast']}' is not registered on this query.";
            }
        }

        return $messages ? ['cast' => $messages] : [];
    }

    /**
     * Parses `cast=`, validates it against `castBy()`/`castMutator()`, and
     * registers a `Builder::afterQuery()` callback that applies it once the
     * query executes - matches the shape of every other `Language` class's
     * `apply()` (build → validate → throw on error → act on the builder),
     * except the "act" here is deferred: `get()`, `first()`, `find()`,
     * `findMany()`, `sole()`, `paginate()`, and `cursor()` all funnel through
     * `Builder::get()` internally, which runs `afterQuery()` callbacks
     * against the hydrated collection before any of those methods narrow or
     * wrap it further, so registering here covers every fetch method
     * uniformly.
     *
     * @param array<int, FilterClause> $tokens already-parsed via `Parser::transform()`
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query): void
    {
        assert($builder instanceof Builder);

        $parsedItems = $this->build($tokens);

        if ($errors = $this->validate($parsedItems, $query)) {
            throw ValidationException::withMessages($errors);
        }

        $this->registerCallback($builder, $parsedItems, $query->castMutator());
    }

    /**
     * @param Builder<Model> $builder
     * @param array<int, CastItem> $parsedItems
     * @param array<string, array<mixed, mixed>|Collection<int|string, mixed>|(callable(mixed): mixed)> $casts
     */
    private function registerCallback(Builder $builder, array $parsedItems, array $casts): void
    {
        $builder->afterQuery(function (mixed $result) use ($parsedItems, $casts): mixed {
            if ($result instanceof EloquentCollection) {
                $this->applyParsedUsing($result, $parsedItems, $casts);
            }

            return $result;
        });
    }

    /**
     * Applies already-built cast items to a hydrated collection, writing
     * results into each model's `casts` attribute - the primitive invoked
     * from `registerCallback()`'s own registered `Builder::afterQuery()`
     * callback.
     *
     * @param Collection<int, Model> $items
     * @param array<int, CastItem> $parsedItems
     * @param array<string, array<mixed, mixed>|Collection<int|string, mixed>|(callable(mixed): mixed)> $casts
     */
    protected function applyParsedUsing(Collection $items, array $parsedItems, array $casts): void
    {
        foreach ($items as $model) {
            $result = [];

            foreach ($parsedItems as $parsed) {
                $resolver = $casts[$parsed['cast']];
                $value = $model->getAttribute($parsed['field']);
                $key = $parsed['alias'] ?? $parsed['field'];

                $result[$key] = $this->resolveCast($resolver, $value);
            }

            $existing = $model->getAttribute('casts');

            $model->setAttribute('casts', [...(is_array($existing) ? $existing : []), ...$result]);
        }
    }

    /**
     * Array/`Collection` resolvers do a plain lookup keyed by the field's raw
     * value; anything else is guaranteed callable by `castMutator()`'s own
     * return type, so the last arm below is reached by elimination rather
     * than an explicit `is_callable()` check.
     *
     * @param array<mixed, mixed>|Collection<int|string, mixed>|(callable(mixed): mixed) $resolver
     */
    private function resolveCast(array|Collection|callable $resolver, mixed $value): mixed
    {
        if (is_array($resolver) || $resolver instanceof Collection) {
            $key = match (true) {
                is_int($value), is_string($value) => $value,
                is_scalar($value) => (string) $value,
                default => null,
            };

            if ($key === null) {
                return $value;
            }

            return $resolver instanceof Collection ? $resolver->get($key, $value) : ($resolver[$key] ?? $value);
        }

        return $resolver($value);
    }
}
