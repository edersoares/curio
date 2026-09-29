<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Listeners;

use Dex\Laravel\Curio\Events\ApplyToken;
use RuntimeException;

class NegatedOperator
{
    public function handle(ApplyToken $apply): void
    {
        if ($apply->token->isAffirmative()) {
            return;
        }

        $operator = $apply->token->operator();

        if ($operator === 'not has relation') {
            $this->flipHasRelationCount($apply);
        } else {
            $this->flipDefaultOperators($apply);
        }
    }

    private function flipHasRelationCount(ApplyToken $apply): void
    {
        if ($apply->token->param('relation.count') === null) {
            return;
        }

        $relationOperator = $apply->token->param('relation.operator', '>=');

        assert(is_string($relationOperator));

        $flipped = match ($relationOperator) {
            '>' => '<=',
            '>=' => '<',
            '<=' => '>',
            '<' => '>=',
            '=' => '!=',
            default => throw new RuntimeException("The relation operator '{$relationOperator}' is not supported."), // @codeCoverageIgnore
        };

        $apply->token->replace([
            'negated' => false,
            'operator' => 'has relation',
            'params' => array_merge($apply->token->token['params'] ?? [], ['relation.operator' => $flipped]),
        ]);
    }

    private function flipDefaultOperators(ApplyToken $apply): void
    {
        $operator = $apply->token->operator();

        $operator = match ($operator) {
            '>' => '<=',
            '>=' => '<',
            '<=' => '>',
            '<' => '>=',
            'filled' => 'null',
            default => $operator,
        };

        $negated = match ($operator) {
            '>', '>=', '<=', '<', 'filled' => false,
            default => true,
        };

        $apply->token->replace([
            'negated' => $negated,
            'operator' => $operator,
        ]);
    }
}
