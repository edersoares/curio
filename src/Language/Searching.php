<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Contracts\Applier;
use Dex\Laravel\Curio\Contracts\Searchable;
use Dex\Laravel\Curio\Query\PaginateQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type FilterClause from Filtering
 */
class Searching implements Applier
{
    /**
     * Build + enrich + validate + apply in one call, mirroring
     * `Sorting::apply()`/`Filtering::apply()` - for direct/fluent use
     * (`Curio.php`) where callers don't need each pipeline step separately.
     *
     * @param array<int, FilterClause> $tokens already-parsed via `Parser::transform()`
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     *
     * @throws ValidationException
     */
    public function apply(Builder|Relation $builder, array $tokens, PaginateQuery $query): void
    {
        $tokens = $this->build($tokens);
        $tokens = $this->enrich($tokens, $query);

        if ($errors = $this->validate($tokens)) {
            throw ValidationException::withMessages($errors); // @codeCoverageIgnore
        }

        $this->applyClauses($tokens, $builder);
    }

    /**
     * Keep only the (at most one) search-operator token from a parsed
     * `'filter'` token array - the mirror image of `Filtering::build()`,
     * which keeps everything else. Both classes read the same
     * `Parser::transform($filter, 'filter')` output and each picks out what
     * it owns.
     *
     * @param array<int, FilterClause> $tokens
     *
     * @return array<int, FilterClause>
     */
    public function build(array $tokens): array
    {
        return array_values(array_filter($tokens, fn (array $token) => $token['operator'] === 'search'));
    }

    /**
     * Inject `params['fields'|'mode'|'threshold']` from `searchBy()`/
     * `searchMode()`/`searchSimilarityThreshold()` into the search clause -
     * so `applyClauses()` knows which real columns to search without needing
     * the `Searchable` itself. Drops the clause entirely when `searchBy()`
     * is empty, so free text is silently discarded instead of failing at
     * apply time.
     *
     * @param array<int, FilterClause> $tokens
     *
     * @return array<int, FilterClause>
     */
    public function enrich(array $tokens, Searchable $searchable): array
    {
        $fields = $searchable->searchBy();

        if (empty($fields)) {
            return [];
        }

        return array_map(fn (array $token) => [
            ...$token,
            'params' => [
                'fields' => $fields,
                'mode' => $searchable->searchMode(),
                'threshold' => $searchable->searchSimilarityThreshold(),
            ],
        ], $tokens);
    }

    /**
     * No per-field allow-list to check - `searchBy()` controls which fields
     * are searched, not whether search is "allowed" - kept only for pipeline
     * symmetry with every other Language class.
     *
     * @param array<int, FilterClause> $tokens
     *
     * @return array<string, array<string>>
     */
    protected function validate(array $tokens): array
    {
        return [];
    }

    /**
     * @param array<int, FilterClause> $tokens
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function applyClauses(array $tokens, Builder|Relation $builder): void
    {
        foreach ($tokens as $token) {
            $this->applyClause($token, $builder);
        }
    }

    /**
     * @param FilterClause $token
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    private function applyClause(array $token, Builder|Relation $builder): void
    {
        $fields = $token['params']['fields'] ?? [];
        $mode = $token['params']['mode'] ?? 'like';
        $threshold = (float) ($token['params']['threshold'] ?? 0.3);
        $value = $token['value'];

        assert(is_string($value));

        $wrapped = "%{$value}%";

        $builder->where(function (Builder|Relation $query) use ($builder, $fields, $mode, $threshold, $value, $wrapped) {
            foreach ($fields as $field) {
                $column = $builder->qualifyColumn($field);

                $template = match ($mode) {
                    'trgm' => 'similarity(unaccent(%s), unaccent(?)) > ?',
                    'unaccent' => 'unaccent(lower(%s)) like unaccent(lower(?))',
                    default => null,
                };

                if ($template === null) {
                    $query->orWhereLike($column, $wrapped);

                    continue;
                }

                $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);
                $bindings = $mode === 'trgm' ? [$value, $threshold] : [$wrapped];

                // @phpstan-ignore-next-line argument.type (orWhereRaw() requires a literal-string - Laravel generic bound, not satisfiable by SQL built from a runtime column name)
                $query->orWhereRaw(sprintf($template, $wrappedColumn), $bindings);
            }
        });
    }
}
