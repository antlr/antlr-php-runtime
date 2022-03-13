<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Dfa;

use Antlr\Antlr4\Runtime\Atn\States\DecisionState;
use Antlr\Antlr4\Runtime\Atn\States\StarLoopEntryState;
use Antlr\Antlr4\Runtime\Utils\Set;
use Antlr\Antlr4\Runtime\Vocabulary;
use Antlr\Antlr4\Runtime\VocabularyImpl;

final class DFA
{
    /**
     * A set of all DFA states. Use {@see Map} so we can get old state back
     * ({@see Set} only allows you to see if it's there).
     *
     * @var Set<DFAState>
     */
    public Set $states;

    public ?DFAState $s0 = null;

    public int $decision;

    /**
     * From which ATN state did we create this DFA?
     */
    public DecisionState $atnStartState;

    /**
     * `true` if this DFA is for a precedence decision; otherwise, `false`.
     * This is the backing field for {@see DFA::isPrecedenceDfa()}.
     */
    private bool $precedenceDfa;

    public function __construct(DecisionState $atnStartState, int $decision = 0)
    {
        $this->atnStartState = $atnStartState;
        $this->decision = $decision;
        $this->states = new Set();
        $this->precedenceDfa = false;

        if ($atnStartState instanceof StarLoopEntryState) {
            if ($atnStartState->isPrecedenceDecision) {
                $this->precedenceDfa = true;
                $precedenceState = new DFAState();
                $precedenceState->edges = new \SplFixedArray();
                $precedenceState->isAcceptState = false;
                $precedenceState->requiresFullContext = false;
                $this->s0 = $precedenceState;
            }
        }
    }

    /**
     * Gets whether this DFA is a precedence DFA. Precedence DFAs use a special
     * start state {@see DFA::$s0} which is not stored in {@see DFA::$states}.
     * The {@see DFAState::$edges} array for this start state contains outgoing
     * edges supplying individual start states corresponding to specific
     * precedence values.
     *
     * @return bool `true` if this is a precedence DFA; otherwise, `false`.
     *
     * @see Parser::getPrecedence()
     */
    public function isPrecedenceDfa(): bool
    {
        return $this->precedenceDfa;
    }

    /**
     * Get the start state for a specific precedence value.
     *
     * @param int $precedence The current precedence.
     *
     * @return DFAState|null The start state corresponding to the specified
     *                       precedence, or `null` if no start state exists
     *                       for the specified precedence.
     *
     * @throws \InvalidArgumentException If this is not a precedence DFA.
     */
    public function getPrecedenceStartState(int $precedence): ?DFAState
    {
        if (!$this->precedenceDfa || $this->s0 === null) {
            throw new \InvalidArgumentException('Only precedence DFAs may contain a precedence start state.');
        }

        if ($this->s0->edges === null) {
            throw new \LogicException('s0.edges cannot be null for a precedence DFA.');
        }

        if ($precedence < 0 || $precedence >= \count($this->s0->edges)) {
            return null;
        }

        return $this->s0->edges[$precedence] ?? null;
    }

    /**
     * Set the start state for a specific precedence value.
     *
     * @param int      $precedence The current precedence.
     * @param DFAState $startState The start state corresponding to the
     *                             specified precedence.
     *
     * @throws \InvalidArgumentException If this is not a precedence DFA.
     */
    public function setPrecedenceStartState(int $precedence, DFAState $startState): void
    {
        if (!$this->precedenceDfa || $this->s0 === null) {
            throw new \InvalidArgumentException('Only precedence DFAs may contain a precedence start state.');
        }

        if ($precedence < 0) {
            return;
        }

        $edges = $this->s0->edges;

        if ($edges === null) {
            throw new \LogicException('Unexpected null edges.');
        }

        if ($precedence >= $edges->count()) {
            $edges->setSize($precedence + 1);
        }

        // synchronization on s0 here is ok. when the DFA is turned into a
        // precedence DFA, s0 will be initialized once and not updated again
        // s0.edges is never null for a precedence DFA
        $edges[$precedence] = $startState;
    }

    /**
     * Return a list of all states in this DFA, ordered by state number.
     *
     * @return array<DFAState>
     */
    public function getStates(): array
    {
        $list = $this->states->getValues();

        \usort($list, static function (DFAState $a, DFAState $b) {
            return $a->stateNumber - $b->stateNumber;
        });

        return $list;
    }

    public function __toString(): string
    {
        return $this->toString(VocabularyImpl::emptyVocabulary());
    }

    public function toString(Vocabulary $vocabulary): string
    {
        if ($this->s0 === null) {
            return '';
        }

        $serializer = new DFASerializer($this, $vocabulary);

        return (string) $serializer;
    }

    public function toLexerString(): string
    {
        if ($this->s0 === null) {
            return '';
        }

        return (new LexerDFASerializer($this))->toString();
    }
}
