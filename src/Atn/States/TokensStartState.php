<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class TokensStartState extends DecisionState
{
    public function getStateType(): int
    {
        return self::TOKEN_START;
    }
}
