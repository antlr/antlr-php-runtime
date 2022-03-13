<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Dfa;

use Antlr\Antlr4\Runtime\Atn\ATNConfigSet;
use Antlr\Antlr4\Runtime\Atn\LexerActionExecutor;
use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Comparison\Hasher;

/**
 * A DFA state represents a set of possible ATN configurations.
 * As Aho, Sethi, Ullman p. 117 says "The DFA uses its state
 * to keep track of all possible states the ATN can be in after
 * reading each input symbol. That is to say, after reading
 * input a1a2..an, the DFA is in a state that represents the
 * subset T of the states of the ATN that are reachable from the
 * ATN's start state along some path labeled a1a2..an."
 * In conventional NFA&rarr;DFA conversion, therefore, the subset T
 * would be a bitset representing the set of states the
 * ATN could be in. We need to track the alt predicted by each
 * state as well, however. More importantly, we need to maintain
 * a stack of states, tracking the closure operations as they
 * jump from rule to rule, emulating rule invocations (method calls).
 * I have to add a stack to simulate the proper lookahead sequences for
 * the underlying LL grammar from which the ATN was derived.
 *
 * I use a set of ATNConfig objects not simple states. An ATNConfig
 * is both a state (ala normal conversion) and a RuleContext describing
 * the chain of rules (if any) followed to arrive at that state.
 *
 * A DFA state may have multiple references to a particular state,
 * but with different ATN contexts (with same or different alts)
 * meaning that state was reached via a different set of rule invocations.
 */
final class DFAState implements Hashable
{
    public int $stateNumber;

    public ATNConfigSet $configs;

    /**
     * `edges[symbol]` points to target of symbol. Shift up by 1 so (-1)
     * {@see Token::EOF} maps to `edges[0]`.
     *
     * @var \SplFixedArray<DFAState>|null
     */
    public ?\SplFixedArray $edges = null;

    public bool $isAcceptState = false;

    /**
     * If accept state, what ttype do we match or alt do we predict?
     * This is set to {@see ATN::INVALID_ALT_NUMBER)} when
     * `{@see DFAState::$predicates} !== null` or {@see DFAState::$requiresFullContext}.
     */
    public int $prediction = 0;

    public ?LexerActionExecutor $lexerActionExecutor = null;

    /**
     * Indicates that this state was created during SLL prediction that
     * discovered a conflict between the configurations in the state. Future
     * {@see ParserATNSimulator::execATN()} invocations immediately jumped doing
     * full context prediction if this field is true.
     */
    public bool $requiresFullContext = false;

    /**
     * During SLL parsing, this is a list of predicates associated with the
     * ATN configurations of the DFA state. When we have predicates,
     * {@see DFAState::$requiresFullContext} is `false` since full context
     * prediction evaluates predicates on-the-fly. If this is not null, then
     * {@see DFAState::$prediction} is {@see ATN::INVALID_ALT_NUMBER}.
     *
     * We only use these for non-{@see DFAState::$requiresFullContext} bu
     * conflicting states. That means we know from the context (it's $ or we
     * don't dip into outer context) that it's an ambiguity not a conflict.
     *
     * This list is computed by {@see ParserATNSimulator::predicateDFAState()}.
     *
     * @var array<PredPrediction>|null
     */
    public ?array $predicates = null;

    public function __construct(?ATNConfigSet $configs = null, int $stateNumber = -1)
    {
        $this->configs = $configs ?? new ATNConfigSet();
        $this->stateNumber = $stateNumber;
    }

    /**
     * Two {@see DFAState} instances are equal if their ATN configuration sets
     * are the same. This method is used to see if a state already exists.
     *
     * Because the number of alternatives and number of ATN configurations are
     * finite, there is a finite number of DFA states that can be processed.
     * This is necessary to show that the algorithm terminates.
     *
     * Cannot test the DFA state numbers here because in
     * {@see ParserATNSimulator::addDFAState()} we need to know if any other state
     * exists that has this exact set of ATN configurations. The
     * {@see DFAState::$stateNumber} is irrelevant.
     */
    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        // Compare set of ATN configurations in this set with other
        return Equality::equals($this->configs, $other->configs);
    }

    public function __toString(): string
    {
        $s = \sprintf('%d:%s', $this->stateNumber, (string) $this->configs);

        if ($this->isAcceptState) {
            $s .= '=>';

            if ($this->predicates !== null) {
                $s .= \sprintf('[%s]', \implode(', ', $this->predicates));
            } else {
                $s .= $this->prediction;
            }
        }

        return $s;
    }

    public function hashCode(): int
    {
        return Hasher::hash($this->configs);
    }
}
