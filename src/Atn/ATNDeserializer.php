<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Atn\Actions\LexerAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerActionType;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerChannelAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerCustomAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerModeAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerMoreAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerPopModeAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerPushModeAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerSkipAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerTypeAction;
use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Atn\States\BasicBlockStartState;
use Antlr\Antlr4\Runtime\Atn\States\BasicState;
use Antlr\Antlr4\Runtime\Atn\States\BlockEndState;
use Antlr\Antlr4\Runtime\Atn\States\BlockStartState;
use Antlr\Antlr4\Runtime\Atn\States\DecisionState;
use Antlr\Antlr4\Runtime\Atn\States\LoopEndState;
use Antlr\Antlr4\Runtime\Atn\States\PlusBlockStartState;
use Antlr\Antlr4\Runtime\Atn\States\PlusLoopbackState;
use Antlr\Antlr4\Runtime\Atn\States\RuleStartState;
use Antlr\Antlr4\Runtime\Atn\States\RuleStopState;
use Antlr\Antlr4\Runtime\Atn\States\StarBlockStartState;
use Antlr\Antlr4\Runtime\Atn\States\StarLoopbackState;
use Antlr\Antlr4\Runtime\Atn\States\StarLoopEntryState;
use Antlr\Antlr4\Runtime\Atn\States\TokensStartState;
use Antlr\Antlr4\Runtime\Atn\Transitions\ActionTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\AtomTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\EpsilonTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\NotSetTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\PrecedencePredicateTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\PredicateTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\RangeTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\RuleTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\SetTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\Transition;
use Antlr\Antlr4\Runtime\Atn\Transitions\WildcardTransition;
use Antlr\Antlr4\Runtime\IntervalSet;
use Antlr\Antlr4\Runtime\Token;

final class ATNDeserializer
{
    public const SERIALIZED_VERSION = 4;

    private ATNDeserializationOptions $deserializationOptions;

    /** @var array<int> */
    private array $data = [];

    private int $pos = 0;

    public function __construct(?ATNDeserializationOptions $options = null)
    {
        $this->deserializationOptions = $options ?? ATNDeserializationOptions::defaultOptions();
    }

    /**
     * @param array<int> $data
     */
    public function deserialize(array $data): ATN
    {
        $this->data = $data;
        $this->pos = 0;

        $this->checkVersion();
        $atn = $this->readATN();
        $this->readStates($atn);
        $this->readRules($atn);
        $this->readModes($atn);
        $sets = [];
        $this->readSets($sets);
        $this->readEdges($atn, $sets);
        $this->readDecisions($atn);
        $this->readLexerActions($atn);
        $this->markPrecedenceDecisions($atn);
        $this->verifyATN($atn);

        if ($atn->grammarType === ATN::ATN_TYPE_PARSER
            && $this->deserializationOptions->isGenerateRuleBypassTransitions()) {
            $this->generateRuleBypassTransitions($atn);
            // re-verify after modification
            $this->verifyATN($atn);
        }

        return $atn;
    }

    private function checkVersion(): void
    {
        $version = $this->readInt();

        if ($version !== self::SERIALIZED_VERSION) {
            throw new \InvalidArgumentException(\sprintf(
                'Could not deserialize ATN with version %d (expected %d).',
                $version,
                self::SERIALIZED_VERSION,
            ));
        }
    }

    private function readATN(): ATN
    {
        $grammarType = $this->readInt();
        $maxTokenType = $this->readInt();

        return new ATN($grammarType, $maxTokenType);
    }

    private function readStates(ATN $atn): void
    {
        $loopBackStateNumbers = [];
        $endStateNumbers = [];
        $nstates = $this->readInt();

        for ($i=0; $i < $nstates; $i++) {
            $stype = $this->readInt();

            // ignore bad type of states
            if ($stype === ATNState::INVALID_TYPE) {
                $atn->addState(null);

                continue;
            }

            $ruleIndex = $this->readInt();
            $s = $this->stateFactory($stype, $ruleIndex);

            if ($stype === ATNState::LOOP_END) {
                // special case
                $loopBackStateNumber = $this->readInt();

                if (!$s instanceof LoopEndState) {
                    throw new \LogicException('Unexpected ATN State');
                }

                $loopBackStateNumbers[] = [$s, $loopBackStateNumber];
            } elseif ($s instanceof BlockStartState) {
                $endStateNumber = $this->readInt();

                $endStateNumbers[] = [$s, $endStateNumber];
            }

            $atn->addState($s);
        }

        // delay the assignment of loop back and end states until we know all the
        // state instances have been initialized
        foreach ($loopBackStateNumbers as $pair) {
            $pair[0]->loopBackState = $atn->states[$pair[1]];
        }

        foreach ($endStateNumbers as $pair) {
            $endState = $atn->states[$pair[1]];

            if (!$endState instanceof BlockEndState) {
                throw new \LogicException('Unexpected ATN State');
            }

            $pair[0]->endState = $endState;
        }

        $numNonGreedyStates = $this->readInt();

        for ($j=0; $j < $numNonGreedyStates; $j++) {
            $decisionState = $atn->states[$this->readInt()];

            if (!$decisionState instanceof DecisionState) {
                throw new \LogicException('Unexpected ATN State');
            }

            $decisionState->nonGreedy = true;
        }

        $numPrecedenceStates = $this->readInt();

        for ($j=0; $j < $numPrecedenceStates; $j++) {
            $ruleStartState = $atn->states[$this->readInt()];

            if (!$ruleStartState instanceof RuleStartState) {
                throw new \LogicException('Unexpected ATN State');
            }

            $ruleStartState->isLeftRecursiveRule = true;
        }
    }

    private function readRules(ATN $atn): void
    {
        $nRules = $this->readInt();

        $atn->ruleToTokenType = [];
        $atn->ruleToStartState = [];
        for ($i=0; $i < $nRules; $i++) {
            $s = $this->readInt();
            $startState = $atn->states[$s];

            if (!$startState instanceof RuleStartState) {
                throw new \LogicException('Unexpected ATN State');
            }

            $atn->ruleToStartState[$i] = $startState;

            if ($atn->grammarType === ATN::ATN_TYPE_LEXER) {
                $tokenType = $this->readInt();
                $atn->ruleToTokenType[$i] = $tokenType;
            }
        }

        $atn->ruleToStopState = [];
        foreach ($atn->states as $state) {
            if (!$state instanceof RuleStopState) {
                continue;
            }

            $atn->ruleToStopState[$state->ruleIndex] = $state;
            $atn->ruleToStartState[$state->ruleIndex]->stopState = $state;
        }
    }

    private function readModes(ATN $atn): void
    {
        $nmodes = $this->readInt();

        for ($i=0; $i < $nmodes; $i++) {
            $tokensStartState = $atn->states[$this->readInt()];

            if (!$tokensStartState instanceof TokensStartState) {
                throw new \LogicException('Unexpected ATN State');
            }

            $atn->modeToStartState[] = $tokensStartState;
        }
    }

    /**
     * @param array<IntervalSet> $sets
     */
    private function readSets(array &$sets): void
    {
        $m = $this->readInt();

        for ($i=0; $i < $m; $i++) {
            $iset = new IntervalSet();

            $sets[] = $iset;
            $n = $this->readInt();
            $containsEof = $this->readInt();

            if ($containsEof !== 0) {
                $iset->addOne(-1);
            }

            for ($j=0; $j < $n; $j++) {
                $i1 = $this->readInt();
                $i2 = $this->readInt();
                $iset->addRange($i1, $i2);
            }
        }
    }

    /**
     * @param array<IntervalSet> $sets
     */
    private function readEdges(ATN $atn, array &$sets): void
    {
        $nEdges = $this->readInt();

        for ($i=0; $i < $nEdges; $i++) {
            $src = $this->readInt();
            $trg = $this->readInt();
            $ttype = $this->readInt();
            $arg1 = $this->readInt();
            $arg2 = $this->readInt();
            $arg3 = $this->readInt();
            $trans = $this->edgeFactory($atn, $ttype, $src, $trg, $arg1, $arg2, $arg3, $sets);
            $srcState = $atn->states[$src];
            $srcState->addTransition($trans);
        }

        // edges for rule stop states can be derived, so they aren't serialized
        foreach ($atn->states as $state) {
            foreach ($state->getTransitions() as $t) {
                if (!$t instanceof RuleTransition) {
                    continue;
                }

                $outermostPrecedenceReturn = -1;
                if ($atn->ruleToStartState[$t->target->ruleIndex]->isLeftRecursiveRule) {
                    if ($t->precedence === 0) {
                        $outermostPrecedenceReturn = $t->target->ruleIndex;
                    }
                }

                $trans = new EpsilonTransition($t->followState, $outermostPrecedenceReturn);
                $atn->ruleToStopState[$t->target->ruleIndex]->addTransition($trans);
            }
        }

        foreach ($atn->states as $state) {
            if ($state instanceof BlockStartState) {
                // we need to know the end state to set its start state
                if ($state->endState === null) {
                    throw new \LogicException('Unexpected null EndState.');
                }

                // block end states can only be associated to a single block start state
                if ($state->endState->startState !== null) {
                    throw new \LogicException('Unexpected null StartState.');
                }

                $state->endState->startState = $state;
            }

            if ($state instanceof PlusLoopbackState) {
                foreach ($state->getTransitions() as $t) {
                    $target = $t->target;

                    if ($target instanceof PlusBlockStartState) {
                        $target->loopBackState = $state;
                    }
                }
            } elseif ($state instanceof StarLoopbackState) {
                foreach ($state->getTransitions() as $t) {
                    $target = $t->target;

                    if ($target instanceof StarLoopEntryState) {
                        $target->loopBackState = $state;
                    }
                }
            }
        }
    }

    private function readDecisions(ATN $atn): void
    {
        $decisions = $this->readInt();

        for ($i = 0; $i < $decisions; $i++) {
            $s = $this->readInt();
            /** @var DecisionState $decState */
            $decState = $atn->states[$s];

            $atn->decisionToState[] = $decState;

            $decState->decision = $i;
        }
    }

    private function readLexerActions(ATN $atn): void
    {
        if ($atn->grammarType === ATN::ATN_TYPE_LEXER) {
            $count = $this->readInt();

            $atn->lexerActions = [];
            for ($i = 0; $i < $count; $i++) {
                $actionType = $this->readInt();
                $data1 = $this->readInt();
                $data2 = $this->readInt();
                $lexerAction = $this->lexerActionFactory($actionType, $data1, $data2);
                $atn->lexerActions[$i] = $lexerAction;
            }
        }
    }

    private function generateRuleBypassTransitions(ATN $atn): void
    {
        $count = \count($atn->ruleToStartState);

        for ($i = 0; $i < $count; $i++) {
            $atn->ruleToTokenType[$i] = $atn->maxTokenType + $i + 1;
        }

        for ($i = 0; $i < $count; $i++) {
            $this->generateRuleBypassTransition($atn, $i);
        }
    }

    private function generateRuleBypassTransition(ATN $atn, int $idx): void
    {
        $bypassStart = new BasicBlockStartState();
        $bypassStart->ruleIndex = $idx;
        $atn->addState($bypassStart);

        $bypassStop = new BlockEndState();
        $bypassStop->ruleIndex = $idx;
        $atn->addState($bypassStop);

        $bypassStart->endState = $bypassStop;
        $atn->defineDecisionState($bypassStart);

        $bypassStop->startState = $bypassStart;

        $excludeTransition = null;
        if ($atn->ruleToStartState[$idx]->isLeftRecursiveRule) {
            // wrap from the beginning of the rule to the StarLoopEntryState
            $endState = null;

            foreach ($atn->states as $state) {
                if ($this->stateIsEndStateFor($state, $idx) !== null) {
                    $endState = $state;

                    if (!$state instanceof LoopEndState) {
                        throw new \LogicException('Unexpected state type.');
                    }

                    if ($state->loopBackState === null) {
                        throw new \LogicException('Unexpected null loop back state.');
                    }

                    $excludeTransition = $state->loopBackState->getTransition(0);

                    break;
                }
            }

            if ($excludeTransition === null) {
                throw new \LogicException('Couldn\'t identify final state of the precedence rule prefix section.');
            }
        } else {
            $endState = $atn->ruleToStopState[$idx];
        }

        // all non-excluded transitions that currently target end state need to target blockEnd instead
        // TODO:looks like a bug
        foreach ($atn->states as $state) {
            foreach ($state->getTransitions() as $transition) {
                if ($excludeTransition !== null && $transition->equals($excludeTransition)) {
                    continue;
                }

                if ($endState !== null && $transition->target->equals($endState)) {
                    $transition->target = $bypassStop;
                }
            }
        }

        // all transitions leaving the rule start state need to leave blockStart instead
        $ruleToStartState = $atn->ruleToStartState[$idx];
        $count = $ruleToStartState->getNumberOfTransitions();

        while ($count > 0) {
            $bypassStart->addTransition($ruleToStartState->getTransition($count-1));
            $ruleToStartState->setTransitions(\array_slice($ruleToStartState->getTransitions(), -1));
        }

        // link the new states
        $atn->ruleToStartState[$idx]->addTransition(new EpsilonTransition($bypassStart));

        if ($endState === null) {
            throw new \LogicException('Unexpected null end state.');
        }

        $bypassStop->addTransition(new EpsilonTransition($endState));

        $matchState = new BasicState();
        $atn->addState($matchState);
        $matchState->addTransition(new AtomTransition($bypassStop, $atn->ruleToTokenType[$idx] ?? 0));
        $bypassStart->addTransition(new EpsilonTransition($matchState));
    }

    private function stateIsEndStateFor(ATNState $state, int $idx): ?ATNState
    {
        if ($state->ruleIndex !== $idx) {
            return null;
        }

        if (!$state instanceof StarLoopEntryState) {
            return null;
        }

        $maybeLoopEndState = $state->getTransition($state->getNumberOfTransitions() - 1)->target;

        if (!$maybeLoopEndState instanceof LoopEndState) {
            return null;
        }

        if ($maybeLoopEndState->epsilonOnlyTransitions
            && $maybeLoopEndState->getTransition(0)->target instanceof RuleStopState) {
            return $state;
        }

        return null;
    }

    /**
     * Analyze the {@see StarLoopEntryState} states in the specified ATN to set
     * the {@see StarLoopEntryState::$isPrecedenceDecision} field to the correct
     * value.
     *
     * @param ATN $atn The ATN.
     */
    private function markPrecedenceDecisions(ATN $atn): void
    {
        foreach ($atn->states as $state) {
            if (!$state instanceof StarLoopEntryState) {
                continue;
            }

            // We analyze the ATN to determine if this ATN decision state is the
            // decision for the closure block that determines whether a
            // precedence rule should continue or complete.
            if ($atn->ruleToStartState[$state->ruleIndex]->isLeftRecursiveRule) {
                $maybeLoopEndState = $state->getTransition($state->getNumberOfTransitions() - 1)->target;

                if ($maybeLoopEndState instanceof LoopEndState) {
                    if ($maybeLoopEndState->epsilonOnlyTransitions
                        && $maybeLoopEndState->getTransition(0)->target instanceof RuleStopState) {
                        $state->isPrecedenceDecision = true;
                    }
                }
            }
        }
    }

    private function verifyATN(ATN $atn): void
    {
        if (!$this->deserializationOptions->isVerifyATN()) {
            return;
        }

        // verify assumptions
        foreach ($atn->states as $state) {
            $this->checkCondition($state->epsilonOnlyTransitions || $state->getNumberOfTransitions() <= 1);

            switch (true) {
                case $state instanceof PlusBlockStartState:
                    $this->checkCondition($state->loopBackState !== null);

                    break;

                case $state instanceof StarLoopEntryState:
                    $this->checkCondition($state->loopBackState !== null);
                    $this->checkCondition($state->getNumberOfTransitions() === 2);

                    if ($state->getTransition(0)->target instanceof StarBlockStartState) {
                        $this->checkCondition($state->getTransition(1)->target instanceof LoopEndState);
                        $this->checkCondition(!$state->nonGreedy);
                    } elseif ($state->getTransition(0)->target instanceof LoopEndState) {
                        $this->checkCondition($state->getTransition(1)->target instanceof StarBlockStartState);
                        $this->checkCondition($state->nonGreedy);
                    } else {
                        throw new \InvalidArgumentException('IllegalState');
                    }

                    break;

                case $state instanceof StarLoopbackState:
                    $this->checkCondition($state->getNumberOfTransitions() === 1);
                    $this->checkCondition($state->getTransition(0)->target instanceof StarLoopEntryState);

                    break;

                case $state instanceof LoopEndState:
                    $this->checkCondition($state->loopBackState !== null);

                    break;

                case $state instanceof RuleStartState:
                    $this->checkCondition($state->stopState !== null);

                    break;

                case $state instanceof BlockStartState:
                    $this->checkCondition($state->endState !== null);

                    break;

                case $state instanceof BlockEndState:
                    $this->checkCondition($state->startState !== null);

                    break;

                case $state instanceof DecisionState:
                    $this->checkCondition($state->getNumberOfTransitions() <= 1 || $state->decision >= 0);

                    break;

                default:
                    $this->checkCondition($state->getNumberOfTransitions() <= 1 || $state instanceof RuleStopState);
            }
        }
    }

    private function checkCondition(?bool $condition, string $message = 'IllegalState'): void
    {
        if ($condition === null) {
            throw new \InvalidArgumentException($message);
        }
    }

    private function readInt(): int
    {
        return $this->data[$this->pos++];
    }

    /**
     * @param array<IntervalSet> $sets
     */
    private function edgeFactory(
        ATN $atn,
        int $type,
        int $src,
        int $trg,
        int $arg1,
        int $arg2,
        int $arg3,
        array $sets,
    ): Transition {
        $target = $atn->states[$trg];

        switch ($type) {
            case Transition::EPSILON:
                return new EpsilonTransition($target);

            case Transition::RANGE:
                return $arg3 !== 0 ?
                    new RangeTransition($target, Token::EOF, $arg2) :
                    new RangeTransition($target, $arg1, $arg2);

            case Transition::RULE:
                $ruleStart = $atn->states[$arg1];

                if (!$ruleStart instanceof RuleStartState) {
                    throw new \LogicException('Unexpected transition type.');
                }

                return new RuleTransition($ruleStart, $arg2, $arg3, $target);

            case Transition::PREDICATE:
                return new PredicateTransition($target, $arg1, $arg2, $arg3 !== 0);

            case Transition::PRECEDENCE:
                return new PrecedencePredicateTransition($target, $arg1);

            case Transition::ATOM:
                return $arg3 !== 0 ? new AtomTransition($target, Token::EOF) : new AtomTransition($target, $arg1);

            case Transition::ACTION:
                return new ActionTransition($target, $arg1, $arg2, $arg3 !== 0);

            case Transition::SET:
                return new SetTransition($target, $sets[$arg1]);

            case Transition::NOT_SET:
                return new NotSetTransition($target, $sets[$arg1]);

            case Transition::WILDCARD:
                return new WildcardTransition($target);

            default:
                throw new \InvalidArgumentException(\sprintf(
                    'The specified transition type: %d is not valid.',
                    $type,
                ));
        }
    }

    private function stateFactory(int $type, int $ruleIndex): ?ATNState
    {
        switch ($type) {
            case ATNState::INVALID_TYPE:
                return null;

            case ATNState::BASIC:
                $s = new BasicState();

                break;

            case ATNState::RULE_START:
                $s = new RuleStartState();

                break;

            case ATNState::BLOCK_START:
                $s = new BasicBlockStartState();

                break;

            case ATNState::PLUS_BLOCK_START:
                $s = new PlusBlockStartState();

                break;

            case ATNState::STAR_BLOCK_START:
                $s = new StarBlockStartState();

                break;

            case ATNState::TOKEN_START:
                $s = new TokensStartState();

                break;

            case ATNState::RULE_STOP:
                $s = new RuleStopState();

                break;

            case ATNState::BLOCK_END:
                $s = new BlockEndState();

                break;

            case ATNState::STAR_LOOP_BACK:
                $s = new StarLoopbackState();

                break;

            case ATNState::STAR_LOOP_ENTRY:
                $s = new StarLoopEntryState();

                break;

            case ATNState::PLUS_LOOP_BACK:
                $s = new PlusLoopbackState();

                break;

            case ATNState::LOOP_END:
                $s = new LoopEndState();

                break;

            default:
                throw new \InvalidArgumentException(\sprintf(
                    'The specified state type %d is not valid.',
                    $type,
                ));
        }

        $s->ruleIndex = $ruleIndex;

        return $s;
    }

    private function lexerActionFactory(int $type, int $data1, int $data2): LexerAction
    {
        switch ($type) {
            case LexerActionType::CHANNEL:
                return new LexerChannelAction($data1);

            case LexerActionType::CUSTOM:
                return new LexerCustomAction($data1, $data2);

            case LexerActionType::MODE:
                return new LexerModeAction($data1);

            case LexerActionType::MORE:
                return LexerMoreAction::instance();

            case LexerActionType::POP_MODE:
                return LexerPopModeAction::instance();

            case LexerActionType::PUSH_MODE:
                return new LexerPushModeAction($data1);

            case LexerActionType::SKIP:
                return LexerSkipAction::instance();

            case LexerActionType::TYPE:
                return new LexerTypeAction($data1);

            default:
                throw new \InvalidArgumentException(\sprintf(
                    'The specified lexer action type %d is not valid.',
                    $type,
                ));
        }
    }
}
