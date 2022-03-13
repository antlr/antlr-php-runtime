<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\IntervalSet;
use Antlr\Antlr4\Runtime\Token;

/**
 * A transition containing a set of values.
 */
class SetTransition extends Transition
{
    public IntervalSet $set;

    public function __construct(ATNState $target, ?IntervalSet $set = null)
    {
        parent::__construct($target);

        if ($set !== null) {
            $this->set = $set;
        } else {
            $this->set = new IntervalSet();
            $this->set->addOne(Token::INVALID_TYPE);
        }
    }

    public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool
    {
        return $this->set->contains($symbol);
    }

    public function label(): ?IntervalSet
    {
        return $this->set;
    }

    public function getSerializationType(): int
    {
        return self::SET;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->set->equals($other->set)
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return (string) $this->set;
    }
}
