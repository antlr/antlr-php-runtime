<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class LoopEndState extends ATNState
{
    /** @var ATNState|null */
    public $loopBackState;

    public function getStateType() : int
    {
        return self::LOOP_END;
    }
}
