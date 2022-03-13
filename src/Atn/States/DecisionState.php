<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

abstract class DecisionState extends ATNState
{
    public int $decision = -1;

    public bool $nonGreedy = false;
}
