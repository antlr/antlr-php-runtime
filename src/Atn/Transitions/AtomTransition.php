<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\IntervalSet;

final class AtomTransition extends Transition
{
    public int $label;

    public function __construct(ATNState $target, int $label)
    {
        parent::__construct($target);

        $this->label = $label;
    }

    public function label(): ?IntervalSet
    {
        return IntervalSet::fromInt($this->label);
    }

    public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool
    {
        return $this->label === $symbol;
    }

    public function getSerializationType(): int
    {
        return self::ATOM;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->label === $other->label
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return (string) $this->label;
    }
}
