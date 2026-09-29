<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Dex\Laravel\Curio\Language\Concerns\NormalizesFilterClauses;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type FilterClause from Filtering
 */
class Parser
{
    use NormalizesFilterClauses;

    /**
     * A single unified entry point covering both grammars a token can carry:
     * a `key:@modifier(args)` chain (bare `key` included - an empty chain)
     * or a `key:operator?value` comparison, delegated to `normalizeClause()`
     * (the same clause-building code `Filtering::parseHavingExpression()`
     * uses). For `'filter'` only, a token matching neither shape -
     * a bare word/phrase with no `key:` prefix at all, or one that fails to
     * parse entirely (a quoted phrase, `key:a+b`) - is free text instead of
     * an error: every free-text word/phrase found anywhere in the string is
     * collected and merged into a single trailing `{operator: 'search'}`
     * clause. Every other type keeps a bare word as a legitimate key
     * (e.g. `include=posts`).
     *
     * @param string $type one of `filter`, `select`, `aggregate`, `cast`, `include`, `sort` - the same names used as `config('curio.query.*')` keys
     * @return array<int, FilterClause>
     */
    public function transform(string $string, string $type): array
    {
        $string = Preset::expand($string);

        $result = [];
        $freeText = [];

        foreach (Tokenizer::split($string) as $token) {
            $prefix = Tokenizer::matchKeyPrefix($token);
            $remainder = $prefix === null ? null : Tokenizer::matchRemainder($prefix['remainder']);

            if ($type === 'filter') {
                if ($prefix === null || $remainder === null || $prefix['remainder'] === '') {
                    $freeText[] = $this->stripQuotes($token);
                    continue;
                }
            }

            if ($prefix === null || $remainder === null) {
                throw ValidationException::withMessages([
                    $type => "The $type expression '$token' is not valid.",
                ]);
            }

            if (empty($remainder['operator'])) {
                $result[] = [
                    'type' => $type,
                    'key' => $prefix['key'],
                    'operator' => null,
                    'value' => null,
                    'negated' => $prefix['negated'],
                    'modifiers' => $remainder['modifiers'],
                ];
                continue;
            }

            // Avoid between operator without start or end values
            if ($remainder['value'] === '..') {
                continue;
            }

            $result[] = [
                ...$this->normalizeClause($prefix['key'], $remainder['operator'], $remainder['value'], $prefix['negated'], $remainder['modifiers']),
                'type' => $type,
                'negated' => $prefix['negated'],
                'modifiers' => [],
            ];
        }

        if ($freeText !== []) {
            $result[] = [
                'type' => $type,
                'key' => 'search',
                'operator' => 'search',
                'value' => implode(' ', $freeText),
                'negated' => false,
                'modifiers' => [],
            ];
        }

        return $result;
    }
}
