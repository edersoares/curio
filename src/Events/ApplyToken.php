<?php

declare(strict_types=1);

namespace Dex\Laravel\Curio\Events;

use Dex\Laravel\Curio\Language\Token;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class ApplyToken
{
    /**
     * @param Builder<Model>|Relation<Model, Model, mixed> $builder
     */
    public function __construct(
        public Token $token,
        public Builder|Relation $builder
    ) {}
}
