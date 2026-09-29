<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Expands `$name` / `$name!` references inside a DSL string against a request
 * context.
 *
 * Trust boundary: on the HTTP path that context is the whole request input
 * (`PaginateRequest::paginate()` passes `collect($this->all())`), so a `$name`
 * is filled by whatever the client sent under that key - it is never a
 * server-side value. Substitution also happens *before* tokenizing, so the
 * substituted text is read as DSL, not as a single value: given
 * `defaultFilter(): 'owner_id:$owner'`, a request can send
 * `?owner=1 status:draft` and have the extra token parsed as its own clause.
 *
 * Every clause still has to pass the same allow-lists and rules as one sent
 * directly in `filter=`, and clauses only ever `AND` together - so this can
 * add constraints, never drop the ones a `default*()` put there. But a
 * `default*()` that reads like it pins something down (a tenant id, the
 * authenticated user) pins down nothing unless the value comes from
 * somewhere the client can't reach: resolve those in
 * `FormRequest::prepareForValidation()` (or from a pre-configured
 * `PaginateQuery` instance returned by `getPaginateQuery()`), not from a
 * `$variable`.
 */
class VariableResolver
{
    private const string PATTERN = '/\$([a-zA-Z_][a-zA-Z0-9_]*)(!)?(?!\w)/';

    /**
     * @param Collection<string, mixed> $context
     */
    public function resolve(string $value, Collection $context): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            function (array $match) use ($context): string {
                $name = $match[1];
                $required = $match[2] ?? '';

                if ($context->has($name)) {
                    $contextValue = $context->get($name);

                    return is_string($contextValue) ? $contextValue : '';
                }

                if ($required === '!') {
                    throw ValidationException::withMessages([
                        $name => [trans('validation.required', ['attribute' => $name])],
                    ]);
                }

                return '';
            },
            $value,
        );
    }
}
