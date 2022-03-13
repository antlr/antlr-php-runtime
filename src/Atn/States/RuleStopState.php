<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class RuleStopState extends ATNState
{
    public function getStateType(): int
    {
        return self::RULE_STOP;
    }
}
