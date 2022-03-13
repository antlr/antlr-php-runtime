<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;

final class EpsilonTransition extends Transition
{
    private int $outermostPrecedenceReturn;

    public function __construct(ATNState $target, int $outermostPrecedenceReturn = -1)
    {
        parent::__construct($target);

        $this->outermostPrecedenceReturn = $outermostPrecedenceReturn;
    }

    /**
     * @return int The rule index of a precedence rule for which this transition
     *             is returning from, where the precedence value is 0; otherwise,
     *             -1.
     *
     * @see ATNConfig::isPrecedenceFilterSuppressed()
     * @see ParserATNSimulator::applyPrecedenceFilter()
     */
    public function getOutermostPrecedenceReturn(): int
    {
        return $this->outermostPrecedenceReturn;
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
        return self::EPSILON;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->outermostPrecedenceReturn === $other->outermostPrecedenceReturn
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return 'epsilon';
    }
}
