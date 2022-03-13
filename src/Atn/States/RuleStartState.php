<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class RuleStartState extends ATNState
{
    public ?RuleStopState $stopState = null;

    public bool $isLeftRecursiveRule = false;

    public function getStateType(): int
    {
        return self::RULE_START;
    }
}
