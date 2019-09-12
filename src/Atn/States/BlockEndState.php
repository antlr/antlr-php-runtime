<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class BlockEndState extends ATNState
{
    /** @var BlockStartState|null */
    public $startState;

    public function getStateType() : int
    {
        return self::BLOCK_END;
    }
}
