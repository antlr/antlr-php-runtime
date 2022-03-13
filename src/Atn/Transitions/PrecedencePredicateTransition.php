<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\SemanticContexts\PrecedencePredicate;
use Antlr\Antlr4\Runtime\Atn\States\ATNState;

class PrecedencePredicateTransition extends AbstractPredicateTransition
{
    public int $precedence;

    public function __construct(ATNState $target, int $precedence)
    {
        parent::__construct($target);

        $this->precedence = $precedence;
    }

    public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool
    {
        return false;
    }

    public function getPredicate(): PrecedencePredicate
    {
        return new PrecedencePredicate($this->precedence);
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
        return self::PRECEDENCE;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->precedence === $other->precedence
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return $this->precedence . ' >= _p';
    }
}
