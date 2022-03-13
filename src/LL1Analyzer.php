<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Atn\ATN;
use Antlr\Antlr4\Runtime\Atn\ATNConfig;
use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Atn\States\RuleStopState;
use Antlr\Antlr4\Runtime\Atn\Transitions\AbstractPredicateTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\NotSetTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\RuleTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\Transition;
use Antlr\Antlr4\Runtime\Atn\Transitions\WildcardTransition;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContext;
use Antlr\Antlr4\Runtime\PredictionContexts\SingletonPredictionContext;
use Antlr\Antlr4\Runtime\Utils\BitSet;
use Antlr\Antlr4\Runtime\Utils\Set;

class LL1Analyzer
{
    /**
     * Special value added to the lookahead sets to indicate that we hit
     * a predicate during analysis if `seeThruPreds === false`.
     */
    public const HIT_PRED = Token::INVALID_TYPE;

    public ATN $atn;

    public function __construct(ATN $atn)
    {
        $this->atn = $atn;
    }

    /**
     * Calculates the SLL(1) expected lookahead set for each outgoing transition
     * of an {@see ATNState}. The returned array has one element for each
     * outgoing transition in `s`. If the closure from transition
     * `i` leads to a semantic predicate before matching a symbol, the
     * element at index `i` of the result will be `null`.
     *
     * @param ATNState|null $s The ATN state
     *
     * @return array<IntervalSet|null>|null The expected symbols for
     *                                      each outgoing transition of `s`.
     */
    public function getDecisionLookahead(?ATNState $s): ?array
    {
        if ($s === null) {
            return null;
        }

        $look = [];
        for ($alt = 0; $alt < $s->getNumberOfTransitions(); $alt++) {
            $interval = new IntervalSet();
            $lookBusy = new Set();
            $seeThruPreds = false; // fail to get lookahead upon pred

            $this->lookRecursively(
                $s->getTransition($alt)->target,
                null,
                PredictionContext::empty(),
                $interval,
                $lookBusy,
                new BitSet(),
                $seeThruPreds,
                false,
            );

            // Wipe out lookahead for this alternative if we found nothing
            // or we had a predicate when we !seeThruPreds
            if ($interval->length() === 0 || $interval->contains(self::HIT_PRED)) {
                $look[$alt] = null;
            } else {
                $look[$alt] = $interval;
            }
        }

        return $look;
    }

    /**
     * Compute set of tokens that can follow `s` in the ATN in the
     * specified `context`.
     *
     * If `context` is `null` and the end of the rule containing
     * `s` is reached, {@see Token::EPSILON} is added to the result set.
     * If `context` is not `null` and the end of the outermost rule is
     * reached, {@see Token::EOF} is added to the result set.
     *
     * @param ATNState         $s         The ATN state.
     * @param ATNState|null    $stopState The ATN state to stop at. This can be
     *                                    a {@see BlockEndState} to detect
     *                                    epsilon paths through a closure.
     * @param RuleContext|null $context   The complete parser context, or `null`
     *                                    if the context should be ignored.
     *
     * @return IntervalSet The set of tokens that can follow `s` in the ATN
     *                     in the specified `context`.
     */
    public function look(ATNState $s, ?ATNState $stopState, ?RuleContext $context): IntervalSet
    {
        $r = new IntervalSet();
        $seeThruPreds = true;// ignore preds; get all lookahead

        $lookContext = $context !== null && $s->atn !== null ?
            PredictionContext::fromRuleContext($s->atn, $context) :
            null;

        $this->lookRecursively(
            $s,
            $stopState,
            $lookContext,
            $r,
            new Set(),
            new BitSet(),
            $seeThruPreds,
            true,
        );

        return $r;
    }

    /**
     * Compute set of tokens that can follow `s` in the ATN in the
     * specified `context`.
     *
     * If `context` is `null` and `stopState` or the end of the
     * rule containing `s` is reached, {@see Token::EPSILON} is added to
     * the result set. If `context` is not `null` and `addEOF` is
     * `true` and `stopState` or the end of the outermost rule is
     * reached, {@see Token::EOF} is added to the result set.
     *
     * @param ATNState               $s               The ATN state.
     * @param ATNState|null          $stopState       The ATN state to stop at.
     *                                                This can be a
     *                                                {@see BlockEndState} to
     *                                                detect epsilon paths
     *                                                through a closure.
     * @param PredictionContext|null $context         The outer context, or `null`
     *                                                if the outer context should
     *                                                not be used.
     * @param IntervalSet            $look            The result lookahead set.
     * @param Set                    $lookBusy        A set used for preventing
     *                                                epsilon closures in the ATN
     *                                                from causing a stack overflow.
     *                                                Outside code should pass
     *                                                `new Set<ATNConfig>}` for
     *                                                this argument.
     * @param BitSet                 $calledRuleStack A set used for preventing
     *                                                left recursion in the ATN
     *                                                from causing a stack overflow.
     *                                                Outside code should pass
     *                                                `new BitSet()` for
     *                                                this argument.
     * @param bool                   $seeThruPreds    `true` to true semantic
     *                                                predicates as implicitly
     *                                                `true` and "see through them",
     *                                                otherwise `false` to treat
     *                                                semantic predicates as
     *                                                opaque and add
     *                                                {@see self::HIT_PRED} to
     *                                                the result if one is
     *                                                encountered.
     * @param bool                   $addEOF          Add {@see Token::EOF} to
     *                                                the result if the end of
     *                                                the outermost context is
     *                                                reached. This parameter
     *                                                has no effect if `context`
     *                                                is `null`.
     */
    protected function lookRecursively(
        ATNState $s,
        ?ATNState $stopState,
        ?PredictionContext $context,
        IntervalSet $look,
        Set $lookBusy,
        BitSet $calledRuleStack,
        bool $seeThruPreds,
        bool $addEOF,
    ): void {
        $c = new ATNConfig(null, $s, $context, null, 0);

        if (!$lookBusy->add($c)) {
            return;
        }

        if ($stopState !== null && $s->equals($stopState)) {
            if ($context === null) {
                $look->addOne(Token::EPSILON);

                return;
            }

            if ($context->isEmpty() && $addEOF) {
                $look->addOne(Token::EOF);

                return;
            }
        }

        if ($s instanceof RuleStopState) {
            if ($context === null) {
                $look->addOne(Token::EPSILON);

                return;
            }

            if ($context->isEmpty() && $addEOF) {
                $look->addOne(Token::EOF);

                return;
            }

            if ($context !== PredictionContext::empty()) {
                // run thru all possible stack tops in ctx
                $removed = $calledRuleStack->contains($s->ruleIndex);

                try {
                    $calledRuleStack->remove($s->ruleIndex);
                    for ($i = 0; $i < $context->getLength(); $i++) {
                        $returnState = $this->atn->states[$context->getReturnState($i)];
                        $this->lookRecursively(
                            $returnState,
                            $stopState,
                            $context->getParent($i),
                            $look,
                            $lookBusy,
                            $calledRuleStack,
                            $seeThruPreds,
                            $addEOF,
                        );
                    }
                } finally {
                    if ($removed) {
                        $calledRuleStack->add($s->ruleIndex);
                    }
                }

                return;
            }
        }

        /** @var Transition $t */
        foreach ($s->getTransitions() as $t) {
            if ($t instanceof RuleTransition) {
                if ($calledRuleStack->contains($t->target->ruleIndex)) {
                    continue;
                }

                $newContext = SingletonPredictionContext::create($context, $t->followState->stateNumber);

                try {
                    $calledRuleStack->add($t->target->ruleIndex);
                    $this->lookRecursively(
                        $t->target,
                        $stopState,
                        $newContext,
                        $look,
                        $lookBusy,
                        $calledRuleStack,
                        $seeThruPreds,
                        $addEOF,
                    );
                } finally {
                    $calledRuleStack->remove($t->target->ruleIndex);
                }
            } elseif ($t instanceof AbstractPredicateTransition) {
                if ($seeThruPreds) {
                    $this->lookRecursively(
                        $t->target,
                        $stopState,
                        $context,
                        $look,
                        $lookBusy,
                        $calledRuleStack,
                        $seeThruPreds,
                        $addEOF,
                    );
                } else {
                    $look->addOne(self::HIT_PRED);
                }
            } elseif ($t->isEpsilon()) {
                $this->lookRecursively(
                    $t->target,
                    $stopState,
                    $context,
                    $look,
                    $lookBusy,
                    $calledRuleStack,
                    $seeThruPreds,
                    $addEOF,
                );
            } elseif ($t instanceof WildcardTransition) {
                $look->addRange(Token::MIN_USER_TOKEN_TYPE, $this->atn->maxTokenType);
            } else {
                $set = $t->label();

                if ($set !== null) {
                    if ($t instanceof NotSetTransition) {
                        $set = $set->complement(IntervalSet::fromRange(
                            Token::MIN_USER_TOKEN_TYPE,
                            $this->atn->maxTokenType,
                        ));
                    }

                    if ($set !== null) {
                        $look->addSet($set);
                    }
                }
            }
        }
    }
}
