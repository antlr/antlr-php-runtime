<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

abstract class BlockStartState extends DecisionState
{
    public ?BlockEndState $endState = null;
}
