<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Comparison\Equatable;
use Antlr\Antlr4\Runtime\IntervalSet;

/**
 * An ATN transition between any two ATN states. Subclasses define atom, set,
 * epsilon, action, predicate, rule transitions.
 *
 * This is a one way link. It emanates from a state (usually via a list of
 * transitions) and has a target state.
 *
 * Since we never have to change the ATN transitions once we construct it,
 * we can fix these transitions as specific classes. The DFA transitions
 * on the other hand need to update the labels as it adds transitions to
 * the states. We'll use the term Edge for the DFA to distinguish them from
 * ATN transitions.
 */
abstract class Transition implements Equatable
{
    public const EPSILON = 1;
    public const RANGE = 2;
    public const RULE = 3;
    public const PREDICATE = 4;
    public const ATOM = 5;
    public const ACTION = 6;
    public const SET = 7;
    public const NOT_SET = 8;
    public const WILDCARD = 9;
    public const PRECEDENCE = 10;

    /**
     * The target of this transition.
     */
    public ATNState $target;

    public function __construct(ATNState $target)
    {
        $this->target = $target;
    }

    /**
     * Determines if the transition is an "epsilon" transition. The default
     * implementation returns `false`.
     *
     * @return bool `true` if traversing this transition in the ATN does not
     *              consume an input symbol; otherwise, `false` if traversing
     *              this transition consumes (matches) an input symbol.
     */
    public function isEpsilon(): bool
    {
        return false;
    }

    public function label(): ?IntervalSet
    {
        return null;
    }

    abstract public function getSerializationType(): int;

    abstract public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool;

    abstract public function __toString(): string;
}
