<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Illuminate\Validation\ValidationException;

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
     * A balanced `(...)` group, nesting included, with double-quoted phrases
     * inside it taken as opaque - so `@filter(has:comments:@count(>=3))`
     * keeps its own closing parenthesis instead of the first `)` ending the
     * whole argument. Referenced as `(?&paren)` by every pattern below.
     */
    private const string PAREN = '(?(DEFINE)(?<paren>\((?:[^()"]|"[^"]*"|(?&paren))*\)))';

    /**
     * @throws ValidationException when a parenthesis is left unbalanced
     *
     * @return list<string>
     */
    public static function split(string $input, string $type = 'query'): array
    {
        self::assertBalanced($input, $type);

        preg_match_all('/' . self::PAREN . '(?:[^\s"()]|"[^"]*"|(?&paren))+/', $input, $matches);

        return $matches[0];
    }

    /**
     * An unbalanced parenthesis used to be dropped without a word: the token
     * around it was cut short and whatever was left (an `@filter(...)` that
     * lost its `)`) went on to be read as something else - free text, in a
     * `filter=` string. It is invalid input, so it is rejected as such.
     * Parentheses inside a double-quoted phrase don't count.
     *
     * @throws ValidationException
     */
    private static function assertBalanced(string $input, string $type): void
    {
        $depth = 0;

        foreach (str_split((string) preg_replace('/"[^"]*"/', '', $input)) as $char) {
            $depth += match ($char) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };

            if ($depth < 0) {
                break;
            }
        }

        if ($depth !== 0) {
            throw ValidationException::withMessages([
                $type => ["The $type expression '$input' has unbalanced parentheses."],
            ]);
        }
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

        if (preg_match('/' . self::PAREN . '^(?::@\w+(?&paren)?)+$/', $remainder)) {
            return ['modifiers' => self::parseModifiers($remainder)];
        }

        // `<>` has to come before `<`, or `<` wins and `>5` is left as the value.
        $operatorPart = '(?P<operator>>=|<=|!=|<>|=|>|<)?';

        // A `:` is only excluded from the value when it starts a `:@word`
        // sequence - the boundary of a trailing modifier chain - so a value
        // that legitimately contains `:` (JSON, `has:relation` free text,
        // ...) is untouched; only `value:@modifier` gets split.
        $valueChar = '(?:(?!:@)[^+\s,])';
        $valuePart = '(?P<value>(?:"[^"]*"|' . $valueChar . ')+(?:,(?:"[^"]*"|' . $valueChar . ')+)*)';
        $modifierTail = '(?::@\w+(?&paren)?)+';

        if (preg_match('/' . self::PAREN . '^:' . $operatorPart . $valuePart . '(?P<tail>' . $modifierTail . ')?$/', $remainder, $matches)) {
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

        preg_match_all('/' . self::PAREN . ':@(?P<modifier>\w+)(?:\((?P<value>(?:[^()"]|"[^"]*"|(?&paren))*)\))?/', $tail, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $found = [];

        foreach ($matches as $match) {
            $found[] = $match['value'] === null
                ? ['modifier' => (string) $match['modifier']]
                : ['modifier' => (string) $match['modifier'], 'value' => $match['value']];
        }

        return $found;
    }
}
