<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class PlusLoopbackState extends DecisionState
{
    public function getStateType(): int
    {
        return self::PLUS_LOOP_BACK;
    }
}
