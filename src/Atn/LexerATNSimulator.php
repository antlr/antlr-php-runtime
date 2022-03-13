<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Atn\States\RuleStopState;
use Antlr\Antlr4\Runtime\Atn\Transitions\ActionTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\PredicateTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\RuleTransition;
use Antlr\Antlr4\Runtime\Atn\Transitions\Transition;
use Antlr\Antlr4\Runtime\CharStream;
use Antlr\Antlr4\Runtime\Dfa\DFA;
use Antlr\Antlr4\Runtime\Dfa\DFAState;
use Antlr\Antlr4\Runtime\Error\Exceptions\LexerNoViableAltException;
use Antlr\Antlr4\Runtime\IntStream;
use Antlr\Antlr4\Runtime\Lexer;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContext;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContextCache;
use Antlr\Antlr4\Runtime\PredictionContexts\SingletonPredictionContext;
use Antlr\Antlr4\Runtime\Token;
use Antlr\Antlr4\Runtime\Utils\StringUtils;

class LexerATNSimulator extends ATNSimulator
{
    public const MIN_DFA_EDGE = 0;
    public const MAX_DFA_EDGE = 127; // forces unicode to stay in ATN
    private const NEW_LINE_CODE = 10;

    protected ?Lexer $recog = null;

    /**
     * The current token's starting index into the character stream.
     * Shared across DFA to ATN simulation in case the ATN fails and the
     * DFA did not have a previous accept state. In this case, we use the
     * ATN-generated exception object.
     */
    protected int $startIndex = -1;

    /**
     * The line number 1..n within the input.
     */
    protected int $line = 1;

    /**
     * The index of the character relative to the beginning of the line 0..n-1.
     */
    protected int $charPositionInLine = 0;

    /** @var array<DFA> */
    public array $decisionToDFA = [];

    protected int $mode = Lexer::DEFAULT_MODE;

    /**
     * Used during DFA/ATN exec to record the most recent accept configuration info.
     */
    protected SimState $prevAccept;

    /**
     * @param array<DFA> $decisionToDFA
     */
    public function __construct(
        ?Lexer $recog,
        ATN $atn,
        array $decisionToDFA,
        PredictionContextCache $sharedContextCache,
    ) {
        parent::__construct($atn, $sharedContextCache);

        $this->decisionToDFA = $decisionToDFA;
        $this->recog = $recog;
        $this->prevAccept = new SimState();
    }

    public function copyState(LexerATNSimulator $simulator): void
    {
        $this->charPositionInLine = $simulator->charPositionInLine;
        $this->line = $simulator->line;
        $this->mode = $simulator->mode;
        $this->startIndex = $simulator->startIndex;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function setLine(int $line): void
    {
        $this->line = $line;
    }

    public function getCharPositionInLine(): int
    {
        return $this->charPositionInLine;
    }

    public function setCharPositionInLine(int $charPositionInLine): void
    {
        $this->charPositionInLine = $charPositionInLine;
    }

    /**
     * @throws LexerNoViableAltException
     */
    public function match(CharStream $input, int $mode): int
    {
        static $match_calls;

        if ($match_calls === null) {
            $match_calls = 0;
        }

        $match_calls++;

        $this->mode = $mode;
        $mark = $input->mark();

        try {
            $this->startIndex = $input->getIndex();
            $this->prevAccept->reset();

            $dfa = $this->decisionToDFA[$mode];

            if ($dfa->s0 === null) {
                return $this->matchATN($input);
            }

            return $this->execATN($input, $dfa->s0);
        } finally {
            $input->release($mark);
        }
    }

    public function reset(): void
    {
        $this->prevAccept->reset();
        $this->startIndex = -1;
        $this->line = 1;
        $this->charPositionInLine = 0;
        $this->mode = Lexer::DEFAULT_MODE;
    }

    /**
     * @throws LexerNoViableAltException
     */
    protected function matchATN(CharStream $input): int
    {
        $startState = $this->atn->modeToStartState[$this->mode];

        $s0_closure = $this->computeStartState($input, $startState);
        $suppressEdge = $s0_closure->hasSemanticContext;
        $s0_closure->hasSemanticContext = false;

        $next = $this->addDFAState($s0_closure);

        if (!$suppressEdge) {
            $this->decisionToDFA[$this->mode]->s0 = $next;
        }

        return $this->execATN($input, $next);
    }

    /**
     * @throws LexerNoViableAltException
     */
    protected function execATN(CharStream $input, DFAState $ds0): int
    {
        if ($ds0->isAcceptState) {
            // allow zero-length tokens
            $this->captureSimState($this->prevAccept, $input, $ds0);
        }

        $t = $input->LA(1);
        $s = $ds0; // s is current/from DFA state

        while (true) {
            // As we move src->trg, src->trg, we keep track of the previous trg to
            // avoid looking up the DFA state again, which is expensive.
            // If the previous target was already part of the DFA, we might
            // be able to avoid doing a reach operation upon t. If s!=null,
            // it means that semantic predicates didn't prevent us from
            // creating a DFA state. Once we know s!=null, we check to see if
            // the DFA state has an edge already for t. If so, we can just reuse
            // it's configuration set; there's no point in re-computing it.
            // This is kind of like doing DFA simulation within the ATN
            // simulation because DFA simulation is really just a way to avoid
            // computing reach/closure sets. Technically, once we know that
            // we have a previously added DFA state, we could jump over to
            // the DFA simulator. But, that would mean popping back and forth
            // a lot and making things more complicated algorithmically.
            // This optimization makes a lot of sense for loops within DFA.
            // A character will take us back to an existing DFA state
            // that already has lots of edges out of it. e.g., .* in comments.
            // print("Target for:" + str(s) + " and:" + str(t))
            $target = $this->getExistingTargetState($s, $t);

            if ($target === null) {
                $target = $this->computeTargetState($input, $s, $t);
            }

            if ($target === ATNSimulator::error()) {
                break;
            }

            // If this is a consumable input element, make sure to consume before
            // capturing the accept state so the input index, line, and char
            // position accurately reflect the state of the interpreter at the
            // end of the token.
            if ($t !== Token::EOF) {
                $this->consume($input);
            }

            if ($target->isAcceptState) {
                $this->captureSimState($this->prevAccept, $input, $target);
                if ($t === Token::EOF) {
                    break;
                }
            }

            $t = $input->LA(1);
            $s = $target; // flip; current DFA target becomes new src/from state
        }

        return $this->failOrAccept($this->prevAccept, $input, $s->configs, $t);
    }

    /**
     * Get an existing target state for an edge in the DFA. If the target state
     * for the edge has not yet been computed or is otherwise not available,
     * this method returns `null`.
     *
     * @param DFAState $s The current DFA state
     * @param int      $t The next input symbol
     *
     * @return DFAState|null The existing target DFA state for the given input symbol
     *                       `t`, or `null` if the target state for this edg
     *                       is not already cached.
     */
    protected function getExistingTargetState(DFAState $s, int $t): ?DFAState
    {
        if ($s->edges === null || $t < self::MIN_DFA_EDGE || $t > self::MAX_DFA_EDGE) {
            return null;
        }

        return $s->edges[$t - self::MIN_DFA_EDGE] ?? null;
    }

    /**
     * Compute a target state for an edge in the DFA, and attempt to add the
     * computed state and corresponding edge to the DFA.
     *
     * @param CharStream $input The input stream
     * @param DFAState   $s     The current DFA state
     * @param int        $t     The next input symbol
     *
     * @return DFAState The computed target DFA state for the given input symbol
     *                  `t`. If `t` does not lead to a valid DFA state, this
     *                  method returns {@see LexerATNSimulator::ERROR}.
     */
    protected function computeTargetState(CharStream $input, DFAState $s, int $t): DFAState
    {
        $reach = new OrderedATNConfigSet();

        // if we don't find an existing DFA state
        // Fill reach starting from closure, following t transitions
        $this->getReachableConfigSet($input, $s->configs, $reach, $t);

        if (\count($reach->elements()) === 0) {
            // we got nowhere on t from s
            if (!$reach->hasSemanticContext) {
                // we got nowhere on t, don't throw out this knowledge; it'd
                // cause a failover from DFA later.
                $this->addDFAEdge($s, $t, ATNSimulator::error());
            }

            // stop when we can't match any more char
            return ATNSimulator::error();
        }

        // Add an edge from s to target DFA found/created for reach
        return $this->addDFAEdgeATNConfigSet($s, $t, $reach);
    }

    /**
     * @throws LexerNoViableAltException
     */
    protected function failOrAccept(SimState $prevAccept, CharStream $input, ATNConfigSet $reach, int $t): int
    {
        if ($this->prevAccept->getDfaState() !== null) {
            $dfaState = $prevAccept->getDfaState();

            if ($dfaState === null) {
                throw new \LogicException('Unexpected null DFA State.');
            }

            $lexerActionExecutor = $dfaState->lexerActionExecutor;

            $this->accept(
                $input,
                $lexerActionExecutor,
                $this->startIndex,
                $prevAccept->getIndex(),
                $prevAccept->getLine(),
                $prevAccept->getCharPos(),
            );

            return $dfaState->prediction;
        }

        // if no accept and EOF is first char, return EOF
        if ($t === IntStream::EOF && $input->getIndex() === $this->startIndex) {
            return Token::EOF;
        }

        if ($this->recog === null) {
            throw new \LogicException('Unexpected null recognizer.');
        }

        throw new LexerNoViableAltException($this->recog, $input, $this->startIndex, $reach);
    }

    /**
     * Given a starting configuration set, figure out all ATN configurations
     * we can reach upon input `t`. Parameter `reach` is a return parameter.
     */
    protected function getReachableConfigSet(
        CharStream $input,
        ATNConfigSet $closure,
        ATNConfigSet $reach,
        int $t,
    ): void {
        // this is used to skip processing for configs which have a lower priority
        // than a config that already reached an accept state for the same rule
        $skipAlt = ATN::INVALID_ALT_NUMBER;

        foreach ($closure->elements() as $cfg) {
            if (!$cfg instanceof LexerATNConfig) {
                throw new \LogicException('Unexpected config type.');
            }

            $currentAltReachedAcceptState = ($cfg->alt === $skipAlt);

            if ($currentAltReachedAcceptState && $cfg->isPassedThroughNonGreedyDecision()) {
                continue;
            }

            foreach ($cfg->state->getTransitions() as $trans) {
                $target = $this->getReachableTarget($trans, $t);

                if ($target !== null) {
                    $lexerExecutor = $cfg->getLexerActionExecutor();

                    if ($lexerExecutor !== null) {
                        $lexerExecutor = $lexerExecutor->fixOffsetBeforeMatch($input->getIndex() - $this->startIndex);
                    }

                    $treatEofAsEpsilon = ($t === Token::EOF);
                    $config = new LexerATNConfig($cfg, $target, null, $lexerExecutor);

                    if ($this->closure(
                        $input,
                        $config,
                        $reach,
                        $currentAltReachedAcceptState,
                        true,
                        $treatEofAsEpsilon,
                    )) {
                        // any remaining configs for this alt have a lower priority
                        // than the one that just reached an accept state.
                        $skipAlt = $cfg->alt;
                    }
                }
            }
        }
    }

    protected function accept(
        CharStream $input,
        ?LexerActionExecutor $lexerActionExecutor,
        int $startIndex,
        int $index,
        int $line,
        int $charPos,
    ): void {
        // seek to after last char in token
        $input->seek($index);
        $this->line = $line;
        $this->charPositionInLine = $charPos;

        if ($lexerActionExecutor !== null && $this->recog !== null) {
            $lexerActionExecutor->execute($this->recog, $input, $startIndex);
        }
    }

    protected function getReachableTarget(Transition $trans, int $t): ?ATNState
    {
        if ($trans->matches($t, Lexer::MIN_CHAR_VALUE, Lexer::MAX_CHAR_VALUE)) {
            return $trans->target;
        }

        return null;
    }

    protected function computeStartState(CharStream $input, ATNState $p): OrderedATNConfigSet
    {
        $initialContext = PredictionContext::empty();
        $configs = new OrderedATNConfigSet();

        foreach ($p->getTransitions() as $i => $t) {
            $target = $t->target;
            $cfg = new LexerATNConfig(null, $target, $initialContext, null, $i + 1);
            $this->closure($input, $cfg, $configs, false, false, false);
        }

        return $configs;
    }

    /**
     * Since the alternatives within any lexer decision are ordered by
     * preference, this method stops pursuing the closure as soon as an accept
     * state is reached. After the first accept state is reached by depth-first
     * search from `config`, all other (potentially reachable) states for
     * this rule would have a lower priority.
     *
     * @return bool `true` if an accept state is reached, otherwise `false`.
     */
    protected function closure(
        CharStream $input,
        LexerATNConfig $config,
        ATNConfigSet $configs,
        bool $currentAltReachedAcceptState,
        bool $speculative,
        bool $treatEofAsEpsilon,
    ): bool {
        if ($config->state instanceof States\RuleStopState) {
            if ($config->context === null || $config->context->hasEmptyPath()) {
                if ($config->context === null || $config->context->isEmpty()) {
                    $configs->add($config);

                    return true;
                }

                $configs->add(new LexerATNConfig($config, $config->state, PredictionContext::empty()));
                $currentAltReachedAcceptState = true;
            }

            if (!$config->context->isEmpty()) {
                for ($i = 0; $i < $config->context->getLength(); $i++) {
                    if ($config->context->getReturnState($i) !== PredictionContext::EMPTY_RETURN_STATE) {
                        $newContext = $config->context->getParent($i);// "pop" return state
                        $returnState = $this->atn->states[$config->context->getReturnState($i)];
                        $cfg = new LexerATNConfig($config, $returnState, $newContext);
                        $currentAltReachedAcceptState = $this->closure(
                            $input,
                            $cfg,
                            $configs,
                            $currentAltReachedAcceptState,
                            $speculative,
                            $treatEofAsEpsilon,
                        );
                    }
                }
            }

            return $currentAltReachedAcceptState;
        }

        // optimization
        if (!$config->state->epsilonOnlyTransitions) {
            if (!$currentAltReachedAcceptState || !$config->isPassedThroughNonGreedyDecision()) {
                $configs->add($config);
            }
        }

        foreach ($config->state->getTransitions() as $trans) {
            $cfg = $this->getEpsilonTarget($input, $config, $trans, $configs, $speculative, $treatEofAsEpsilon);

            if ($cfg !== null) {
                $currentAltReachedAcceptState = $this->closure(
                    $input,
                    $cfg,
                    $configs,
                    $currentAltReachedAcceptState,
                    $speculative,
                    $treatEofAsEpsilon,
                );
            }
        }

        return $currentAltReachedAcceptState;
    }

    /**
     * side-effect: can alter configs.hasSemanticContext
     */
    protected function getEpsilonTarget(
        CharStream $input,
        LexerATNConfig $config,
        Transition $t,
        ATNConfigSet $configs,
        bool $speculative,
        bool $treatEofAsEpsilon,
    ): ?LexerATNConfig {
        $cfg = null;

        switch ($t->getSerializationType()) {
            case Transition::RULE:
                if (!$t instanceof RuleTransition) {
                    throw new \LogicException('Unexpected transition type.');
                }

                $newContext = SingletonPredictionContext::create($config->context, $t->followState->stateNumber);
                $cfg = new LexerATNConfig($config, $t->target, $newContext);

                break;

            case Transition::PRECEDENCE:
                throw new \LogicException('Precedence predicates are not supported in lexers.');

            case Transition::PREDICATE:
                // Track traversing semantic predicates. If we traverse,
                // we cannot add a DFA state for this "reach" computation
                // because the DFA would not test the predicate again in the
                // future. Rather than creating collections of semantic predicates
                // like v3 and testing them on prediction, v4 will test them on the
                // fly all the time using the ATN not the DFA. This is slower but
                // semantically it's not used that often. One of the key elements to
                // this predicate mechanism is not adding DFA states that see
                // predicates immediately afterwards in the ATN. For example,

                // a : ID {p1}? | ID {p2}? ;

                // should create the start state for rule 'a' (to save start state
                // competition), but should not create target of ID state. The
                // collection of ATN states the following ID references includes
                // states reached by traversing predicates. Since this is when we
                // test them, we cannot cash the DFA state target of ID.

                if (!$t instanceof PredicateTransition) {
                    throw new \LogicException('Unexpected transition type.');
                }

                $configs->hasSemanticContext = true;

                if ($this->evaluatePredicate($input, $t->ruleIndex, $t->predIndex, $speculative)) {
                    $cfg = new LexerATNConfig($config, $t->target);
                }

                break;

            case Transition::ACTION:
                if ($config->context === null || $config->context->hasEmptyPath()) {
                    // execute actions anywhere in the start rule for a token.

                    // TODO: if the entry rule is invoked recursively, some
                    // actions may be executed during the recursive call. The
                    // problem can appear when hasEmptyPath() is true but
                    // isEmpty() is false. In this case, the config needs to be
                    // split into two contexts - one with just the empty path
                    // and another with everything but the empty path.
                    // Unfortunately, the current algorithm does not allow
                    // getEpsilonTarget to return two configurations, so
                    // additional modifications are needed before we can support
                    // the split operation.

                    if (!$t instanceof ActionTransition) {
                        throw new \LogicException('Unexpected transition type.');
                    }

                    $lexerAction = $this->atn->lexerActions[$t->actionIndex];

                    $lexerActionExecutor = LexerActionExecutor::append($config->getLexerActionExecutor(), $lexerAction);

                    $cfg = new LexerATNConfig($config, $t->target, null, $lexerActionExecutor);
                } else {
                    // ignore actions in referenced rules
                    $cfg = new LexerATNConfig($config, $t->target);
                }

                break;

            case Transition::EPSILON:
                $cfg = new LexerATNConfig($config, $t->target);

                break;

            case Transition::ATOM:
            case Transition::RANGE:
            case Transition::SET:
                if ($treatEofAsEpsilon) {
                    if ($t->matches(Token::EOF, 0, Lexer::MAX_CHAR_VALUE)) {
                        $cfg = new LexerATNConfig($config, $t->target);
                    }
                }

                break;

            default:
                $cfg = null;
        }

        return $cfg;
    }

    /**
     * Evaluate a predicate specified in the lexer.
     *
     * If `speculative` is `true`, this method was called before
     * {@see LexerATNSimulator::consume()} for the matched character. This
     * method should call {@see LexerATNSimulator::consume()} before evaluating
     * the predicate to ensure position sensitive values, including
     * {@see Lexer::getText()}, {@see Lexer::getLine()}, and
     * {@see Lexer::getCharPositionInLine()}, properly reflect the current
     * lexer state. This method should restore `input` and the simulator
     * to the original state before returning (i.e. undo the actions made by
     * the call to {@see LexerATNSimulator::consume()}.
     *
     * @param CharStream $input       The input stream.
     * @param int        $ruleIndex   The rule containing the predicate.
     * @param int        $predIndex   The index of the predicate within the rule.
     * @param bool       $speculative `true` if the current index in `input` is
     *                                one character before the predicate's location.
     *
     * @return bool `true` If the specified predicate evaluates to `true`.
     */
    protected function evaluatePredicate(CharStream $input, int $ruleIndex, int $predIndex, bool $speculative): bool
    {
        $recognizer = $this->recog;

        if ($recognizer === null) {
            return true;
        }

        if (!$speculative) {
            return $recognizer->sempred(null, $ruleIndex, $predIndex);
        }

        $savedcolumn = $this->charPositionInLine;
        $savedLine = $this->line;
        $index = $input->getIndex();
        $marker = $input->mark();

        try {
            $this->consume($input);

            return $recognizer->sempred(null, $ruleIndex, $predIndex);
        } finally {
            $this->charPositionInLine = $savedcolumn;
            $this->line = $savedLine;
            $input->seek($index);
            $input->release($marker);
        }
    }

    protected function captureSimState(SimState $settings, CharStream $input, DFAState $dfaState): void
    {
        $settings->setIndex($input->getIndex());
        $settings->setLine($this->line);
        $settings->setCharPos($this->charPositionInLine);
        $settings->setDfaState($dfaState);
    }

    protected function addDFAEdgeATNConfigSet(DFAState $from, int $t, ATNConfigSet $configs): DFAState
    {
        /* leading to this call, ATNConfigSet.hasSemanticContext is used as a
         * marker indicating dynamic predicate evaluation makes this edge
         * dependent on the specific input sequence, so the static edge in the
         * DFA should be omitted. The target DFAState is still created since
         * execATN has the ability to resynchronize with the DFA state cache
         * following the predicate evaluation step.
         *
         * TJP notes: next time through the DFA, we see a pred again and eval.
         * If that gets us to a previously created (but dangling) DFA
         * state, we can continue in pure DFA mode from there.
         */
        $suppressEdge = $configs->hasSemanticContext;
        $configs->hasSemanticContext = false;

        $to = $this->addDFAState($configs);

        if ($suppressEdge) {
            return $to;
        }

        $this->addDFAEdge($from, $t, $to);

        return $to;
    }

    protected function addDFAEdge(DFAState $from, int $t, DFAState $to): void
    {
        // add the edge
        if ($t < self::MIN_DFA_EDGE || $t > self::MAX_DFA_EDGE) {
            // Only track edges within the DFA bounds
            return;
        }

        if ($from->edges === null) {
            // make room for tokens 1..n and -1 masquerading as index 0
            $from->edges = new \SplFixedArray(self::MAX_DFA_EDGE - self::MIN_DFA_EDGE + 1);
        }

        $from->edges[$t - self::MIN_DFA_EDGE] = $to; // connect
    }

    /**
     * Add a new DFA state if there isn't one with this set of configurations
     * already. This method also detects the first configuration containing
     * an ATN rule stop state. Later, when traversing the DFA, we will know
     * which rule to accept.
     */
    protected function addDFAState(ATNConfigSet $configs): DFAState
    {
        if ($configs->hasSemanticContext) {
            throw new \LogicException('ATN Config Set cannot have semantic context.');
        }

        $proposed = new DFAState($configs);

        $firstConfigWithRuleStopState = null;

        foreach ($configs->elements() as $config) {
            if ($config->state instanceof RuleStopState) {
                $firstConfigWithRuleStopState = $config;

                break;
            }
        }

        if ($firstConfigWithRuleStopState !== null) {
            if (!$firstConfigWithRuleStopState instanceof LexerATNConfig) {
                throw new \LogicException('Unexpected ATN config type.');
            }

            $proposed->isAcceptState = true;
            $proposed->lexerActionExecutor = $firstConfigWithRuleStopState->getLexerActionExecutor();

            $prediction = $this->atn->ruleToTokenType[$firstConfigWithRuleStopState->state->ruleIndex];

            $proposed->prediction = $prediction ?? 0;
        }

        $dfa = $this->decisionToDFA[$this->mode];

        $existing = $dfa->states->get($proposed);

        if ($existing instanceof DFAState) {
            return $existing;
        }

        $newState = $proposed;
        $newState->stateNumber = $dfa->states->count();
        $configs->setReadonly(true);
        $newState->configs = $configs;
        $dfa->states->add($newState);

        return $newState;
    }

    public function getDFA(int $mode): DFA
    {
        return $this->decisionToDFA[$mode];
    }

    /**
     * Get the text matched so far for the current token.
     */
    public function getText(CharStream $input): string
    {
        // index is first lookahead char, don't include.
        return $input->getText($this->startIndex, $input->getIndex() - 1);
    }

    public function consume(CharStream $input): void
    {
        $curChar = $input->LA(1);

        if ($curChar === self::NEW_LINE_CODE) {
            $this->line++;
            $this->charPositionInLine = 0;
        } else {
            $this->charPositionInLine++;
        }

        $input->consume();
    }

    public function getTokenName(int $t): ?string
    {
        if ($t === -1) {
            return 'EOF';
        }

        return \sprintf('\'%s\'', StringUtils::char($t));
    }
}
