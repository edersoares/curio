<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

/**
 * Splits a DSL string into whitespace-separated tokens without breaking a
 * double-quoted phrase or a `:@modifier(...)` argument that contains a space
 * - used by `Parser::transform()` (e.g. `posts:@filter(status:draft
 * created_at:2026-01-01)` is one token, not two - the space belongs to the
 * nested filter expression, same as a space inside quotes belongs to the
 * quoted phrase) and, standalone, by `Curio`/`Paginator` to pre-split an
 * `include=` string before recombining it with dotted `select=` fields.
 */
final class Tokenizer
{
    /**
     * @return list<string>
     */
    public static function split(string $input): array
    {
        preg_match_all('/(?:[^\s"()]|"[^"]*"|\([^)]*\))+/', $input, $matches);

        return $matches[0];
    }

    /**
     * Splits a token into its optional leading `-` (negation) and the key
     * itself - shared because `Filtering` used to glue `-` onto the key
     * string (stripping it back out in four different places downstream)
     * while `Parser` already captured it separately; capturing it once here
     * removes that duplication. The key charclass keeps `-` (kebab-case
     * fields like `kebab-case` are legitimate), `>` (JSON path accessor,
     * `field->path`), and `*` (the wildcard field `Aggregating` uses for
     * `*:@count`) even though only one caller needs each - a token without
     * any of them just never uses them.
     *
     * @return array{negated: bool, key: string, remainder: string}|null
     */
    public static function matchKeyPrefix(string $token): ?array
    {
        if (!preg_match('/^(?P<not>-)?(?P<key>[\w.>*-]+)/', $token, $matches)) {
            return null;
        }

        return [
            'negated' => $matches['not'] !== '',
            'key' => $matches['key'],
            'remainder' => substr($token, strlen($matches[0])),
        ];
    }

    /**
     * The one place that decides what a `:...` remainder after a key means:
     * a `:@name(args)` modifier chain (one or more, never mixed with a
     * value), or the classic `:operator?value` comparison shape used by
     * `Filtering` - optionally followed by its own trailing modifier chain
     * (e.g. `has:posts:@count(>=3)`), since a value can carry modifiers too,
     * not just a bare key. `key:@word` right after the key (no value at all)
     * is ALWAYS a modifier chain - never a preset value - which is why
     * `Preset::expand()` only ever expands free-standing `@name` tokens
     * (never one glued to a key via `:`): a preset reference never reaches
     * this method still attached to a key, so there's no ambiguity left to
     * resolve here.
     *
     * @return array{modifiers: array<string, list<string>>}|array{operator: string, value: string, modifiers: array<string, list<string>>}|null
     */
    public static function matchRemainder(string $remainder): ?array
    {
        if ($remainder === '') {
            return ['modifiers' => []];
        }

        if (preg_match('/^(?::@\w+(?:\([^)]*\))?)+$/', $remainder)) {
            return ['modifiers' => self::parseModifiers($remainder)];
        }

        $operatorPart = '(?P<operator>>=|<=|!=|=|>|<|<>)?';

        // A `:` is only excluded from the value when it starts a `:@word`
        // sequence - the boundary of a trailing modifier chain - so a value
        // that legitimately contains `:` (JSON, `has:relation` free text,
        // ...) is untouched; only `value:@modifier` gets split.
        $valueChar = '(?:(?!:@)[^+\s,])';
        $valuePart = '(?P<value>(?:"[^"]*"|' . $valueChar . ')+(?:,(?:"[^"]*"|' . $valueChar . ')+)*)';
        $modifierTail = '(?::@\w+(?:\([^)]*\))?)+';

        if (preg_match('/^:' . $operatorPart . $valuePart . '(?P<tail>' . $modifierTail . ')?$/', $remainder, $matches)) {
            return [
                'operator' => $matches['operator'] ?: '=',
                'value' => $matches['value'],
                'modifiers' => self::parseModifiers($matches['tail'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function parseModifiers(string $tail): array
    {
        $modifiers = [];

        foreach (self::find($tail) as $match) {
            $modifiers[$match['modifier']] = isset($match['value']) ? [$match['value']] : [];
        }

        return $modifiers;
    }

    /**
     * Finds every `:@name` / `:@name(value)` token in a DSL item's tail -
     * purely mechanical, mirroring how `Tokenizer::split()` only tokenizes
     * (all interpretation happens afterward, in each caller's `build()`).
     * This doesn't validate modifier names or shape their values; each
     * caller (`Selecting`, `Aggregating`, `Casting`, `Including`, `Sorting`)
     * interprets and validates the modifiers it cares about itself.
     *
     * @return array<int, array{modifier: string, value?: string}>
     */
    public static function find(string $tail): array
    {
        if ($tail === '') {
            return [];
        }

        preg_match_all('/:@(?P<modifier>\w+)(?:\((?P<value>[^)]*)\))?/', $tail, $matches, PREG_SET_ORDER);

        return $matches;
    }
}
