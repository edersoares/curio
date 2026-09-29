<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;

/**
 * Registered first for `ApplyPending` in `CurioServiceProvider::boot()`, so
 * every filter/sort/select/aggregate/cast token has its `key` resolved
 * through `$query->replaceBy()` before any type-specific listener runs.
 *
 * `include` tokens are skipped: their `key` names a relation, not a field,
 * and a `replaceBy()` map entry meant for a `sort=`/`filter=` alias (e.g.
 * `'author' => 'author.name'`) would otherwise also rewrite a legitimate
 * `include=author` into the nonsensical `include=author.name`.
 */
class ReplaceKeys
{
    public function handle(ApplyPending $event): void
    {
        $replaces = $event->query->replaceBy();

        if ($replaces === []) {
            return;
        }

        $event->tokens = array_map(fn (array $token) => $token['type'] === 'include' ? $token : [
            ...$token,
            'key' => $replaces[$token['key']] ?? $token['key'],
        ], $event->tokens);
    }
}
