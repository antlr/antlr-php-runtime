<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;

final class ActionTransition extends Transition
{
    public int $ruleIndex;

    public int $actionIndex;

    /**
     * e.g. $i ref in action
     */
    public bool $isCtxDependent;

    public function __construct(ATNState $target, int $ruleIndex, int $actionIndex = -1, bool $isCtxDependent = false)
    {
        parent::__construct($target);

        $this->ruleIndex = $ruleIndex;
        $this->actionIndex = $actionIndex;
        $this->isCtxDependent = $isCtxDependent;
    }

    public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isEpsilon(): bool
    {
        return true;
    }

    public function getSerializationType(): int
    {
        return self::ACTION;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->ruleIndex === $other->ruleIndex
            && $this->actionIndex === $other->actionIndex
            && $this->isCtxDependent === $other->isCtxDependent
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return \sprintf('action_%d:%d', $this->ruleIndex, $this->actionIndex);
    }
}
