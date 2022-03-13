<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

final class WildcardTransition extends Transition
{
    public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool
    {
        return $symbol >= $minVocabSymbol && $symbol <= $maxVocabSymbol;
    }

    public function getSerializationType(): int
    {
        return self::WILDCARD;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return '.';
    }
}
