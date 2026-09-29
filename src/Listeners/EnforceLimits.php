<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyPending;
use Illuminate\Validation\ValidationException;

/**
 * The first step `Pipeline` runs for an `ApplyPending` batch: rejects a
 * request that carries more items than its query allows, before any other
 * step spends anything on it.
 *
 * An allow-list bounds *which* fields a request may name, not how many times
 * it names them - `ranking:1` repeated a thousand times is a thousand valid
 * clauses, each validated on its own and each a `WHERE` of its own (the
 * database gave up first, and the request came back as a 500). So the count
 * is checked on the parsed tokens, which is after `Parser::transform()` has
 * expanded every preset: a single `@name` can stand for many clauses.
 *
 * A filter scoped to a relation (`include=posts:@filter(...)`) is a batch of
 * its own and goes through this same step, against the limits of that
 * relation's query - each filter string has its own budget.
 */
class EnforceLimits
{
    public function handle(ApplyPending $event): void
    {
        $counts = array_count_values(array_column($event->tokens, 'type'));

        $limits = [
            'filter' => [$event->query->maxFilterClauses(), 'clauses'],
            'sort' => [$event->query->maxSortFields(), 'fields'],
            'aggregate' => [$event->query->maxAggregateItems(), 'items'],
            'include' => [$event->query->maxIncludeRelations(), 'relations'],
        ];

        $errors = [];

        foreach ($limits as $type => [$max, $unit]) {
            if (($counts[$type] ?? 0) > $max) {
                $errors[$type] = ["The $type may not have more than $max $unit."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
