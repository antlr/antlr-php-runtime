<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Atn\States\RuleStartState;

final class RuleTransition extends Transition
{
    /**
     * Ptr to the rule definition object for this rule ref.
     * No Rule object at runtime.
     */
    public int $ruleIndex;

    public int $precedence;

    /**
     * What node to begin computations following ref to rule
     */
    public ATNState $followState;

    public function __construct(RuleStartState $ruleStart, int $ruleIndex, int $precedence, ATNState $followState)
    {
        parent::__construct($ruleStart);

        $this->ruleIndex = $ruleIndex;
        $this->precedence = $precedence;
        $this->followState = $followState;
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
        return self::RULE;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->ruleIndex === $other->ruleIndex
            && $this->precedence === $other->precedence
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return \sprintf('rule_%d:%d,%s', $this->ruleIndex, $this->precedence, $this->followState);
    }
}
