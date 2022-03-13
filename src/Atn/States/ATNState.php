<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

use Antlr\Antlr4\Runtime\Atn\ATN;
use Antlr\Antlr4\Runtime\Atn\Transitions\Transition;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\IntervalSet;

abstract class ATNState implements Hashable
{
    public const INVALID_TYPE = 0;
    public const BASIC = 1;
    public const RULE_START = 2;
    public const BLOCK_START = 3;
    public const PLUS_BLOCK_START = 4;
    public const STAR_BLOCK_START = 5;
    public const TOKEN_START = 6;
    public const RULE_STOP = 7;
    public const BLOCK_END = 8;
    public const STAR_LOOP_BACK = 9;
    public const STAR_LOOP_ENTRY = 10;
    public const PLUS_LOOP_BACK = 11;
    public const LOOP_END = 12;

    public const SERIALIZATION_NAMES = [
        'INVALID',
        'BASIC',
        'RULE_START',
        'BLOCK_START',
        'PLUS_BLOCK_START',
        'STAR_BLOCK_START',
        'TOKEN_START',
        'RULE_STOP',
        'BLOCK_END',
        'STAR_LOOP_BACK',
        'STAR_LOOP_ENTRY',
        'PLUS_LOOP_BACK',
        'LOOP_END',
    ];

    public const INVALID_STATE_NUMBER = -1;

    /**
     * Which ATN are we in?
     */
    public ?ATN $atn = null;

    public int $stateNumber = self::INVALID_STATE_NUMBER;

    /**
     * Initially, at runtime, we don't have Rule objects.
     */
    public int $ruleIndex = 0;

    public bool $epsilonOnlyTransitions = false;

    /**
     * Track the transitions emanating from this ATN state.
     *
     * @var array<Transition>
     */
    protected array $transitions = [];

    /**
     * Used to cache lookahead during parsing, not used during construction.
     */
    public ?IntervalSet $nextTokenWithinRule = null;

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof static
            && $this->stateNumber === $other->stateNumber;
    }

    public function isNonGreedyExitState(): bool
    {
        return false;
    }

    /**
     * @return array<Transition>
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    public function getNumberOfTransitions(): int
    {
        return \count($this->transitions);
    }

    public function addTransition(Transition $trans, int $index = -1): void
    {
        if (\count($this->transitions) === 0) {
            $this->epsilonOnlyTransitions = $trans->isEpsilon();
        } elseif ($this->epsilonOnlyTransitions !== $trans->isEpsilon()) {
            $this->epsilonOnlyTransitions = false;
        }

        if ($index === -1) {
            $this->transitions[] = $trans;
        } else {
            \array_splice($this->transitions, $index, 1, [$trans]);
        }
    }

    public function getTransition(int $index): Transition
    {
        return $this->transitions[$index];
    }

    public function setTransition(Transition $trans, int $index): void
    {
        $this->transitions[$index] = $trans;
    }

    /**
     * @param array<Transition> $transitions
     */
    public function setTransitions(array $transitions): void
    {
        $this->transitions = $transitions;
    }

    public function removeTransition(int $index): void
    {
        \array_splice($this->transitions, $index, 1);
    }

    public function onlyHasEpsilonTransitions(): bool
    {
        return $this->epsilonOnlyTransitions;
    }

    public function setRuleIndex(int $ruleIndex): void
    {
        $this->ruleIndex = $ruleIndex;
    }

    public function __toString(): string
    {
        return (string) $this->stateNumber;
    }

    public function hashCode(): int
    {
        return $this->getStateType();
    }

    abstract public function getStateType(): int;
}
