<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class BasicBlockStartState extends BlockStartState
{
    public function getStateType(): int
    {
        return self::BLOCK_START;
    }
}
