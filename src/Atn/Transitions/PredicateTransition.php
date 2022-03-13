<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\SemanticContexts\Predicate;
use Antlr\Antlr4\Runtime\Atn\States\ATNState;

class PredicateTransition extends AbstractPredicateTransition
{
    public int $ruleIndex;

    public int $predIndex;

    /**
     * e.g., $i ref in pred
     */
    public bool $isCtxDependent;

    public function __construct(ATNState $target, int $ruleIndex, int $predIndex, bool $isCtxDependent)
    {
        parent::__construct($target);

        $this->ruleIndex = $ruleIndex;
        $this->predIndex = $predIndex;
        $this->isCtxDependent = $isCtxDependent;
    }

    public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool
    {
        return false;
    }

    public function getPredicate(): Predicate
    {
        return new Predicate($this->ruleIndex, $this->predIndex, $this->isCtxDependent);
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
        return self::PREDICATE;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->ruleIndex === $other->ruleIndex
            && $this->predIndex === $other->predIndex
            && $this->isCtxDependent === $other->isCtxDependent
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return \sprintf('pred_%d:%d', $this->ruleIndex, $this->predIndex);
    }
}
