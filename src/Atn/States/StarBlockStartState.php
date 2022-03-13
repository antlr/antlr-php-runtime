<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class StarBlockStartState extends BlockStartState
{
    public function getStateType(): int
    {
        return self::STAR_BLOCK_START;
    }
}
