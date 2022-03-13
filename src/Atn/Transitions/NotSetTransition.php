<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

final class NotSetTransition extends SetTransition
{
    public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool
    {
        return $symbol >= $minVocabSymbol && $symbol <= $maxVocabSymbol
            && !parent::matches($symbol, $minVocabSymbol, $maxVocabSymbol);
    }

    public function getSerializationType(): int
    {
        return self::NOT_SET;
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
        return '~' . parent::__toString();
    }
}
