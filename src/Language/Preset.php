<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Closure;

/**
 * Registry of named, reusable filter snippets, expanded wherever `@name`
 * appears in any DSL string (see `expand()`, called once, centrally, from
 * `Parser::transform()` - every one of the 6 DSL types (`filter`, `select`,
 * `aggregate`, `include`, `sort`, `cast`) is expanded before tokenizing,
 * whichever `Language` class or path (HTTP `Paginator`/fluent `Curio`)
 * eventually calls `transform()`).
 *
 * @phpstan-type PresetFilter string|(Closure(string|null): string)
 */
class Preset
{
    /**
     * @var array<string, PresetFilter>
     */
    private static array $items = [];

    /**
     * Built-in presets, distinct from `$items` so `clear()` (which every test
     * calls between cases) never removes them. A method, not a static
     * property, since a `Closure` literal isn't a compile-time constant
     * expression PHP allows as a property default. `count` is a Closure -
     * not a plain string - specifically so it never trips `validate()`'s
     * cycle detector (which bails out for closures without scanning them)
     * and so it can splice back whatever modifier chain followed `@count`
     * (e.g. `@count:@join`) instead of `expand()` swallowing it as an unused
     * preset param.
     *
     * @return array<string, PresetFilter>
     */
    private static function defaults(): array
    {
        return [
            'count' => fn (?string $param): string => '*:@count' . ($param !== null ? ":{$param}" : ''),
        ];
    }

    /**
     * @param PresetFilter $filter
     */
    public static function register(string $name, string|Closure $filter): void
    {
        self::validate($name, $filter);
        self::$items[$name] = $filter;
    }

    /**
     * @param array<string, PresetFilter> $presets
     */
    public static function registerMany(array $presets): void
    {
        foreach ($presets as $name => $filter) {
            self::register($name, $filter);
        }
    }

    /**
     * @return PresetFilter|null
     */
    public static function get(string $name): string|Closure|null
    {
        return self::$items[$name] ?? self::defaults()[$name] ?? null;
    }

    /**
     * @return array<string, PresetFilter>
     */
    public static function all(): array
    {
        return [...self::defaults(), ...self::$items];
    }

    public static function clear(): void
    {
        self::$items = [];
    }

    public static function has(string $name): bool
    {
        return isset(self::$items[$name]) || isset(self::defaults()[$name]);
    }

    /**
     * @param PresetFilter $filter
     * @param array<int, string> $visited
     */
    private static function validate(string $name, string|Closure $filter, array $visited = []): void
    {
        if ($filter instanceof Closure) {
            return;
        }

        $visited[] = $name;

        preg_match_all('/@([\w-]+)/', $filter, $matches);

        foreach ($matches[1] as $referencedPreset) {
            if (in_array($referencedPreset, $visited, true)) {
                $chain = implode(' -> ', [...$visited, $referencedPreset]);

                throw new \InvalidArgumentException("Circular preset reference detected: {$chain}");
            }

            $referencedFilter = self::get($referencedPreset);

            if ($referencedFilter !== null) {
                self::validate($referencedPreset, $referencedFilter, $visited);
            }
        }
    }

    /**
     * Expand every free-standing `@name` (optionally `@name:param`) token in
     * `$query` against the registry, recursively - a preset referencing
     * another preset gets expanded too. An unknown `@name` is left untouched.
     *
     * "Free-standing" means preceded by the start of the string or
     * whitespace - never glued to a preceding `key:`, which is reserved for
     * the `key:@modifier(args)` grammar (see `Tokenizer::matchRemainder()`),
     * so `created:@today` is always a modifier invocation, never a preset
     * reference, regardless of whether a preset named `today` exists.
     */
    public static function expand(string $query): string
    {
        return (string) preg_replace_callback('/(?:^|(?<=\s))@([\w-]+)(?::(\S+))?/', function (array $matches) {
            $presetName = $matches[1];
            $presetParam = $matches[2] ?? null;

            $preset = self::get($presetName);

            if ($preset === null) {
                return $matches[0];
            }

            $expanded = $preset instanceof Closure ? $preset($presetParam) : $preset;

            return self::expand($expanded);
        }, $query);
    }
}
