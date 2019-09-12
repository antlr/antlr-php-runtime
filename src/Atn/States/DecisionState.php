<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

abstract class DecisionState extends ATNState
{
    /** @var int */
    public $decision = -1;

    /** @var bool */
    public $nonGreedy = false;
}
