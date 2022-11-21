<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Atn\SemanticContexts\SemanticContext;
use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Atn\States\BlockEndState;
use Antlr\Antlr4\Runtime\Atn\States\BlockStartState;
use Antlr\Antlr4\Runtime\Atn\States\DecisionState;
use Antlr\Antlr4\Runtime\Atn\States\RuleStopState;
use Antlr\Antlr4\Runtime\Atn\States\StarLoopEntryState;
use Antlr\Antlr4\Runtime\Atn\Transitions\ActionTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\EpsilonTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\PrecedencePredicateTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\PredicateTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\RuleTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\Transition;
use Antlr\Antlr4\Runtime\Dfa\DFA;
use Antlr\Antlr4\Runtime\Dfa\DFAState;
use Antlr\Antlr4\Runtime\Dfa\PredPrediction;
use Antlr\Antlr4\Runtime\Error\Exceptions\NoViableAltException;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\IntervalSet;
use Antlr\Antlr4\Runtime\IntStream;
use Antlr\Antlr4\Runtime\LoggerProvider;
use Antlr\Antlr4\Runtime\Parser;
use Antlr\Antlr4\Runtime\ParserRuleContext;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContext;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContextCache;
use Antlr\Antlr4\Runtime\PredictionContexts\SingletonPredictionContext;
use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Token;
use Antlr\Antlr4\Runtime\TokenStream;
use Antlr\Antlr4\Runtime\Utils\BitSet;
use Antlr\Antlr4\Runtime\Utils\DoubleKeyMap;
use Antlr\Antlr4\Runtime\Utils\Set;
use Psr\Log\LoggerInterface as Logger;

/**
 * The embodiment of the adaptive LL(*), ALL(*), parsing strategy.
 *
 * The basic complexity of the adaptive strategy makes it harder to understand.
 * We begin with ATN simulation to build paths in a DFA. Subsequent prediction
 * requests go through the DFA first. If they reach a state without an edge for
 * the current symbol, the algorithm fails over to the ATN simulation to
 * complete the DFA path for the current input (until it finds a conflict state
 * or uniquely predicting state).
 *
 * All of that is done without using the outer context because we want to create
 * a DFA that is not dependent upon the rule invocation stack when we do a
 * prediction. One DFA works in all contexts. We avoid using context not
 * necessarily because it's slower, although it can be, but because of the DFA
 * caching problem. The closure routine only considers the rule invocation stack
 * created during prediction beginning in the decision rule. For example, if
 * prediction occurs without invoking another rule's ATN, there are no context
 * stacks in the configurations. When lack of context leads to a conflict, we
 * don't know if it's an ambiguity or a weakness in the strong LL(*) parsing
 * strategy (versus full LL(*)).
 *
 * When SLL yields a configuration set with conflict, we rewind the input and
 * retry the ATN simulation, this time using full outer context without adding
 * to the DFA. Configuration context stacks will be the full invocation stacks
 * from the start rule. If we get a conflict using full context, then we can
 * definitively say we have a true ambiguity for that input sequence. If we
 * don't get a conflict, it implies that the decision is sensitive to the outer
 * context. (It is not context-sensitive in the sense of context-sensitive
 * grammars.)
 *
 * The next time we reach this DFA state with an SLL conflict, through DFA
 * simulation, we will again retry the ATN simulation using full context mode.
 * This is slow because we can't save the results and have to "interpret" the
 * ATN each time we get that input.
 *
 * CACHING FULL CONTEXT PREDICTIONS
 *
 * We could cache results from full context to predicted alternative easily and
 * that saves a lot of time but doesn't work in presence of predicates. The set
 * of visible predicates from the ATN start state changes depending on the
 * context, because closure can fall off the end of a rule. I tried to cache
 * tuples (stack context, semantic context, predicted alt) but it was slower
 * than interpreting and much more complicated. Also required a huge amount of
 * memory. The goal is not to create the world's fastest parser anyway. I'd like
 * to keep this algorithm simple. By launching multiple threads, we can improve
 * the speed of parsing across a large number of files.
 *
 * There is no strict ordering between the amount of input used by SLL vs LL,
 * which makes it really hard to build a cache for full context. Let's say that
 * we have input A B C that leads to an SLL conflict with full context X. That
 * implies that using X we might only use A B but we could also use A B C D to
 * resolve conflict. Input A B C D could predict alternative 1 in one position
 * in the input and A B C E could predict alternative 2 in another position in
 * input. The conflicting SLL configurations could still be non-unique in the
 * full context prediction, which would lead us to requiring more input than the
 * original A B C. To make a   prediction cache work, we have to track the exact
 * input used during the previous prediction. That amounts to a cache that maps
 * X to a specific DFA for that context.
 *
 * Something should be done for left-recursive expression predictions. They are
 * likely LL(1) + pred eval. Easier to do the whole SLL unless error and retry
 * with full LL thing Sam does.
 *
 * AVOIDING FULL CONTEXT PREDICTION
 *
 * We avoid doing full context retry when the outer context is empty, we did not
 * dip into the outer context by falling off the end of the decision state rule,
 * or when we force SLL mode.
 *
 * As an example of the not dip into outer context case, consider as super
 * constructor calls versus function calls. One grammar might look like
 * this:
 *
 *     ctorBody
 *         : '{' superCall? stat* '}'
 *         ;
 *
 *
 * Or, you might see something like
 *
 *     stat
 *         : superCall ';'
 *         | expression ';'
 *         | ...
 *         ;
 *
 *
 * In both cases I believe that no closure operations will dip into the outer
 * context. In the first case ctorBody in the worst case will stop at the '}'.
 * In the 2nd case it should stop at the ';'. Both cases should stay within the
 * entry rule and not dip into the outer context.
 *
 * PREDICATES
 *
 * Predicates are always evaluated if present in either SLL or LL both. SLL and
 * LL simulation deals with predicates differently. SLL collects predicates as
 * it performs closure operations like ANTLR v3 did. It delays predicate
 * evaluation until it reaches and accept state. This allows us to cache the SLL
 * ATN simulation whereas, if we had evaluated predicates on-the-fly during
 * closure, the DFA state configuration sets would be different and we couldn't
 * build up a suitable DFA.
 *
 * When building a DFA accept state during ATN simulation, we evaluate any
 * predicates and return the sole semantically valid alternative. If there is
 * more than 1 alternative, we report an ambiguity. If there are 0 alternatives,
 * we throw an exception. Alternatives without predicates act like they have
 * true predicates. The simple way to think about it is to strip away all
 * alternatives with false predicates and choose the minimum alternative that
 * remains.
 *
 * When we start in the DFA and reach an accept state that's predicated, we test
 * those and return the minimum semantically viable alternative. If no
 * alternatives are viable, we throw an exception.
 *
 * During full LL ATN simulation, closure always evaluates predicates and
 * on-the-fly. This is crucial to reducing the configuration set size during
 * closure. It hits a landmine when parsing with the Java grammar, for example,
 * without this on-the-fly evaluation.
 *
 * SHARING DFA
 *
 * All instances of the same parser share the same decision DFAs through a
 * static field. Each instance gets its own ATN simulator but they share the
 * same {@see ParserATNSimulator::$decisionToDFA} field. They also share a
 * {@see PredictionContextCache} object that makes sure that all
 * {@see PredictionContext} objects are shared among the DFA states. This makes
 * a big size difference.
 *
 * THREAD SAFETY
 *
 * The {@see ParserATNSimulator} locks on the {@see ParserATNSimulator::$decisionToDFA}
 * field when it adds a new DFA object to that array.
 * {@see ParserATNSimulator::$addDFAEdge} locks on the DFA for the current
 * decision when setting the {@see DFAState::$edges} field.
 * {@see ParserATNSimulator::addDFAState()} locks on the DFA for the current
 * decision when looking up a DFA state to see if it already exists. We must
 * make sure that all requests to add DFA states that are equivalent result in
 * the same shared DFA object. This is because lots of threads will be trying
 * to update the DFA at once. {@see ParserATNSimulator::addDFAState()} also
 * locks inside the DFA lock but this time on the shared context cache when it
 * rebuilds the configurations' {@see PredictionContext} objects using cached
 * subgraphs/nodes. No other locking occurs, even during DFA simulation. This is
 * safe as long as we can guarantee that all threads referencing
 * `s.edge[t]` get the same physical target {@see DFAState}, or `null`. Once
 * into the DFA, the DFA simulation does not reference the {@see DFA::$states} map.
 * It follows the {@see DFAState::$edges} field to new targets. The DFA simulator
 * will either find {@see DFAState::$edges} to be `null`, to be non-`null` and
 * `dfa.edges[t]` null, or `dfa.edges[t]` to be non-null. The
 * {@see ParserATNSimulator::addDFAEdge()} method could be racing to set the field
 * but in either case the DFA simulator works; if `null`, and requests ATN
 * simulation. It could also race trying to get `dfa.edges[t]`, but either
 * way it will work because it's not doing a test and set operation.
 *
 * tarting with SLL then failing to combined SLL/LL (Two-Stage Parsing)
 *
 * Sam pointed out that if SLL does not give a syntax error, then there is no
 * point in doing full LL, which is slower. We only have to try LL if we get a
 * syntax error. For maximum speed, Sam starts the parser set to pure SLL
 * mode with the {@see BailErrorStrategy}:
 *
 *     parser.{@see Parser::getInterpreter()}.
 *     {@see  ParserATNSimulator::setPredictionMode()}`(}{@see PredictionMode::$SLL})`;
 *     parser->{@see Parser::setErrorHandler()}(new {@see BailErrorStrategy}());
 *
 * If it does not get a syntax error, then we're done. If it does get a syntax
 * error, we need to retry with the combined SLL/LL strategy.
 *
 * The reason this works is as follows. If there are no SLL conflicts, then the
 * grammar is SLL (at least for that input set). If there is an SLL conflict,
 * the full LL analysis must yield a set of viable alternatives which is a
 * subset of the alternatives reported by SLL. If the LL set is a singleton,
 * then the grammar is LL but not SLL. If the LL set is the same size as the SLL
 * set, the decision is SLL. If the LL set has size > 1, then that decision
 * is truly ambiguous on the current input. If the LL set is smaller, then the
 * SLL conflict resolution might choose an alternative that the full LL would
 * rule out as a possibility based upon better context information. If that's
 * the case, then the SLL parse will definitely get an error because the full LL
 * analysis says it's not viable. If SLL conflict resolution chooses an
 * alternative within the LL set, them both SLL and LL would choose the same
 * alternative because they both choose the minimum of multiple conflicting
 * alternatives.
 *
 * Let's say we have a set of SLL conflicting alternatives `{1, 2, 3}` and
 * a smaller LL set called s. If s is `{2, 3}`, then SLL parsing will
 * get an error because SLL will pursue alternative 1. If s is `{1, 2}` or
 * `{1, 3}` then both SLL and LL will choose the same alternative because
 * alternative one is the minimum of either set. If s is `{2}` or `{3}` then
 * SLL will get a syntax error. If s is `{1}` then SLL will succeed.
 *
 * Of course, if the input is invalid, then we will get an error for sure in
 * both SLL and LL parsing. Erroneous input will therefore require 2 passes over
 * the input.
 */
final class ParserATNSimulator extends ATNSimulator
{
    public static bool $traceAtnSimulation = false;

    protected Parser $parser;

    /** @var array<DFA> */
    public array $decisionToDFA = [];

    private int $mode = PredictionMode::LL;

    /**
     * Each prediction operation uses a cache for merge of prediction contexts.
     * Don't keep around as it wastes huge amounts of memory. DoubleKeyMap
     * isn't synchronized but we're ok since two threads shouldn't reuse same
     * parser/atnsim object because it can only handle one input at a time.
     * This maps graphs a and b to merged result c. (a,b)&rarr;c. We can avoid
     * the merge if we ever see a and b again. Note that (b,a)&rarr;c should
     * also be examined during cache lookup.
     */
    protected ?DoubleKeyMap $mergeCache = null;

    /**
     * LAME globals to avoid parameters!!!!! I need these down deep in predTransition.
     */
    protected TokenStream $input;

    protected int $startIndex = 0;

    protected ?ParserRuleContext $outerContext = null;

    protected ?DFA $dfa = null;

    private Logger $logger;

    /**
     * @param array<DFA> $decisionToDFA
     */
    public function __construct(
        Parser $parser,
        ATN $atn,
        array $decisionToDFA,
        PredictionContextCache $sharedContextCache,
    ) {
        parent::__construct($atn, $sharedContextCache);

        $this->parser = $parser;
        $this->decisionToDFA = $decisionToDFA;
        $this->logger = LoggerProvider::getLogger();
    }

    public function reset(): void
    {
        // No-op
    }

    public function clearDFA(): void
    {
        for ($d = 0, $count = \count($this->decisionToDFA); $d < $count; $d++) {
            $decisionState = $this->atn->getDecisionState($d);

            if ($decisionState !== null) {
                $this->decisionToDFA[$d] = new DFA($decisionState, $d);
            }
        }
    }

    /**
     * @throws RecognitionException
     */
    public function adaptivePredict(TokenStream $input, int $decision, ParserRuleContext $outerContext): int
    {
        if (self::$traceAtnSimulation) {
            $this->logger->debug(
                'adaptivePredict decision {decision} exec LA(1)=={token} line {line}:{pos}',
                [
                    'decision' => $decision,
                    'token' => $this->getTokenName($input->LA(1)),
                    'line' => $input->LT(1)?->getLine(),
                    'pos' => $input->LT(1)?->getCharPositionInLine(),
                ],
            );
        }

        $this->input = $input;
        $this->startIndex = $input->getIndex();
        $this->outerContext = $outerContext;

        $dfa = $this->decisionToDFA[$decision];
        $this->dfa = $dfa;

        $m = $input->mark();
        $index = $this->startIndex;

        // Now we are certain to have a specific decision's DFA, but do we still need an initial state?
        try {
            if ($dfa->isPrecedenceDfa()) {
                // The start state for a precedence DFA depends on the current
                // parser precedence, and is provided by a DFA method.

                $s0 = $dfa->getPrecedenceStartState($this->parser->getPrecedence());
            } else {
                // The start state for a "regular" DFA is just s0.
                $s0 = $dfa->s0;
            }

            if ($s0 === null) {
                $fullCtx = false;

                $s0_closure = $this->computeStartState(
                    $dfa->atnStartState,
                    ParserRuleContext::emptyContext(),
                    $fullCtx,
                );

                if ($dfa->isPrecedenceDfa()) {
                    /*
                     * If this is a precedence DFA, we use applyPrecedenceFilter
                     * to convert the computed start state to a precedence start
                     * state. We then use DFA.setPrecedenceStartState to set the
                     * appropriate start state for the precedence level rather
                     * than simply setting DFA.s0.
                     */

                    if ($dfa->s0 === null) {
                        throw new \LogicException('DFA.s0 cannot be null.');
                    }

                    $dfa->s0->configs = $s0_closure; // not used for prediction but useful to know start configs anyway

                    $s0_closure = $this->applyPrecedenceFilter($s0_closure);

                    $s0 = $this->addDFAState($dfa, new DFAState($s0_closure));

                    $dfa->setPrecedenceStartState($this->parser->getPrecedence(), $s0);
                } else {
                    $s0 = $this->addDFAState($dfa, new DFAState($s0_closure));
                    $dfa->s0 = $s0;
                }
            }

            $alt = $this->execATN($dfa, $s0, $input, $index, $outerContext);

            return $alt ?? 0;
        } finally {
            $this->mergeCache = null; // wack cache after each prediction
            $this->dfa = null;
            $input->seek($index);
            $input->release($m);
        }
    }

    /**
     * Performs ATN simulation to compute a predicted alternative based
     * upon the remaining input, but also updates the DFA cache to avoid
     * having to traverse the ATN again for the same input sequence.
     *
     * There are some key conditions we're looking for after computing a new
     * set of ATN configs (proposed DFA state):
     * if the set is empty, there is no viable alternative for current symbol
     * does the state uniquely predict an alternative?
     * does the state have a conflict that would prevent us from
     * putting it on the work list?
     *
     * We also have some key operations to do:
     *      - add an edge from previous DFA state to potentially new DFA state, D,
     *        upon current symbol but only if adding to work list, which means in all
     *        cases except no viable alternative (and possibly non-greedy decisions?)
     *      - collecting predicates and adding semantic context to DFA accept states
     *      - adding rule context to context-sensitive DFA accept states
     *      - consuming an input symbol
     *      - reporting a conflict
     *      - reporting an ambiguity
     *      - reporting a context sensitivity
     *      - reporting insufficient predicates
     *
     * cover these cases:
     *      - dead end
     *      - single alt
     *      - single alt + preds
     *      - conflict
     *      - conflict + preds
     *
     * @throws NoViableAltException
     */
    public function execATN(
        DFA $dfa,
        DFAState $s0,
        TokenStream $input,
        int $startIndex,
        ParserRuleContext $outerContext,
    ): ?int {
        if (self::$traceAtnSimulation) {
            $this->logger->debug(
                'execATN decision {decision}, DFA state {state}, LA(1)=={token} line {line}:{pos}',
                [
                    'decision' => $dfa->decision,
                    'state' => $s0->__toString(),
                    'token' => $this->getTokenName($input->LA(1)),
                    'line' => $input->LT(1)?->getLine(),
                    'pos' => $input->LT(1)?->getCharPositionInLine(),
                ],
            );
        }

        $previousD = $s0;

        $t = $input->LA(1);

        while (true) {
            $D = $this->getExistingTargetState($previousD, $t);

            if ($D === null) {
                $D = $this->computeTargetState($dfa, $previousD, $t);
            }

            if ($D === null) {
                throw new \LogicException('DFA State cannot be null.');
            }

            if ($D === self::error()) {
                /* If any configs in previous dipped into outer context, that
                 * means that input up to t actually finished entry rule
                 * at least for SLL decision. Full LL doesn't dip into outer
                 * so don't need special case.
                 * We will get an error no matter what so delay until after
                 * decision; better error message. Also, no reachable target
                 * ATN states in SLL implies LL will also get nowhere.
                 * If conflict in states that dip out, choose min since we
                 * will get error no matter what.
                 */

                $e = $this->noViableAlt($input, $outerContext, $previousD->configs, $startIndex);

                $input->seek($startIndex);

                $alt = $this->getSynValidOrSemInvalidAltThatFinishedDecisionEntryRule(
                    $previousD->configs,
                    $outerContext,
                );

                if ($alt !== ATN::INVALID_ALT_NUMBER) {
                    return $alt;
                }

                throw $e;
            }

            if ($D->requiresFullContext && $this->mode !== PredictionMode::SLL) {
                // IF PREDS, MIGHT RESOLVE TO SINGLE ALT => SLL (or syntax error)

                $conflictingAlts = $D->configs->getConflictingAlts();

                if ($D->predicates !== null) {
                    $conflictIndex = $input->getIndex();

                    if ($conflictIndex !== $startIndex) {
                        $input->seek($startIndex);
                    }

                    $conflictingAlts = $this->evalSemanticContextMany($D->predicates, $outerContext, true);

                    if ($conflictingAlts->length() === 1) {
                        return $conflictingAlts->minValue();
                    }

                    if ($conflictIndex !== $startIndex) {
                        // Restore the index so reporting the fallback to full
                        // context occurs with the index at the correct spot

                        $input->seek($conflictIndex);
                    }
                }

                $s0_closure = $this->computeStartState($dfa->atnStartState, $outerContext, true);

                $this->reportAttemptingFullContext(
                    $dfa,
                    $conflictingAlts,
                    $D->configs,
                    $startIndex,
                    $input->getIndex(),
                );

                return $this->execATNWithFullContext($dfa, $D, $s0_closure, $input, $startIndex, $outerContext);
            }

            if ($D->isAcceptState) {
                if ($D->predicates === null) {
                    return $D->prediction;
                }

                $stopIndex = $input->getIndex();
                $input->seek($startIndex);
                $alts = $this->evalSemanticContextMany($D->predicates, $outerContext, true);

                switch ($alts->length()) {
                    case 0:
                        throw $this->noViableAlt($input, $outerContext, $D->configs, $startIndex);

                    case 1:
                        return $alts->minValue();

                    default:
                        // Report ambiguity after predicate evaluation to make sure
                        // the correct set of ambig alts is reported.
                        $this->reportAmbiguity($dfa, $D, $startIndex, $stopIndex, false, $alts, $D->configs);

                        return $alts->minValue();
                }
            }

            $previousD = $D;

            if ($t !== IntStream::EOF) {
                $input->consume();
                $t = $input->LA(1);
            }
        }
    }

    /**
     * Get an existing target state for an edge in the DFA. If the target state
     * for the edge has not yet been computed or is otherwise not available,
     * this method returns `null`.
     *
     * @param DFAState $previousD The current DFA state
     * @param int      $t         The next input symbol
     *
     * @return DFAState|null The existing target DFA state for the given input
     *                       symbol `t`, or `null` if the target state for
     *                       this edge is not already cached.
     */
    public function getExistingTargetState(DFAState $previousD, int $t): ?DFAState
    {
        $edges = $previousD->edges;

        if ($edges === null || $t + 1 < 0 || $t + 1 >= $edges->count()) {
            return null;
        }

        return $edges[$t + 1];
    }

    /**
     * Compute a target state for an edge in the DFA, and attempt to add
     * the computed state and corresponding edge to the DFA.
     *
     * @param DFA      $dfa       The DFA
     * @param DFAState $previousD The current DFA state
     * @param int      $t         The next input symbol
     *
     * @return DFAState|null The computed target DFA state for the given input
     *                       symbol `t`. If `t` does not lead to a valid DFA
     *                       state, this method returns
     *                       {@see ParserATNSimulator::error()}.
     */
    public function computeTargetState(DFA $dfa, DFAState $previousD, int $t): ?DFAState
    {
        $reach = $this->computeReachSet($previousD->configs, $t, false);

        if ($reach === null) {
            $this->addDFAEdge($dfa, $previousD, $t, self::error());

            return self::error();
        }

        // Create new target state; we'll add to DFA after it's complete
        $D = new DFAState($reach);

        $predictedAlt = self::getUniqueAlt($reach);

        if ($predictedAlt !== ATN::INVALID_ALT_NUMBER) {
            // NO CONFLICT, UNIQUELY PREDICTED ALT

            $D->isAcceptState = true;
            $D->configs->uniqueAlt = $predictedAlt;
            $D->prediction = $predictedAlt;
        } elseif (PredictionMode::hasSLLConflictTerminatingPrediction($this->mode, $reach)) {
            // MORE THAN ONE VIABLE ALTERNATIVE

            $D->configs->setConflictingAlts($this->getConflictingAlts($reach));
            $D->requiresFullContext = true;

            // in SLL-only mode, we will stop at this state and return the minimum alt
            $D->isAcceptState = true;

            $conflictingAlts = $D->configs->getConflictingAlts();

            if ($conflictingAlts === null) {
                throw new \LogicException('Unexpected null conflicting alternatives.');
            }

            $D->prediction = $conflictingAlts->minValue();
        }

        if ($D->isAcceptState && $D->configs->hasSemanticContext) {
            $decisionState = $this->atn->getDecisionState($dfa->decision);

            if ($decisionState !== null) {
                $this->predicateDFAState($D, $decisionState);
            }

            if ($D->predicates !== null) {
                $D->prediction = ATN::INVALID_ALT_NUMBER;
            }
        }

        // All adds to dfa are done after we've created full D state
        $D = $this->addDFAEdge($dfa, $previousD, $t, $D);

        return $D;
    }

    protected function predicateDFAState(DFAState $dfaState, DecisionState $decisionState): void
    {
        // We need to test all predicates, even in DFA states that uniquely predict alternative.
        $nalts = $decisionState->getNumberOfTransitions();

        // Update DFA so reach becomes accept state with (predicate,alt) pairs
        // if preds found for conflicting alts.

        $altsToCollectPredsFrom = $this->getConflictingAltsOrUniqueAlt($dfaState->configs);
        $altToPred = $altsToCollectPredsFrom === null ?
            null :
            $this->getPredsForAmbigAlts($altsToCollectPredsFrom, $dfaState->configs, $nalts);

        if ($altToPred !== null) {
            $dfaState->predicates = $this->getPredicatePredictions($altsToCollectPredsFrom, $altToPred);
            $dfaState->prediction = ATN::INVALID_ALT_NUMBER; // make sure we use preds
        } else {
            // There are preds in configs but they might go away when
            // OR'd together like {p}? || NONE == NONE. If neither alt has preds,
            // resolve to min alt.

            if ($altsToCollectPredsFrom === null) {
                throw new \LogicException('Unexpected null alternatives to collect predicates');
            }

            $dfaState->prediction = $altsToCollectPredsFrom->minValue();
        }
    }

    /**
     * Comes back with reach.uniqueAlt set to a valid alt.
     *
     * @throws NoViableAltException
     */
    protected function execATNWithFullContext(
        DFA $dfa,
        DFAState $D, // how far we got before failing over
        ATNConfigSet $s0,
        TokenStream $input,
        int $startIndex,
        ParserRuleContext $outerContext,
    ): int {
        if (self::$traceAtnSimulation) {
            $this->logger->debug('execATNWithFullContext {state}', [
                'state' => $s0->__toString(),
            ]);
        }

        $fullCtx = true;
        $foundExactAmbig = false;
        $reach = null;
        $previous = $s0;
        $input->seek($startIndex);
        $t = $input->LA(1);
        $predictedAlt = 0;

        while (true) { // while more work
            $reach = $this->computeReachSet($previous, $t, $fullCtx);

            if ($reach === null) {
                /* I any configs in previous dipped into outer context, that
                 * means that input up to t actually finished entry rule
                 * at least for LL decision. Full LL doesn't dip into outer
                 * so don't need special case.
                 * We will get an error no matter what so delay until after
                 * decision; better error message. Also, no reachable target
                 * ATN states in SLL implies LL will also get nowhere.
                 * If conflict in states that dip out, choose min since we
                 * will get error no matter what.
                 */

                $e = $this->noViableAlt($input, $outerContext, $previous, $startIndex);

                $input->seek($startIndex);

                $alt = $this->getSynValidOrSemInvalidAltThatFinishedDecisionEntryRule($previous, $outerContext);

                if ($alt !== ATN::INVALID_ALT_NUMBER) {
                    return $alt;
                }

                throw $e;
            }

            $altSubSets = PredictionMode::getConflictingAltSubsets($reach);
            $reach->uniqueAlt = self::getUniqueAlt($reach);

            // unique prediction?
            if ($reach->uniqueAlt !== ATN::INVALID_ALT_NUMBER) {
                $predictedAlt = $reach->uniqueAlt;

                break;
            }

            if ($this->mode !== PredictionMode::LL_EXACT_AMBIG_DETECTION) {
                $predictedAlt = PredictionMode::resolvesToJustOneViableAlt($altSubSets);

                if ($predictedAlt !== ATN::INVALID_ALT_NUMBER) {
                    break;
                }
            } else {
                // In exact ambiguity mode, we never try to terminate early.
                // Just keeps scarfing until we know what the conflict is
                if (PredictionMode::allSubsetsConflict($altSubSets) && PredictionMode::allSubsetsEqual($altSubSets)) {
                    $foundExactAmbig = true;
                    $predictedAlt = PredictionMode::getSingleViableAlt($altSubSets);

                    break;
                }

                // Else there are multiple non-conflicting subsets or
                // we're not sure what the ambiguity is yet.
                // So, keep going.
            }

            $previous = $reach;

            if ($t !== IntStream::EOF) {
                $input->consume();
                $t = $input->LA(1);
            }
        }

        // If the configuration set uniquely predicts an alternative,
        // without conflict, then we know that it's a full LL decision not SLL.
        if ($reach->uniqueAlt !== ATN::INVALID_ALT_NUMBER) {
            $this->reportContextSensitivity($dfa, $predictedAlt, $reach, $startIndex, $input->getIndex());

            return $predictedAlt;
        }

        /* We do not check predicates here because we have checked them on-the-fly
         * when doing full context prediction.
         *
         * In non-exact ambiguity detection mode, we might  actually be able to
         * detect an exact ambiguity, but I'm not going to spend the cycles
         * needed to check. We only emit ambiguity warnings in exact ambiguity
         * mode.
         *
         * For example, we might know that we have conflicting configurations.
         * But, that does not mean that there is no way forward without a
         * conflict. It's possible to have nonconflicting alt subsets as in:
         *
         *     altSubSets=[{1, 2}, {1, 2}, {1}, {1, 2}]
         *
         *     from
         *        [
         *            (17,1,[5 $]), (13,1,[5 10 $]), (21,1,[5 10 $]), (11,1,[$]),
         *            (13,2,[5 10 $]), (21,2,[5 10 $]), (11,2,[$])
         *        ]
         *
         * In this case, (17,1,[5 $]) indicates there is some next sequence that
         * would resolve this without conflict to alternative 1. Any other viable
         * next sequence, however, is associated with a conflict. We stop
         * looking for input because no amount of further lookahead will alter
         * the fact that we should predict alternative 1. We just can't say for
         * sure that there is an ambiguity without looking further.
         */

        $this->reportAmbiguity($dfa, $D, $startIndex, $input->getIndex(), $foundExactAmbig, null, $reach);

        return $predictedAlt;
    }

    protected function computeReachSet(ATNConfigSet $closure, int $t, bool $fullCtx): ?ATNConfigSet
    {
        if ($this->mergeCache === null) {
            $this->mergeCache = new DoubleKeyMap();
        }

        $intermediate = new ATNConfigSet($fullCtx);

        /*
         * Configurations already in a rule stop state indicate reaching the end
         * of the decision rule (local context) or end of the start rule (full
         * context). Once reached, these configurations are never updated by a
         * closure operation, so they are handled separately for the performance
         * advantage of having a smaller intermediate set when calling closure.
         *
         * For full-context reach operations, separate handling is required to
         * ensure that the alternative matching the longest overall sequence is
         * chosen when multiple such configurations can match the input.
         */
        $skippedStopStates = null;

        // First figure out where we can reach on input t
        foreach ($closure->elements() as $c) {
            if ($c->state instanceof RuleStopState) {
                if ($c->context !== null && !$c->context->isEmpty()) {
                    throw new \LogicException('Context cannot be empty.');
                }

                if ($fullCtx || $t === IntStream::EOF) {
                    if ($skippedStopStates === null) {
                        $skippedStopStates = [];
                    }

                    $skippedStopStates[] = $c;
                }

                continue;
            }

            foreach ($c->state->getTransitions() as $trans) {
                $target = $this->getReachableTarget($trans, $t);

                if ($target !== null) {
                    $cfg = new ATNConfig($c, $target);
                    $intermediate->add($cfg, $this->mergeCache);
                }
            }
        }

        // Now figure out where the reach operation can take us...

        $reach = null;

        /* This block optimizes the reach operation for intermediate sets which
         * trivially indicate a termination state for the overall
         * adaptivePredict operation.
         *
         * The conditions assume that intermediate contains all configurations
         * relevant to the reach set, but this condition is not true when one
         * or more configurations have been withheld in skippedStopStates, or
         * when the current symbol is EOF.
         */
        if ($skippedStopStates === null && $t !== Token::EOF) {
            if (\count($intermediate->elements()) === 1) {
                // Don't pursue the closure if there is just one state.
                // It can only have one alternative; just add to result
                // Also don't pursue the closure if there is unique alternative
                // among the configurations.

                $reach = $intermediate;
            } elseif (self::getUniqueAlt($intermediate) !== ATN::INVALID_ALT_NUMBER) {
                // Also don't pursue the closure if there is unique alternative among the configurations.
                $reach = $intermediate;
            }
        }

        // If the reach set could not be trivially determined, perform a closure
        // operation on the intermediate set to compute its initial value.
        if ($reach === null) {
            $reach = new ATNConfigSet($fullCtx);
            $closureBusy = new Set();
            $treatEofAsEpsilon = $t === Token::EOF;

            foreach ($intermediate->elements() as $item) {
                $this->closure($item, $reach, $closureBusy, false, $fullCtx, $treatEofAsEpsilon);
            }
        }

        if ($t === IntStream::EOF) {
            /* After consuming EOF no additional input is possible, so we are
             * only interested in configurations which reached the end of the
             * decision rule (local context) or end of the start rule (full
             * context). Update reach to contain only these configurations. This
             * handles both explicit EOF transitions in the grammar and implicit
             * EOF transitions following the end of the decision or start rule.
             *
             * When reach==intermediate, no closure operation was performed. In
             * this case, removeAllConfigsNotInRuleStopState needs to check for
             * reachable rule stop states as well as configurations already in
             * a rule stop state.
             *
             * This is handled before the configurations in skippedStopStates,
             * because any configurations potentially added from that list are
             * already guaranteed to meet this condition whether or not it's
             * required.
             */

            $reach = $this->removeAllConfigsNotInRuleStopState($reach, $reach->equals($intermediate));
        }

        /* If `skippedStopStates !== null`, then it contains at least one
         * configuration. For full-context reach operations, these
         * configurations reached the end of the start rule, in which case we
         * only add them back to reach if no configuration during the current
         * closure operation reached such a state. This ensures adaptivePredict
         * chooses an alternative matching the longest overall sequence when
         * multiple alternatives are viable.*/

        if ($skippedStopStates !== null && (!$fullCtx || !PredictionMode::hasConfigInRuleStopState($reach))) {
            foreach ($skippedStopStates as $lValue) {
                $reach->add($lValue, $this->mergeCache);
            }
        }

        if (self::$traceAtnSimulation) {
            $this->logger->debug('computeReachSet {closure} -> {reach}', [
                'closure' => $closure->__toString(),
                'reach' => $reach->__toString(),
            ]);
        }

        if ($reach->isEmpty()) {
            return null;
        }

        return $reach;
    }

    /**
     * Return a configuration set containing only the configurations from
     * `configs` which are in a {@see RuleStopState}. If all configurations in
     * `configs` are already in a rule stop state, this method simply returns
     * `configs`.
     *
     * When `lookToEndOfRule` is true, this method uses {@see ATN::nextTokens()}
     * for each configuration in `configs` which is not already in a rule stop
     * state to see if a rule stop state is reachable from the configuration via
     * epsilon-only transitions.
     *
     * @param ATNConfigSet $configs         The configuration set to update
     * @param bool         $lookToEndOfRule When true, this method checks for
     *                                      rule stop states reachable by
     *                                      epsilon-only transitions from each
     *                                      configuration in `configs`.
     *
     * @return ATNConfigSet `configs` if all configurations in `configs` are
     *                      in a rule stop state, otherwise return a new
     *                      configuration set containing only the configurations
     *                      from `configs` which are in a rule stop state.
     *
     * @throws \InvalidArgumentException
     */
    protected function removeAllConfigsNotInRuleStopState(ATNConfigSet $configs, bool $lookToEndOfRule): ATNConfigSet
    {
        if (PredictionMode::allConfigsInRuleStopStates($configs)) {
            return $configs;
        }

        $result = new ATNConfigSet($configs->fullCtx);

        foreach ($configs->elements() as $config) {
            if ($config->state instanceof RuleStopState) {
                $result->add($config, $this->mergeCache);

                continue;
            }

            if ($lookToEndOfRule && $config->state->onlyHasEpsilonTransitions()) {
                $nextTokens = $this->atn->nextTokens($config->state);

                if ($nextTokens->contains(Token::EPSILON)) {
                    $endOfRuleState = $this->atn->ruleToStopState[$config->state->ruleIndex];
                    $result->add(new ATNConfig($config, $endOfRuleState), $this->mergeCache);
                }
            }
        }

        return $result;
    }

    protected function computeStartState(ATNState $p, RuleContext $ctx, bool $fullCtx): ATNConfigSet
    {
        // Always at least the implicit call to start rule
        $initialContext = PredictionContext::fromRuleContext($this->atn, $ctx);
        $configs = new ATNConfigSet($fullCtx);

        if (self::$traceAtnSimulation) {
            $this->logger->debug('computeStartState from ATN state {state} initialContext={initialContext}', [
                'state' => $p->__toString(),
                'initialContext' => $initialContext->__toString(),
            ]);
        }

        foreach ($p->getTransitions() as $i => $t) {
            $c = new ATNConfig(null, $t->target, $initialContext, null, $i + 1);
            $closureBusy = new Set();

            $this->closure($c, $configs, $closureBusy, true, $fullCtx, false);
        }

        return $configs;
    }

    /*
     * parrt internal source braindump that doesn't mess up external API spec.
     *
     * Context-sensitive in that they can only be properly evaluated in the context
     * of the proper prec argument. Without pruning, these predicates are normal
     * predicates evaluated when we reach conflict state (or unique prediction).
     * As we cannot evaluate these predicates out of context, the resulting
     * conflict leads to full LL evaluation and nonlinear prediction which
     * shows up very clearly with fairly large expressions.
     *
     * Example grammar:
     *     e
     *        : e '*' e
     *        | e '+' e
     *        | INT
     *    ;
     *
     * We convert that to the following:
     *
     *    e[int prec]
     *        :   INT ( {3>=prec}? '*' e[4] | {2>=prec}? '+' e[3] )*
     *    ;
     *
     * The (..)* loop has a decision for the inner block as well as an enter
     * or exit decision, which is what concerns us here. At the 1st + of input
     * 1+2+3, the loop entry sees both predicates and the loop exit also sees
     * both predicates by falling off the edge of e. This is because we have
     * no stack information with SLL and find the follow of e, which will
     * hit the return states inside the loop after e[4] and e[3], which brings
     * it back to the enter or exit decision. In this case, we know that we
     * cannot evaluate those predicates because we have fallen off the edge
     * of the stack and will in general not know which prec parameter is
     * the right one to use in the predicate.
     *
     * Because we have special information, that these are precedence predicates,
     * we can resolve them without failing over to full LL despite their context
     * sensitive nature. We make an assumption that prec[-1] <= prec[0], meaning
     * that the current precedence level is greater than or equal to the precedence
     * level of recursive invocations above us in the stack. For example, if
     * predicate {3>=prec}? is true of the current prec, then one option is to
     * enter the loop to match it now. The other option is to exit the loop and
     * the left recursive rule to match the current operator in rule invocation
     * further up the stack. But, we know that all of those prec are lower or
     * the same value and so we can decide to enter the loop instead of matching
     * it later. That means we can strip out the other configuration for the exit branch.
     *
     * So imagine we have (14,1,$,{2>=prec}?) and then
     * (14,2,$-dipsIntoOuterContext,{2>=prec}?). The optimization allows us to
     * collapse these two configurations. We know that if {2>=prec}? is true
     * for the current prec parameter, it will  also be true for any precfrom
     * an invoking e call, indicated by dipsIntoOuterContext. As the predicates
     * are both true, we have the option to evaluate them early in the decision
     * start state. We do this by stripping both predicates and choosing to
     * enter the loop as it is consistent with the notion of operator precedence.
     * It's also how the full LL conflict resolution would work.
     *
     * The solution requires a different DFA start state for each precedence level.
     *
     * The basic filter mechanism is to remove configurations of the form (p, 2, pi)
     * if (p, 1, pi) exists for the same p and pi. In other words, for the same
     * ATN state and predicate context, remove any configuration associated with
     * an exit branch if there is a configuration associated with the enter branch.
     *
     * It's also the case that the filter evaluates precedence predicates and
     * resolves conflicts according to precedence levels. For example, for input
     * 1+2+3 at the first +, we see prediction filtering.
     *
     *     [(11,1,[$],{3>=prec}?), (14,1,[$],{2>=prec}?), (5,2,[$],up=1),
     *     (11,2,[$],up=1), (14,2,[$],up=1)], hasSemanticContext=true,dipsIntoOuterContext
     *
     *     to
     *
     *     [(11,1,[$]), (14,1,[$]), (5,2,[$],up=1)],dipsIntoOuterContext
     *
     * This filters because {3>=prec}? evals to true and collapses
     * (11,1,[$],{3>=prec}?) and (11,2,[$],up=1) since early conflict
     * resolution based upon rules of operator precedence fits with our
     * usual match first alt upon conflict.
     *
     * We noticed a problem where a recursive call resets precedence to 0.
     * Sam's fix: each config has flag indicating if it has returned from
     * an expr[0] call. then just don't filter any config with that flag set.
     * flag is carried along in closure(). so to avoid adding field, set bit
     * just under sign bit of dipsIntoOuterContext (SUPPRESS_PRECEDENCE_FILTER).
     * With the change you filter "unless (p, 2, pi) was reached after leaving
     * the rule stop state of the LR rule containing state p, corresponding
     * to a rule invocation with precedence level 0".
     */

    /**
     * This method transforms the start state computed by
     * {@see ParserATNSimulator::computeStartState()} to the special start state
     * used by a precedence DFA for a particular precedence value. The transformation
     * process applies the following changes to the start state's configuration
     * set.
     *
     * - Evaluate the precedence predicates for each configuration using
     *    {@see SemanticContext//evalPrecedence}.
     * - Remove all configurations which predict an alternative greater than
     *    1, for which another configuration that predicts alternative 1 is in the
     *    same ATN state with the same prediction context. This transformation is
     *    valid for the following reasons:
     * - The closure block cannot contain any epsilon transitions which bypass
     *    the body of the closure, so all states reachable via alternative 1 are
     *    part of the precedence alternatives of the transformed left-recursive
     *    rule.
     * - The "primary" portion of a left recursive rule cannot contain an
     *    epsilon transition, so the only way an alternative other than 1 can exist
     *    in a state that is also reachable via alternative 1 is by nesting calls
     *    to the left-recursive rule, with the outer calls not being at the
     *    preferred precedence level.
     *
     * The prediction context must be considered by this filter to address
     * situations like the following.
     *
     *     grammar TA;
     *     prog: statement* EOF;
     *     statement: letterA | statement letterA 'b' ;
     *     letterA: 'a';
     *
     * If the above grammar, the ATN state immediately before the token
     * reference `'a'` in `letterA` is reachable from the left edge
     * of both the primary and closure blocks of the left-recursive rule
     * `statement`. The prediction context associated with each of these
     * configurations distinguishes between them, and prevents the alternative
     * which stepped out to `prog` (and then back in to `statement` from being
     * eliminated by the filter.
     *
     * @param ATNConfigSet $configs The configuration set computed by
     *                              {@see ParserATNSimulator::computeStartState()}
     *                              as the start state for the DFA.
     *
     * @return ATNConfigSet The transformed configuration set representing the start state
     *                      for a precedence DFA at a particular precedence level
     *                      (determined by calling {@see Parser::getPrecedence()}).
     *
     * @throws \InvalidArgumentException
     */
    protected function applyPrecedenceFilter(ATNConfigSet $configs): ATNConfigSet
    {
        /** @var array<PredictionContext> $statesFromAlt1 */
        $statesFromAlt1 = [];
        $configSet = new ATNConfigSet($configs->fullCtx);

        foreach ($configs->elements() as $config) {
            // handle alt 1 first
            if ($config->alt !== 1) {
                continue;
            }

            $updatedContext = $this->outerContext !== null ?
                $config->semanticContext->evalPrecedence($this->parser, $this->outerContext) :
                null;

            if ($updatedContext === null) {
                continue;
            }

            $statesFromAlt1[$config->state->stateNumber] = $config->context;

            if (!$updatedContext->equals($config->semanticContext)) {
                $configSet->add(
                    new ATNConfig($config, null, null, $updatedContext),
                    $this->mergeCache,
                );
            } else {
                $configSet->add($config, $this->mergeCache);
            }
        }

        foreach ($configs->elements() as $config) {
            if ($config->alt === 1) {
                continue; // already handled
            }

            /* In the future, this elimination step could be updated to also
            * filter the prediction context for alternatives predicting alt>1
            * (basically a graph subtraction algorithm).
            */
            if (!$config->isPrecedenceFilterSuppressed()) {
                $context = $statesFromAlt1[$config->state->stateNumber] ?? null;

                if ($context !== null && $config->context !== null && $context->equals($config->context)) {
                    continue; // eliminated
                }
            }

            $configSet->add($config, $this->mergeCache);
        }

        return $configSet;
    }

    protected function getReachableTarget(Transition $trans, int $ttype): ?ATNState
    {
        return $trans->matches($ttype, 0, $this->atn->maxTokenType) ? $trans->target : null;
    }

    /**
     * @return array<SemanticContext>|null
     */
    protected function getPredsForAmbigAlts(
        BitSet $ambigAlts,
        ATNConfigSet $configs,
        int $nalts,
    ): ?array {
        /** @var \SplFixedArray<SemanticContext> $altToPred */
        $altToPred = new \SplFixedArray($nalts + 1);

        foreach ($configs->elements() as $c) {
            if ($ambigAlts->contains($c->alt)) {
                $altToPred[$c->alt] = SemanticContext::orContext(
                    $altToPred[$c->alt],
                    $c->semanticContext,
                );
            }
        }

        $nPredAlts = 0;

        for ($i = 1; $i <= $nalts; $i++) {
            $pred = $altToPred[$i];

            if ($pred === null) {
                $altToPred[$i] = SemanticContext::none();
            } elseif ($pred !== SemanticContext::none()) {
                $nPredAlts++;
            }
        }

        if ($nPredAlts === 0) {
            return null;
        }

        /** @var array<SemanticContext> $semanticContexts */
        $semanticContexts = $altToPred->toArray();

        return $semanticContexts;
    }

    /**
     * @param array<SemanticContext> $altToPred
     *
     * @return array<PredPrediction>|null
     */
    protected function getPredicatePredictions(?BitSet $ambigAlts, array $altToPred): ?array
    {
        $pairs = [];
        $containsPredicate = false;
        $count = \count($altToPred);

        for ($i = 1; $i < $count; $i++) {
            $pred = $altToPred[$i];

            // unpredicated is indicated by SemanticContext.NONE
            if ($ambigAlts !== null && $ambigAlts->contains($i)) {
                $pairs[] = new PredPrediction($pred, $i);
            }

            if ($pred !== SemanticContext::none()) {
                $containsPredicate = true;
            }
        }

        if (!$containsPredicate) {
            return null;
        }

        return $pairs;
    }

    /**
     * This method is used to improve the localization of error messages by
     * choosing an alternative rather than throwing a
     * {@see NoViableAltException} in particular prediction scenarios where the
     * {@see ParserATNSimulator::error()} state was reached during ATN simulation.
     *
     * The default implementation of this method uses the following
     * algorithm to identify an ATN configuration which successfully parsed the
     * decision entry rule. Choosing such an alternative ensures that the
     * {@see ParserRuleContext} returned by the calling rule will be complete
     * and valid, and the syntax error will be reported later at a more
     * localized location.
     *
     * - If a syntactically valid path or paths reach the end of the decision rule and
     *    they are semantically valid if predicated, return the min associated alt.
     * - Else, if a semantically invalid but syntactically valid path exist
     *    or paths exist, return the minimum associated alt.
     * - Otherwise, return {@see ATN::INVALID_ALT_NUMBER}.
     *
     * In some scenarios, the algorithm described above could predict an
     * alternative which will result in a {@see FailedPredicateException} in
     * the parser. Specifically, this could occur if the only configuration
     * capable of successfully parsing to the end of the decision rule is
     * blocked by a semantic predicate. By choosing this alternative within
     * {@see ParserATNSimulator::adaptivePredict()} instead of throwing a
     * {@see NoViableAltException}, the resulting {@see FailedPredicateException}
     * in the parser will identify the specific predicate which is preventing
     * the parser from successfully parsing the decision rule, which helps
     * developers identify and correct logic errors in semantic predicates.
     *
     * @param ATNConfigSet      $configs      The ATN configurations which were valid
     *                                        immediately before the
     *                                        {@see ParserATNSimulator::error()}
     *                                        state was reached.
     * @param ParserRuleContext $outerContext The \gamma_0 initial parser context
     *                                        from the paper or the parser stack
     *                                        at the instant before prediction commences.
     *
     * @return int The value to return from {@see ParserATNSimulator::adaptivePredict()},
     *             or {@see ATN::INVALID_ALT_NUMBER} if a suitable alternative
     *             was not identified and {@see ParserATNSimulator::::adaptivePredict()}
     *             should report an error instead.
     */
    protected function getSynValidOrSemInvalidAltThatFinishedDecisionEntryRule(
        ATNConfigSet $configs,
        ParserRuleContext $outerContext,
    ): int {
        /** @var ATNConfigSet $semValidConfigs */
        /** @var ATNConfigSet $semInvalidConfigs */
        [$semValidConfigs, $semInvalidConfigs] = $this->splitAccordingToSemanticValidity($configs, $outerContext);

        $alt = $this->getAltThatFinishedDecisionEntryRule($semValidConfigs);

        if ($alt !== ATN::INVALID_ALT_NUMBER) {
            // semantically/syntactically viable path exists

            return $alt;
        }

        // Is there a syntactically valid path with a failed pred?
        if ($semInvalidConfigs->getLength() > 0) {
            $alt = $this->getAltThatFinishedDecisionEntryRule($semInvalidConfigs);

            if ($alt !== ATN::INVALID_ALT_NUMBER) {
                // syntactically viable path exists

                return $alt;
            }
        }

        return ATN::INVALID_ALT_NUMBER;
    }

    protected function getAltThatFinishedDecisionEntryRule(ATNConfigSet $configs): int
    {
        $alts = new IntervalSet();
        /** @var ATNConfig $c */
        foreach ($configs->elements() as $c) {
            if ($c->getOuterContextDepth() > 0
                || ($c->state instanceof RuleStopState && $c->context !== null && $c->context->hasEmptyPath())) {
                $alts->addOne($c->alt);
            }
        }

        return $alts->length() === 0 ? ATN::INVALID_ALT_NUMBER : $alts->getMinElement();
    }

    /**
     * Walk the list of configurations and split them according to
     * those that have preds evaluating to true/false. If no pred, assume
     * true pred and include in succeeded set. Returns Pair of sets.
     *
     * Create a new set so as not to alter the incoming parameter.
     *
     * Assumption: the input stream has been restored to the starting point
     * prediction, which is where predicates need to evaluate.
     *
     * @return array<ATNConfigSet>
     */
    protected function splitAccordingToSemanticValidity(ATNConfigSet $configs, ParserRuleContext $outerContext): array
    {
        $succeeded = new ATNConfigSet($configs->fullCtx);
        $failed = new ATNConfigSet($configs->fullCtx);

        foreach ($configs->elements() as $c) {
            if ($c->semanticContext !== SemanticContext::none()) {
                $predicateEvaluationResult = $c->semanticContext->eval($this->parser, $outerContext);

                if ($predicateEvaluationResult) {
                    $succeeded->add($c);
                } else {
                    $failed->add($c);
                }
            } else {
                $succeeded->add($c);
            }
        }

        return [$succeeded, $failed];
    }

    /**
     * Look through a list of predicate/alt pairs, returning alts for the
     * pairs that win. A `NONE` predicate indicates an alt containing an
     * unpredicated config which behaves as "always true." If !complete
     * then we stop at the first predicate that evaluates to true. This
     * includes pairs with null predicates.
     *
     * @param array<PredPrediction> $predPredictions
     */
    protected function evalSemanticContextMany(
        array $predPredictions,
        ParserRuleContext $outerContext,
        bool $complete,
    ): BitSet {
        $predictions = new BitSet();

        foreach ($predPredictions as $pair) {
            if ($pair->pred === SemanticContext::none()) {
                $predictions->add($pair->alt);

                if (!$complete) {
                    break;
                }

                continue;
            }

            $fullCtx = false; // in dfa

            $predicateEvaluationResult = $this->evalSemanticContextOne(
                $pair->pred,
                $outerContext,
                $pair->alt,
                $fullCtx,
            );

            if ($predicateEvaluationResult) {
                $predictions->add($pair->alt);

                if (!$complete) {
                    break;
                }
            }
        }

        return $predictions;
    }

    protected function evalSemanticContextOne(
        SemanticContext $pred,
        ParserRuleContext $parserCallStack,
        int $alt,
        bool $fullCtx,
    ): bool {
        return $pred->eval($this->parser, $parserCallStack);
    }

    /**
     * TODO: If we are doing predicates, there is no point in pursuing
     *       closure operations if we reach a DFA state that uniquely predicts
     *       alternative. We will not be caching that DFA state and it is a
     *       waste to pursue the closure. Might have to advance when we do
     *       ambig detection thought :(
     */
    protected function closure(
        ATNConfig $config,
        ATNConfigSet $configs,
        Set $closureBusy,
        bool $collectPredicates,
        bool $fullCtx,
        bool $treatEofAsEpsilon,
    ): void {
        $initialDepth = 0;

        $this->closureCheckingStopState(
            $config,
            $configs,
            $closureBusy,
            $collectPredicates,
            $fullCtx,
            $initialDepth,
            $treatEofAsEpsilon,
        );

        if ($fullCtx && $configs->dipsIntoOuterContext) {
            throw new \LogicException('Error.');
        }
    }

    protected function closureCheckingStopState(
        ATNConfig $config,
        ATNConfigSet $configs,
        Set $closureBusy,
        bool $collectPredicates,
        bool $fullCtx,
        int $depth,
        bool $treatEofAsEpsilon,
    ): void {
        if (self::$traceAtnSimulation) {
            $this->logger->debug('closure({config})', [
                'config' => $config->toString(true),
            ]);
        }

        if ($config->state instanceof RuleStopState) {
            // We hit rule end. If we have context info, use it run thru all possible stack tops in ctx
            $context = $config->context;

            if ($context !== null && !$context->isEmpty()) {
                for ($i = 0; $i < $context->getLength(); $i++) {
                    if ($context->getReturnState($i) === PredictionContext::EMPTY_RETURN_STATE) {
                        if ($fullCtx) {
                            $configs->add(
                                new ATNConfig($config, $config->state, PredictionContext::empty(), null, null),
                                $this->mergeCache,
                            );
                        } else {
                            $this->closure_(
                                $config,
                                $configs,
                                $closureBusy,
                                $collectPredicates,
                                $fullCtx,
                                $depth,
                                $treatEofAsEpsilon,
                            );
                        }

                        continue;
                    }

                    $returnState = $this->atn->states[$context->getReturnState($i)];
                    $newContext = $context->getParent($i);// "pop" return state

                    $c = new ATNConfig(null, $returnState, $newContext, $config->semanticContext, $config->alt);

                    // While we have context to pop back from, we may have
                    // gotten that context AFTER having falling off a rule.
                    // Make sure we track that we are now out of context.
                    //
                    // This assignment also propagates the
                    // isPrecedenceFilterSuppressed() value to the new
                    // configuration.
                    $c->reachesIntoOuterContext = $config->reachesIntoOuterContext;

                    $this->closureCheckingStopState(
                        $c,
                        $configs,
                        $closureBusy,
                        $collectPredicates,
                        $fullCtx,
                        $depth - 1,
                        $treatEofAsEpsilon,
                    );
                }

                return;
            } elseif ($fullCtx) {
                // Reached end of start rule
                $configs->add($config, $this->mergeCache);

                return;
            }
        }

        $this->closure_($config, $configs, $closureBusy, $collectPredicates, $fullCtx, $depth, $treatEofAsEpsilon);
    }

    /**
     * Do the actual work of walking epsilon edges.
     */
    protected function closure_(
        ATNConfig $config,
        ATNConfigSet $configs,
        Set $closureBusy,
        bool $collectPredicates,
        bool $fullCtx,
        int $depth,
        bool $treatEofAsEpsilon,
    ): void {
        $p = $config->state;

        // optimization
        if (!$p->onlyHasEpsilonTransitions()) {
            // make sure to not return here, because EOF transitions can act as
            // both epsilon transitions and non-epsilon transitions.

            $configs->add($config, $this->mergeCache);
        }

        foreach ($p->getTransitions() as $i => $t) {
            if ($i === 0 && $this->canDropLoopEntryEdgeInLeftRecursiveRule($config)) {
                continue;
            }

            $continueCollecting = $collectPredicates && !$t instanceof ActionTransition;
            $c = $this->getEpsilonTarget($config, $t, $continueCollecting, $depth === 0, $fullCtx, $treatEofAsEpsilon);

            if ($c !== null) {
                $newDepth = $depth;

                if ($config->state instanceof RuleStopState) {
                    if ($fullCtx) {
                        throw new \LogicException('Unexpected error.');
                    }

                    // Target fell off end of rule; mark resulting c as having dipped into outer context
                    // We can't get here if incoming config was rule stop and we had context
                    // track how far we dip into outer context. Might
                    // come in handy and we avoid evaluating context dependent
                    // preds if this is > 0.

                    if ($this->dfa !== null && $this->dfa->isPrecedenceDfa()) {
                        if ($t instanceof EpsilonTransition
                            && $t->getOutermostPrecedenceReturn() === $this->dfa->atnStartState->ruleIndex) {
                            $c->setPrecedenceFilterSuppressed(true);
                        }
                    }

                    $c->reachesIntoOuterContext++;

                    if (!$closureBusy->add($c)) {
                        // avoid infinite recursion for right-recursive rules

                        continue;
                    }

                    // TODO: can remove? only care when we add to set per middle of this method
                    $configs->dipsIntoOuterContext = true;
                    $newDepth--;
                } else {
                    if (!$t->isEpsilon() && !$closureBusy->add($c)) {
                        // avoid infinite recursion for EOF* and EOF+

                        continue;
                    }

                    if ($t instanceof RuleTransition) {
                        // latch when newDepth goes negative - once we step out of the entry context we can't return

                        if ($newDepth >= 0) {
                            $newDepth++;
                        }
                    }
                }

                $this->closureCheckingStopState(
                    $c,
                    $configs,
                    $closureBusy,
                    $continueCollecting,
                    $fullCtx,
                    $newDepth,
                    $treatEofAsEpsilon,
                );
            }
        }
    }

    /**
     * Implements first-edge (loop entry) elimination as an optimization
     * during closure operations. See antlr/antlr4#1398.
     *
     * The optimization is to avoid adding the loop entry config when
     * the exit path can only lead back to the same
     * StarLoopEntryState after popping context at the rule end state
     * (traversing only epsilon edges, so we're still in closure, in
     * this same rule).
     *
     * We need to detect any state that can reach loop entry on
     * epsilon w/o exiting rule. We don't have to look at FOLLOW
     * links, just ensure that all stack tops for config refer to key
     * states in LR rule.
     *
     * To verify we are in the right situation we must first check
     * closure is at a StarLoopEntryState generated during LR removal.
     * Then we check that each stack top of context is a return state
     * from one of these cases:
     *
     *     1. 'not' expr, '(' type ')' expr. The return state points at loop entry state
     *     2. expr op expr. The return state is the block end of internal block of (...)*
     *     3. 'between' expr 'and' expr. The return state of 2nd expr reference.
     *        That state points at block end of internal block of (...)*.
     *     4. expr '?' expr ':' expr. The return state points at block end,
     *        which points at loop entry state.
     *
     * If any is true for each stack top, then closure does not add a
     * config to the current config set for edge[0], the loop entry branch.
     *
     * Conditions fail if any context for the current config is:
     *
     *     a. empty (we'd fall out of expr to do a global FOLLOW which could
     *        even be to some weird spot in expr) or,
     *     b. lies outside of expr or,
     *     c. lies within expr but at a state not the BlockEndState
     *        generated during LR removal
     *
     * Do we need to evaluate predicates ever in closure for this case?
     *
     * No. Predicates, including precedence predicates, are only
     * evaluated when computing a DFA start state. I.e., only before
     * the lookahead (but not parser) consumes a token.
     *
     * There are no epsilon edges allowed in LR rule alt blocks or in
     * the "primary" part (ID here). If closure is in
     * StarLoopEntryState any lookahead operation will have consumed a
     * token as there are no epsilon-paths that lead to
     * StarLoopEntryState. We do not have to evaluate predicates
     * therefore if we are in the generated StarLoopEntryState of a LR
     * rule. Note that when making a prediction starting at that
     * decision point, decision d=2, compute-start-state performs
     * closure starting at edges[0], edges[1] emanating from
     * StarLoopEntryState. That means it is not performing closure on
     * StarLoopEntryState during compute-start-state.
     *
     * How do we know this always gives same prediction answer?
     *
     * Without predicates, loop entry and exit paths are ambiguous
     * upon remaining input +b (in, say, a+b). Either paths lead to
     * valid parses. Closure can lead to consuming + immediately or by
     * falling out of this call to expr back into expr and loop back
     * again to StarLoopEntryState to match +b. In this special case,
     * we choose the more efficient path, which is to take the bypass
     * path.
     *
     * The lookahead language has not changed because closure chooses
     * one path over the other. Both paths lead to consuming the same
     * remaining input during a lookahead operation. If the next token
     * is an operator, lookahead will enter the choice block with
     * operators. If it is not, lookahead will exit expr. Same as if
     * closure had chosen to enter the choice block immediately.
     *
     * Closure is examining one config (some loopentrystate, some alt,
     * context) which means it is considering exactly one alt. Closure
     * always copies the same alt to any derived configs.
     *
     * How do we know this optimization doesn't mess up precedence in
     * our parse trees?
     *
     * Looking through expr from left edge of stat only has to confirm
     * that an input, say, a+b+c; begins with any valid interpretation
     * of an expression. The precedence actually doesn't matter when
     * making a decision in stat seeing through expr. It is only when
     * parsing rule expr that we must use the precedence to get the
     * right interpretation and, hence, parse tree.
     *
     * @since 4.6
     */
    protected function canDropLoopEntryEdgeInLeftRecursiveRule(ATNConfig $config): bool
    {
        $p = $config->state;

        /* First check to see if we are in StarLoopEntryState generated during
         * left-recursion elimination. For efficiency, also check if
         * the context has an empty stack case. If so, it would mean
         * global FOLLOW so we can't perform optimization
         * Are we the special loop entry/exit state? or SLL wildcard
         */

        if ($config->context === null) {
            throw new \LogicException('Prediction context cannot be null.');
        }

        if ($p->getStateType() !== ATNState::STAR_LOOP_ENTRY
            || ($p instanceof StarLoopEntryState && !$p->isPrecedenceDecision)
            || $config->context->isEmpty()
            || $config->context->hasEmptyPath()) {
            return false;
        }

        // Require all return states to return back to the same rule that p is in.
        $numCtxs = $config->context->getLength();

        for ($i = 0; $i < $numCtxs; $i++) {
            // For each stack context
            $returnState = $this->atn->states[$config->context->getReturnState($i)];

            if ($returnState->ruleIndex !== $p->ruleIndex) {
                return false;
            }
        }

        $decisionStartState = $p->getTransition(0)->target;

        if (!$decisionStartState instanceof BlockStartState || $decisionStartState->endState === null) {
            throw new \LogicException('Unexpected transition type.');
        }

        $blockEndStateNum = $decisionStartState->endState->stateNumber;
        $blockEndState = $this->atn->states[$blockEndStateNum];

        if (!$blockEndState instanceof BlockEndState) {
            throw new \LogicException('Unexpected transition type.');
        }

        // Verify that the top of each stack context leads to loop entry/exit
        // state through epsilon edges and w/o leaving rule.
        for ($i = 0; $i < $numCtxs; $i++) {
            // For each stack context

            $returnStateNumber = $config->context->getReturnState($i);
            $returnState = $this->atn->states[$returnStateNumber];

            // All states must have single outgoing epsilon edge
            if ($returnState->getNumberOfTransitions() !== 1 || !$returnState->getTransition(0)->isEpsilon()) {
                return false;
            }

            // Look for prefix op case like 'not expr', (' type ')' expr
            $returnStateTarget = $returnState->getTransition(0)->target;

            if ($returnState->getStateType() === ATNState::BLOCK_END && $returnStateTarget->equals($p)) {
                continue;
            }

            // Look for 'expr op expr' or case where expr's return state is block end
            // of (...)* internal block; the block end points to loop back
            // which points to p but we don't need to check that
            if ($returnState->equals($blockEndState)) {
                continue;
            }

            // Look for ternary expr ? expr : expr. The return state points at block end,
            // which points at loop entry state
            if ($returnStateTarget->equals($blockEndState)) {
                continue;
            }

            // Look for complex prefix 'between expr and expr' case where 2nd expr's
            // return state points at block end state of (...)* internal block
            if ($returnStateTarget->getStateType() === ATNState::BLOCK_END
                && $returnStateTarget->getNumberOfTransitions() === 1
                && $returnStateTarget->getTransition(0)->isEpsilon()
                && $returnStateTarget->getTransition(0)->target->equals($p)) {
                continue;
            }

            // anything else ain't conforming
            return false;
        }

        return true;
    }

    public function getRuleName(int $index): string
    {
        if ($index >= 0) {
            return $this->parser->getRuleNames()[$index];
        }

        return '<rule $index>';
    }

    protected function getEpsilonTarget(
        ATNConfig $config,
        Transition $t,
        bool $collectPredicates,
        bool $inContext,
        bool $fullCtx,
        bool $treatEofAsEpsilon,
    ): ?ATNConfig {
        switch ($t->getSerializationType()) {
            case Transition::RULE:
                if (!$t instanceof RuleTransition) {
                    throw new \LogicException('Unexpected transition type.');
                }

                return $this->ruleTransition($config, $t);

            case Transition::PRECEDENCE:
                if (!$t instanceof PrecedencePredicateTransition) {
                    throw new \LogicException('Unexpected transition type.');
                }

                return $this->precedenceTransition($config, $t, $collectPredicates, $inContext, $fullCtx);

            case Transition::PREDICATE:
                if (!$t instanceof PredicateTransition) {
                    throw new \LogicException('Unexpected transition type.');
                }

                return $this->predTransition($config, $t, $collectPredicates, $inContext, $fullCtx);

            case Transition::ACTION:
                if (!$t instanceof ActionTransition) {
                    throw new \LogicException('Unexpected transition type.');
                }

                return $this->actionTransition($config, $t);

            case Transition::EPSILON:
                return new ATNConfig($config, $t->target);

            case Transition::ATOM:
            case Transition::RANGE:
            case Transition::SET:
                // EOF transitions act like epsilon transitions after the first EOF transition is traversed

                if ($treatEofAsEpsilon) {
                    if ($t->matches(Token::EOF, 0, 1)) {
                        return new ATNConfig($config, $t->target);
                    }
                }

                return null;

            default:
                return null;
        }
    }

    protected function actionTransition(ATNConfig $config, ActionTransition $t): ATNConfig
    {
        return new ATNConfig($config, $t->target);
    }

    public function precedenceTransition(
        ATNConfig $config,
        PrecedencePredicateTransition $pt,
        bool $collectPredicates,
        bool $inContext,
        bool $fullCtx,
    ): ?ATNConfig {
        $c = null;

        if ($collectPredicates && $inContext) {
            if ($fullCtx) {
                /* In full context mode, we can evaluate predicates on-the-fly
                 * during closure, which dramatically reduces the size of
                 * the config sets. It also obviates the need to test predicates
                 * later during conflict resolution.
                 */

                $currentPosition = $this->input->getIndex();

                $this->input->seek($this->startIndex);

                $predSucceeds = $this->outerContext !== null ?
                    $pt->getPredicate()->eval($this->parser, $this->outerContext) :
                    false;

                $this->input->seek($currentPosition);

                if ($predSucceeds) {
                    $c = new ATNConfig($config, $pt->target);// no pred context
                }
            } else {
                $newSemCtx = SemanticContext::andContext($config->semanticContext, $pt->getPredicate());
                $c = new ATNConfig($config, $pt->target, null, $newSemCtx);
            }
        } else {
            $c = new ATNConfig($config, $pt->target);
        }

        return $c;
    }

    protected function predTransition(
        ATNConfig $config,
        PredicateTransition $pt,
        bool $collectPredicates,
        bool $inContext,
        bool $fullCtx,
    ): ?ATNConfig {
        $c = null;

        if ($collectPredicates && (!$pt->isCtxDependent || $inContext)) {
            if ($fullCtx) {
                // In full context mode, we can evaluate predicates on-the-fly
                // during closure, which dramatically reduces the size of
                // the config sets. It also obviates the need to test predicates
                // later during conflict resolution.

                $currentPosition = $this->input->getIndex();

                $this->input->seek($this->startIndex);

                $predSucceeds = $this->outerContext !== null ?
                    $pt->getPredicate()->eval($this->parser, $this->outerContext) :
                    false;

                $this->input->seek($currentPosition);

                if ($predSucceeds) {
                    $c = new ATNConfig($config, $pt->target);// no pred context
                }
            } else {
                $newSemCtx = SemanticContext::andContext($config->semanticContext, $pt->getPredicate());
                $c = new ATNConfig($config, $pt->target, null, $newSemCtx);
            }
        } else {
            $c = new ATNConfig($config, $pt->target);
        }

        return $c;
    }

    protected function ruleTransition(ATNConfig $config, RuleTransition $t): ATNConfig
    {
        $returnState = $t->followState;
        $newContext = SingletonPredictionContext::create($config->context, $returnState->stateNumber);

        return new ATNConfig($config, $t->target, $newContext);
    }

    /**
     * Gets a {@see BitSet} containing the alternatives in `configs`
     * which are part of one or more conflicting alternative subsets.
     *
     * @param ATNConfigSet $configs The {@see ATNConfigSet} to analyze.
     *
     * @return BitSet The alternatives in `configs` which are part of one or
     *                more conflicting alternative subsets. If `configs` does
     *                not contain any conflicting subsets, this method returns
     *                an empty {@see BitSet}.
     */
    protected function getConflictingAlts(ATNConfigSet $configs): BitSet
    {
        $altsets = PredictionMode::getConflictingAltSubsets($configs);

        return PredictionMode::getAlts($altsets);
    }

    /**
    Sam pointed out a problem with the previous definition, v3, of
    ambiguous states. If we have another state associated with conflicting
    alternatives, we should keep going. For example, the following grammar

    s : (ID | ID ID?) ';' ;

    When the ATN simulation reaches the state before ';', it has a DFA
    state that looks like: [12|1|[], 6|2|[], 12|2|[]]. Naturally
    12|1|[] and 12|2|[] conflict, but we cannot stop processing this node
    because alternative to has another way to continue, via [6|2|[]].
    The key is that we have a single state that has config's only associated
    with a single alternative, 2, and crucially the state transitions
    among the configurations are all non-epsilon transitions. That means
    we don't consider any conflicts that include alternative 2. So, we
    ignore the conflict between alts 1 and 2. We ignore a set of
    conflicting alts when there is an intersection with an alternative
    associated with a single alt state in the state&rarr;config-list map.

    It's also the case that we might have two conflicting configurations but
    also a 3rd nonconflicting configuration for a different alternative:
    [1|1|[], 1|2|[], 8|3|[]]. This can come about from grammar:

    a : A | A | A B ;

    After matching input A, we reach the stop state for rule A, state 1.
    State 8 is the state right before B. Clearly alternatives 1 and 2
    conflict and no amount of further lookahead will separate the two.
    However, alternative 3 will be able to continue and so we do not
    stop working on this state. In the previous example, we're concerned
    with states associated with the conflicting alternatives. Here alt
    3 is not associated with the conflicting configs, but since we can continue
    looking for input reasonably, I don't declare the state done. We
    ignore a set of conflicting alts when we have an alternative
    that we still need to pursue.
     */
    protected function getConflictingAltsOrUniqueAlt(ATNConfigSet $configs): ?BitSet
    {
        if ($configs->uniqueAlt !== ATN::INVALID_ALT_NUMBER) {
            $conflictingAlts = new BitSet();

            $conflictingAlts->add($configs->uniqueAlt);

            return $conflictingAlts;
        }

        return $configs->getConflictingAlts();
    }

    public function getTokenName(int $t): string
    {
        if ($t === Token::EOF) {
            return 'EOF';
        }

        $vocabulary = $this->parser->getVocabulary();
        $displayName = $vocabulary->getDisplayName($t);

        if ($displayName === (string) $t) {
            return $displayName;
        }

        return \sprintf('%s<%d>', $displayName, $t);
    }

    public function getLookaheadName(TokenStream $input): string
    {
        return $this->getTokenName($input->LA(1));
    }

    protected function noViableAlt(
        TokenStream $input,
        ParserRuleContext $outerContext,
        ?ATNConfigSet $configs,
        int $startIndex,
    ): NoViableAltException {
        return new NoViableAltException(
            $this->parser,
            $input,
            $input->get($startIndex),
            $input->LT(1),
            $configs,
            $outerContext,
        );
    }

    protected static function getUniqueAlt(ATNConfigSet $configs): int
    {
        $alt = ATN::INVALID_ALT_NUMBER;

        foreach ($configs->elements() as $c) {
            if ($alt === ATN::INVALID_ALT_NUMBER) {
                $alt = $c->alt; // found first alt
            } elseif ($c->alt !== $alt) {
                return ATN::INVALID_ALT_NUMBER;
            }
        }

        return $alt;
    }

    /**
     * Add an edge to the DFA, if possible. This method calls
     * {@see ParserATNSimulator::addDFAState()} to ensure the `to` state is
     * present in the DFA. If `from` is `null`, or if `t` is outside the
     * range of edges that can be represented in the DFA tables, this method
     * returns without adding the edge to the DFA.
     *
     * If `to` is `null`, this method returns `null`. Otherwise, this method
     * returns the {@see DFAState} returned by calling
     * {@see ParserATNSimulator::addDFAState()} for the `to` state.
     *
     * @param DFA           $dfa  The DFA
     * @param DFAState|null $from The source state for the edge
     * @param int           $t    The input symbol
     * @param DFAState|null $to   The target state for the edge
     *
     * @return DFAState If `to` is `null` this method returns `null`,
     *                  otherwise this method returns the result of calling
     *                  {@see ParserATNSimulator::addDFAState()} on `to`.
     */
    protected function addDFAEdge(DFA $dfa, ?DFAState $from, int $t, ?DFAState $to): ?DFAState
    {
        if ($to === null) {
            return null;
        }

        $target = $this->addDFAState($dfa, $to);// used existing if possible not incoming

        if ($from === null || $t < -1 || $t > $this->atn->maxTokenType) {
            return $target;
        }

        if ($from->edges === null) {
            $from->edges = new \SplFixedArray($this->atn->maxTokenType + 1 + 1);
        }

        $from->edges[$t + 1] = $target;

        return $target;
    }

    /**
     * Add state `D` to the DFA if it is not already present, and return
     * the actual instance stored in the DFA. If a state equivalent to `D`
     * is already in the DFA, the existing state is returned. Otherwise this
     * method returns `D` after adding it to the DFA.
     *
     * If `D` is {@see ParserATNSimulator::error()}, this method returns
     * {@see ParserATNSimulator::error()} and does not change the DFA.
     *
     * @param DFA      $dfa The dfa
     * @param DFAState $D   The DFA state to add
     *
     * @return DFAState The state stored in the DFA. This will be either
     *                  the existing state if `D` is already in the DFA, or `D`
     *                  itself if the state was not already present.
     */
    protected function addDFAState(DFA $dfa, DFAState $D): DFAState
    {
        if ($D === self::error()) {
            return $D;
        }

        $existing = $dfa->states->get($D);

        if ($existing instanceof DFAState) {
            if (self::$traceAtnSimulation) {
                $this->logger->debug('addDFAState {state} exists', [
                    'state' => $D->__toString(),
                ]);
            }

            return $existing;
        }

        $D->stateNumber = $dfa->states->count();

        if (!$D->configs->isReadOnly()) {
            $D->configs->optimizeConfigs($this);
            $D->configs->setReadonly(true);
        }

        if (self::$traceAtnSimulation) {
            $this->logger->debug('addDFAState new {state}', [
                'state' => $D->__toString(),
            ]);
        }

        $dfa->states->add($D);

        return $D;
    }

    protected function reportAttemptingFullContext(
        DFA $dfa,
        ?BitSet $conflictingAlts,
        ATNConfigSet $configs,
        int $startIndex,
        int $stopIndex,
    ): void {
        $this->parser->getErrorListenerDispatch()->reportAttemptingFullContext(
            $this->parser,
            $dfa,
            $startIndex,
            $stopIndex,
            $conflictingAlts,
            $configs,
        );
    }

    protected function reportContextSensitivity(
        DFA $dfa,
        int $prediction,
        ATNConfigSet $configs,
        int $startIndex,
        int $stopIndex,
    ): void {
        $this->parser->getErrorListenerDispatch()->reportContextSensitivity(
            $this->parser,
            $dfa,
            $startIndex,
            $stopIndex,
            $prediction,
            $configs,
        );
    }

    /**
     * If context sensitive parsing, we know it's ambiguity not conflict.
     */
    protected function reportAmbiguity(
        DFA $dfa,
        DFAState $D,
        int $startIndex,
        int $stopIndex,
        bool $exact,
        ?BitSet $ambigAlts,
        ATNConfigSet $configs,
    ): void {
        $this->parser->getErrorListenerDispatch()->reportAmbiguity(
            $this->parser,
            $dfa,
            $startIndex,
            $stopIndex,
            $exact,
            $ambigAlts,
            $configs,
        );
    }

    public function setPredictionMode(int $mode): void
    {
        $this->mode = $mode;
    }

    public function getPredictionMode(): int
    {
        return $this->mode;
    }

    public function getParser(): Parser
    {
        return $this->parser;
    }
}
