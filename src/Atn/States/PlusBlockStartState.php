<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class PlusBlockStartState extends BlockStartState
{
    public ?PlusLoopbackState $loopBackState = null;

    public function getStateType(): int
    {
        return self::PLUS_BLOCK_START;
    }
}
