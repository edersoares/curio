<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Language;

/**
 * @phpstan-import-type FilterClause from Filtering
 * @phpstan-import-type FilterClauseParams from Filtering
 * @phpstan-type FilterClauseUpdate array{type?: string, key?: string, operator?: string|null, value?: mixed, negated?: bool, modifiers?: array<string, list<string>>, params?: FilterClauseParams}
 */
class Token
{
    /**
     * @param FilterClause $token
     */
    public function __construct(
        public array $token,
    ) {}

    public function key(): string
    {
        return $this->token['key'];
    }

    public function operator(): ?string
    {
        return $this->token['operator'];
    }

    public function value(): mixed
    {
        return $this->token['value'];
    }

    public function param(string $key, mixed $default = null): mixed
    {
        $params = $this->token['params'] ?? [];

        return $params[$key] ?? $default;
    }

    public function isAffirmative(): bool
    {
        return $this->token['negated'] === false;
    }

    /**
     * @param FilterClauseUpdate $replace
     */
    public function replace(array $replace): void
    {
        $this->token = array_merge($this->token, $replace);
    }
}
