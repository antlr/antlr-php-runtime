<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class StarLoopbackState extends ATNState
{
    public function getStateType(): int
    {
        return self::STAR_LOOP_BACK;
    }
}
