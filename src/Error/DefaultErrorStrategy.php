<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Atn\Transitions\RuleTransition;
use Antlr\Antlr4\Runtime\Error\Exceptions\FailedPredicateException;
use Antlr\Antlr4\Runtime\Error\Exceptions\InputMismatchException;
use Antlr\Antlr4\Runtime\Error\Exceptions\NoViableAltException;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\IntervalSet;
use Antlr\Antlr4\Runtime\Parser;
use Antlr\Antlr4\Runtime\ParserRuleContext;
use Antlr\Antlr4\Runtime\Token;
use Antlr\Antlr4\Runtime\Utils\Pair;
use Antlr\Antlr4\Runtime\Utils\StringUtils;

/**
 * This is the default implementation of {@see ANTLRErrorStrategy} used for
 * error reporting and recovery in ANTLR parsers.
 */
class DefaultErrorStrategy implements ANTLRErrorStrategy
{
    /**
     * Indicates whether the error strategy is currently "recovering from an
     * error". This is used to suppress reporting multiple error messages while
     * attempting to recover from a detected syntax error.
     *
     * @see DefaultErrorStrategy::inErrorRecoveryMode()
     */
    protected bool $errorRecoveryMode = false;

    /** The index into the input stream where the last error occurred.
     *  This is used to prevent infinite loops where an error is found
     *  but no token is consumed during recovery...another error is found,
     *  ad nauseum. This is a failsafe mechanism to guarantee that at least
     *  one token/tree node is consumed for two errors.
     */
    protected int $lastErrorIndex = -1;

    protected ?IntervalSet $lastErrorStates = null;

    /**
     * This field is used to propagate information about the lookahead following
     * the previous match. Since prediction prefers completing the current rule
     * to error recovery efforts, error reporting may occur later than the
     * original point where it was discoverable. The original context is used to
     * compute the true expected sets as though the reporting occurred as early
     * as possible.
     */
    protected ?ParserRuleContext $nextTokensContext = null;

    /** @see DefaultErrorStrategy::$nextTokensContext */
    protected ?int $nextTokensState = null;

    /**
     * {@inheritdoc}
     *
     * The default implementation simply calls
     * {@see DefaultErrorStrategy::endErrorCondition()} to ensure that
     * the handler is not in error recovery mode.
     */
    public function reset(Parser $recognizer): void
    {
        $this->endErrorCondition($recognizer);
    }

    /**
     * This method is called to enter error recovery mode when a recognition
     * exception is reported.
     *
     * @param Parser $recognizer The parser instance.
     */
    protected function beginErrorCondition(Parser $recognizer): void
    {
        $this->errorRecoveryMode = true;
    }

    public function inErrorRecoveryMode(Parser $recognizer): bool
    {
        return $this->errorRecoveryMode;
    }

    /**
     * This method is called to leave error recovery mode after recovering from
     * a recognition exception.
     */
    protected function endErrorCondition(Parser $recognizer): void
    {
        $this->errorRecoveryMode = false;
        $this->lastErrorStates = null;
        $this->lastErrorIndex = -1;
    }

    /**
     * {@inheritdoc}
     *
     * The default implementation simply calls
     * {@see DefaultErrorStrategy::endErrorCondition()}.
     */
    public function reportMatch(Parser $recognizer): void
    {
        $this->endErrorCondition($recognizer);
    }

    /**
     * {@inheritdoc}
     *
     * The default implementation returns immediately if the handler is already
     * in error recovery mode. Otherwise, it calls
     * {@see DefaultErrorStrategy::beginErrorCondition()} and dispatches
     * the reporting task based on the runtime type of `e` according to
     * the following table.
     *
     * - {@see NoViableAltException}: Dispatches the call to
     * {@see reportNoViableAlternative}
     * - {@see InputMismatchException}: Dispatches the call to
     * {@see reportInputMismatch}
     * - {@see FailedPredicateException}: Dispatches the call to
     * {@see reportFailedPredicate}
     * - All other types: calls {@see Parser#notifyErrorListeners} to report
     * the exception
     */
    public function reportError(Parser $recognizer, RecognitionException $e): void
    {
        // if we've already reported an error and have not matched a token
        // yet successfully, don't report any errors.
        if ($this->inErrorRecoveryMode($recognizer)) {
            // don't report spurious errors
            return;
        }

        $this->beginErrorCondition($recognizer);

        if ($e instanceof NoViableAltException) {
            $this->reportNoViableAlternative($recognizer, $e);
        } elseif ($e instanceof InputMismatchException) {
            $this->reportInputMismatch($recognizer, $e);
        } elseif ($e instanceof FailedPredicateException) {
            $this->reportFailedPredicate($recognizer, $e);
        } else {
            $recognizer->notifyErrorListeners($e->getMessage(), $e->getOffendingToken(), $e);
        }
    }

    /**
     * {@inheritdoc}
     *
     * The default implementation resynchronizes the parser by consuming tokens
     * until we find one in the resynchronization set--loosely the set of tokens
     * that can follow the current rule.
     */
    public function recover(Parser $recognizer, RecognitionException $e): void
    {
        $inputStream = $recognizer->getInputStream();

        if ($inputStream === null) {
            throw new \LogicException('Unexpected null input stream.');
        }

        if ($this->lastErrorStates !== null
            && $this->lastErrorIndex === $inputStream->getIndex()
            && $this->lastErrorStates->contains($recognizer->getState())
        ) {
            // uh oh, another error at same token index and previously-visited
            // state in ATN; must be a case where LT(1) is in the recovery
            // token set so nothing got consumed. Consume a single token
            // at least to prevent an infinite loop; this is a failsafe.
            $recognizer->consume();
        }

        $this->lastErrorIndex = $inputStream->getIndex();

        if ($this->lastErrorStates === null) {
            $this->lastErrorStates = new IntervalSet();
        }

        $this->lastErrorStates->addOne($recognizer->getState());

        $followSet = $this->getErrorRecoverySet($recognizer);

        $this->consumeUntil($recognizer, $followSet);
    }

    /**
     * The default implementation of {@see ANTLRErrorStrategy::sync()} makes sure
     * that the current lookahead symbol is consistent with what were expecting
     * at this point in the ATN. You can call this anytime but ANTLR only
     * generates code to check before subrules/loops and each iteration.
     *
     * Implements Jim Idle's magic sync mechanism in closures and optional
     * subrules. E.g.,
     *
     *     a : sync ( stuff sync )* ;
     *     sync : {consume to what can follow sync} ;
     *
     * At the start of a sub rule upon error, {@see sync} performs single
     * token deletion, if possible. If it can't do that, it bails on the current
     * rule and uses the default error recovery, which consumes until the
     * resynchronization set of the current rule.
     *
     * If the sub rule is optional (`(...)?`, `(...)*`, or block
     * with an empty alternative), then the expected set includes what follows
     * the subrule.
     *
     * During loop iteration, it consumes until it sees a token that can start a
     * sub rule or what follows loop. Yes, that is pretty aggressive. We opt to
     * stay in the loop as long as possible.
     *
     * ORIGINS
     *
     * Previous versions of ANTLR did a poor job of their recovery within loops.
     * A single mismatch token or missing token would force the parser to bail
     * out of the entire rules surrounding the loop. So, for rule
     *
     *     classDef : 'class' ID '{' member* '}'
     *
     * input with an extra token between members would force the parser to
     * consume until it found the next class definition rather than the next
     * member definition of the current class.
     *
     * This functionality cost a little bit of effort because the parser has to
     * compare token set at the start of the loop and at each iteration. If for
     * some reason speed is suffering for you, you can turn off this
     * functionality by simply overriding this method as a blank { }.
     *
     * @throws RecognitionException
     */
    public function sync(Parser $recognizer): void
    {
        $interpreter = $recognizer->getInterpreter();

        if ($interpreter === null) {
            throw new \LogicException('Unexpected null interpreter.');
        }

        /** @var ATNState $s */
        $s = $interpreter->atn->states[$recognizer->getState()];

        // If already recovering, don't try to sync
        if ($this->inErrorRecoveryMode($recognizer)) {
            return;
        }

        $tokens = $recognizer->getInputStream();

        if ($tokens === null) {
            throw new \LogicException('Unexpected null input stream.');
        }

        $la = $tokens->LA(1);

        // try cheaper subset first; might get lucky. seems to shave a wee bit off
        $nextTokens = $recognizer->getATN()->nextTokens($s);

        if ($nextTokens->contains($la)) {
            // We are sure the token matches
            $this->nextTokensContext = null;
            $this->nextTokensState = ATNState::INVALID_STATE_NUMBER;

            return;
        }

        if ($nextTokens->contains(Token::EPSILON)) {
            if ($this->nextTokensContext === null) {
                // It's possible the next token won't match; information tracked
                // by sync is restricted for performance.
                $this->nextTokensContext = $recognizer->getContext();
                $this->nextTokensState = $recognizer->getState();
            }

            return;
        }

        switch ($s->getStateType()) {
            case ATNState::BLOCK_START:
            case ATNState::STAR_BLOCK_START:
            case ATNState::PLUS_BLOCK_START:
            case ATNState::STAR_LOOP_ENTRY:
                // report error and recover if possible
                if ($this->singleTokenDeletion($recognizer) !== null) {
                    return;
                }

                throw new InputMismatchException($recognizer);

            case ATNState::PLUS_LOOP_BACK:
            case ATNState::STAR_LOOP_BACK:
                $this->reportUnwantedToken($recognizer);
                $expecting = $recognizer->getExpectedTokens();
                $whatFollowsLoopIterationOrRule = $expecting->orSet($this->getErrorRecoverySet($recognizer));
                $this->consumeUntil($recognizer, $whatFollowsLoopIterationOrRule);

                break;

            default:
                // do nothing if we can't identify the exact kind of ATN state
                break;
        }
    }

    /**
     * This is called by {@see DefaultErrorStrategy::reportError()} when
     * the exception is a {@see NoViableAltException}.
     *
     * @param Parser               $recognizer The parser instance.
     * @param NoViableAltException $e          The recognition exception.
     *
     * @see DefaultErrorStrategy::reportError()
     */
    protected function reportNoViableAlternative(Parser $recognizer, NoViableAltException $e): void
    {
        $tokens = $recognizer->getTokenStream();

        $input = '<unknown input>';

        if ($tokens !== null) {
            $startToken = $e->getStartToken();

            if ($startToken === null) {
                throw new \LogicException('Unexpected null start token.');
            }

            if ($startToken->getType() === Token::EOF) {
                $input = '<EOF>';
            } else {
                $input = $tokens->getTextByTokens($e->getStartToken(), $e->getOffendingToken());
            }
        }

        $msg = \sprintf('no viable alternative at input %s', $this->escapeWSAndQuote($input));

        $recognizer->notifyErrorListeners($msg, $e->getOffendingToken(), $e);
    }

    /**
     * This is called by {@see DefaultErrorStrategy::reportError()} when
     * the exception is an {@see InputMismatchException}.
     *
     * @param Parser                 $recognizer The parser instance.
     * @param InputMismatchException $e          The recognition exception.
     *
     * @see DefaultErrorStrategy::reportError()
     */
    protected function reportInputMismatch(Parser $recognizer, InputMismatchException $e): void
    {
        $expectedTokens = $e->getExpectedTokens();

        if ($expectedTokens === null) {
            throw new \LogicException('Unexpected null expected tokens.');
        }

        $msg = \sprintf(
            'mismatched input %s expecting %s',
            $this->getTokenErrorDisplay($e->getOffendingToken()),
            $expectedTokens->toStringVocabulary($recognizer->getVocabulary()),
        );

        $recognizer->notifyErrorListeners($msg, $e->getOffendingToken(), $e);
    }

    /**
     * This is called by {@see DefaultErrorStrategy::reportError()} when
     * the exception is a {@see FailedPredicateException}.
     *
     * @param Parser                   $recognizer The parser instance.
     * @param FailedPredicateException $e          The recognition exception.
     *
     * @see DefaultErrorStrategy::reportError()
     */
    protected function reportFailedPredicate(Parser $recognizer, FailedPredicateException $e): void
    {
        $msg = \sprintf('rule %s %s', $recognizer->getCurrentRuleName(), $e->getMessage());

        $recognizer->notifyErrorListeners($msg, $e->getOffendingToken(), $e);
    }

    /**
     * This method is called to report a syntax error which requires the removal
     * of a token from the input stream. At the time this method is called, the
     * erroneous symbol is current `LT(1)` symbol and has not yet been
     * removed from the input stream. When this method returns,
     * `$recognizer` is in error recovery mode.
     *
     * This method is called when {@see DefaultErrorStrategy::singleTokenDeletion()}
     * identifies single-token deletion as a viable recovery strategy for
     * a mismatched input error.
     *
     * The default implementation simply returns if the handler is already in
     * error recovery mode. Otherwise, it calls
     * {@see DefaultErrorStrategy::beginErrorCondition()} to enter error
     * recovery mode, followed by calling {@see Parser::notifyErrorListeners}.
     *
     * @param Parser $recognizer The parser instance.
     */
    protected function reportUnwantedToken(Parser $recognizer): void
    {
        if ($this->inErrorRecoveryMode($recognizer)) {
            return;
        }

        $this->beginErrorCondition($recognizer);

        $t = $recognizer->getCurrentToken();
        $tokenName = $this->getTokenErrorDisplay($t);
        $expecting = $this->getExpectedTokens($recognizer);

        $msg = \sprintf(
            'extraneous input %s expecting %s',
            $tokenName,
            $expecting->toStringVocabulary($recognizer->getVocabulary()),
        );

        $recognizer->notifyErrorListeners($msg, $t);
    }

    /**
     * This method is called to report a syntax error which requires the
     * insertion of a missing token into the input stream. At the time this
     * method is called, the missing token has not yet been inserted. When this
     * method returns, `$recognizer` is in error recovery mode.
     *
     * This method is called when {@see DefaultErrorStrategy::singleTokenInsertion()}
     * identifies single-token insertion as a viable recovery strategy for
     * a mismatched input error.
     *
     * The default implementation simply returns if the handler is already in
     * error recovery mode. Otherwise, it calls
     * {@see DefaultErrorStrategy::beginErrorCondition()} to enter error
     * recovery mode, followed by calling {@see Parser::notifyErrorListeners()}.
     *
     * @param Parser $recognizer the parser instance
     */
    protected function reportMissingToken(Parser $recognizer): void
    {
        if ($this->inErrorRecoveryMode($recognizer)) {
            return;
        }

        $this->beginErrorCondition($recognizer);

        $t = $recognizer->getCurrentToken();
        $expecting = $this->getExpectedTokens($recognizer);

        $msg = \sprintf(
            'missing %s at %s',
            $expecting->toStringVocabulary($recognizer->getVocabulary()),
            $this->getTokenErrorDisplay($t),
        );

        $recognizer->notifyErrorListeners($msg, $t);
    }

    /**
     * {@inheritdoc}
     *
     * The default implementation attempts to recover from the mismatched input
     * by using single token insertion and deletion as described below. If the
     * recovery attempt fails, this method throws an
     * {@see InputMismatchException}.
     *
     * EXTRA TOKEN (single token deletion)
     *
     * `LA(1)` is not what we are looking for. If `LA(2)` has the
     * right token, however, then assume `LA(1)` is some extra spurious
     * token and delete it. Then consume and return the next token (which was
     * the `LA(2)` token) as the successful result of the match operation.
     *
     * This recovery strategy is implemented by
     * {@see DefaultErrorStrategy::singleTokenDeletion()}.
     *
     * MISSING TOKEN (single token insertion)
     *
     * If current token (at `LA(1)`) is consistent with what could come
     * after the expected `LA(1)` token, then assume the token is missing
     * and use the parser's {@see TokenFactory} to create it on the fly. The
     * "insertion" is performed by returning the created token as the successful
     * result of the match operation.
     *
     * This recovery strategy is implemented by
     * {@see DefaultErrorStrategy::singleTokenInsertion()}.
     *
     * EXAMPLE
     *
     * For example, Input `i=(3;` is clearly missing the `')'`. When
     * the parser returns from the nested call to `expr`, it will have
     * call chain:
     *
     *     stat &rarr; expr &rarr; atom
     *
     * and it will be trying to match the `')'` at this point in the
     * derivation:
     *
     *     =&gt; ID '=' '(' INT ')' ('+' atom)* ';'
     *                        ^
     *
     * The attempt to match `')'` will fail when it sees `';'` and call
     * {@see DefaultErrorStrategy::recoverInline()}. To recover, it sees that
     * `LA(1)==';'` is in the set of tokens that can follow the `')'` token
     * reference in rule `atom`. It can assume that you forgot the `')'`.
     *
     * @throws RecognitionException
     */
    public function recoverInline(Parser $recognizer): Token
    {
        // SINGLE TOKEN DELETION
        $matchedSymbol = $this->singleTokenDeletion($recognizer);

        if ($matchedSymbol !== null) {
            // we have deleted the extra token.
            // now, move past ttype token as if all were ok
            $recognizer->consume();

            return $matchedSymbol;
        }

        // SINGLE TOKEN INSERTION
        if ($this->singleTokenInsertion($recognizer)) {
            return $this->getMissingSymbol($recognizer);
        }

        // even that didn't work; must throw the exception
        if ($this->nextTokensContext === null) {
            throw new InputMismatchException($recognizer);
        }

        throw new InputMismatchException($recognizer, $this->nextTokensState, $this->nextTokensContext);
    }

    /**
     * This method implements the single-token insertion inline error recovery
     * strategy. It is called by {@see DefaultErrorStrategy::recoverInline()}
     * if the single-token deletion strategy fails to recover from the mismatched
     * input. If this method returns `true`, `$recognizer` will be in error
     * recovery mode.
     *
     * This method determines whether or not single-token insertion is viable by
     * checking if the `LA(1)` input symbol could be successfully matched
     * if it were instead the `LA(2)` symbol. If this method returns
     * `true`, the caller is responsible for creating and inserting a
     * token with the correct type to produce this behavior.
     *
     * @param Parser $recognizer The parser instance.
     *
     * @return bool `true` If single-token insertion is a viable recovery
     *              strategy for the current mismatched input, otherwise `false`.
     */
    protected function singleTokenInsertion(Parser $recognizer): bool
    {
        $stream = $recognizer->getInputStream();

        if ($stream === null) {
            throw new \LogicException('Unexpected null input stream.');
        }

        $interpreter = $recognizer->getInterpreter();

        if ($interpreter === null) {
            throw new \LogicException('Unexpected null interpreter.');
        }

        $currentSymbolType = $stream->LA(1);

        // if current token is consistent with what could come after current
        // ATN state, then we know we're missing a token; error recovery
        // is free to conjure up and insert the missing token

        $atn = $interpreter->atn;
        /** @var ATNState $currentState */
        $currentState = $atn->states[$recognizer->getState()];
        $next = $currentState->getTransition(0)->target;
        $expectingAtLL2 = $atn->nextTokensInContext($next, $recognizer->getContext());

        if ($expectingAtLL2->contains($currentSymbolType)) {
            $this->reportMissingToken($recognizer);

            return true;
        }

        return false;
    }

    /**
     * This method implements the single-token deletion inline error recovery
     * strategy. It is called by {@see DefaultErrorStrategy::recoverInline()}
     * to attempt to recover from mismatched input. If this method returns null,
     * the parser and error handler state will not have changed. If this method
     * returns non-null, `$recognizer` will _not_ be in error recovery mode
     * since the returned token was a successful match.
     *
     * If the single-token deletion is successful, this method calls
     * {@see DefaultErrorStrategy::reportUnwantedToken()} to report the error,
     * followed by {@see Parser::consume()} to actually "delete" the extraneous
     * token. Then, before returning {@see DefaultErrorStrategy::reportMatch()}
     * is called to signal a successful match.
     *
     * @param Parser $recognizer The parser instance.
     *
     * @return Token The successfully matched {@see Token} instance if
     *               single-token deletion successfully recovers from
     *               the mismatched input, otherwise `null`.
     */
    protected function singleTokenDeletion(Parser $recognizer): ?Token
    {
        $inputStream = $recognizer->getInputStream();

        if ($inputStream === null) {
            throw new \LogicException('Unexpected null input stream.');
        }

        $nextTokenType = $inputStream->LA(2);
        $expecting = $this->getExpectedTokens($recognizer);

        if ($expecting->contains($nextTokenType)) {
            $this->reportUnwantedToken($recognizer);
            $recognizer->consume(); // simply delete extra token
            // we want to return the token we're actually matching
            $matchedSymbol = $recognizer->getCurrentToken();
            $this->reportMatch($recognizer); // we know current token is correct

            return $matchedSymbol;
        }

        return null;
    }

    /** Conjure up a missing token during error recovery.
     *
     *  The recognizer attempts to recover from single missing
     *  symbols. But, actions might refer to that missing symbol.
     *  For example, x=ID {f($x);}. The action clearly assumes
     *  that there has been an identifier matched previously and that
     *  $x points at that token. If that token is missing, but
     *  the next token in the stream is what we want we assume that
     *  this token is missing and we keep going. Because we
     *  have to return some token to replace the missing token,
     *  we have to conjure one up. This method gives the user control
     *  over the tokens returned for missing tokens. Mostly,
     *  you will want to create something special for identifier
     *  tokens. For literals such as '{' and ',', the default
     *  action in the parser or tree parser works. It simply creates
     *  a CommonToken of the appropriate type. The text will be the token.
     *  If you change what tokens must be created by the lexer,
     *  override this method to create the appropriate tokens.
     */
    protected function getMissingSymbol(Parser $recognizer): Token
    {
        $currentSymbol = $recognizer->getCurrentToken();

        if ($currentSymbol === null) {
            throw new \LogicException('Unexpected null current token.');
        }

        $inputStream = $recognizer->getInputStream();

        if ($inputStream === null) {
            throw new \LogicException('Unexpected null input stream.');
        }

        $tokenSource = $currentSymbol->getTokenSource();

        if ($tokenSource === null) {
            throw new \LogicException('Unexpected null token source.');
        }

        $expecting = $this->getExpectedTokens($recognizer);

        $expectedTokenType = Token::INVALID_TYPE;

        if (!$expecting->isNull()) {
            $expectedTokenType = $expecting->getMinElement(); // get any element
        }

        if ($expectedTokenType === Token::EOF) {
            $tokenText = '<missing EOF>';
        } else {
            $tokenText = \sprintf('<missing %s>', $recognizer->getVocabulary()->getDisplayName($expectedTokenType));
        }

        $current = $currentSymbol;
        $lookback = $inputStream->LT(-1);

        if ($current->getType() === Token::EOF && $lookback !== null) {
            $current = $lookback;
        }

        return $recognizer->getTokenFactory()->createEx(
            new Pair(
                $tokenSource,
                $tokenSource->getInputStream(),
            ),
            $expectedTokenType,
            $tokenText,
            Token::DEFAULT_CHANNEL,
            -1,
            -1,
            $current->getLine(),
            $current->getCharPositionInLine(),
        );
    }

    protected function getExpectedTokens(Parser $recognizer): IntervalSet
    {
        return $recognizer->getExpectedTokens();
    }

    /**
     * How should a token be displayed in an error message? The default
     * is to display just the text, but during development you might
     * want to have a lot of information spit out.  Override in that case
     * to use (string) (which, for CommonToken, dumps everything about
     * the token). This is better than forcing you to override a method in
     * your token objects because you don't have to go modify your lexer
     * so that it creates a new Java type.
     */
    protected function getTokenErrorDisplay(?Token $t): string
    {
        if ($t === null) {
            return '<no token>';
        }

        $s = $this->getSymbolText($t);

        if ($s === null) {
            if ($this->getSymbolType($t) === Token::EOF) {
                $s = '<EOF>';
            } else {
                $s = '<' . $this->getSymbolType($t) . '>';
            }
        }

        return $this->escapeWSAndQuote($s);
    }

    protected function getSymbolText(Token $symbol): ?string
    {
        return $symbol->getText();
    }

    protected function getSymbolType(Token $symbol): int
    {
        return $symbol->getType();
    }

    protected function escapeWSAndQuote(string $s): string
    {
        return "'" . StringUtils::escapeWhitespace($s) . "'";
    }

    /**
     * Compute the error recovery set for the current rule.  During
     * rule invocation, the parser pushes the set of tokens that can
     * follow that rule reference on the stack; this amounts to
     * computing FIRST of what follows the rule reference in the
     * enclosing rule. See LinearApproximator::FIRST.
     * This local follow set only includes tokens
     * from within the rule; i.e., the FIRST computation done by
     * ANTLR stops at the end of a rule.
     *
     * EXAMPLE
     *
     * When you find a "no viable alt exception", the input is not
     * consistent with any of the alternatives for rule r.  The best
     * thing to do is to consume tokens until you see something that
     * can legally follow a call to r *or* any rule that called r.
     * You don't want the exact set of viable next tokens because the
     * input might just be missing a token--you might consume the
     * rest of the input looking for one of the missing tokens.
     *
     * Consider grammar:
     *
     *     a : '[' b ']'
     *       | '(' b ')'
     *       ;
     *     b : c '^' INT ;
     *     c : ID
     *       | INT
     *       ;
     *
     * At each rule invocation, the set of tokens that could follow
     * that rule is pushed on a stack.  Here are the various
     * context-sensitive follow sets:
     *
     *     FOLLOW(b1_in_a) = FIRST(']') = ']'
     *     FOLLOW(b2_in_a) = FIRST(')') = ')'
     *     FOLLOW(c_in_b) = FIRST('^') = '^'
     *
     * Upon erroneous input "[]", the call chain is
     *
     *     a -> b -> c
     *
     * and, hence, the follow context stack is:
     *
     * depth | follow set | start of rule execution
     * ------|------------|-------------------------
     *   0   |   <EOF>    |    a (from main())
     *   1   |   ']'      |          b
     *   2   |   '^'      |          c
     *
     * Notice that ')' is not included, because b would have to have
     * been called from a different context in rule a for ')' to be
     * included.
     *
     * For error recovery, we cannot consider FOLLOW(c)
     * (context-sensitive or otherwise).  We need the combined set of
     * all context-sensitive FOLLOW sets--the set of all tokens that
     * could follow any reference in the call chain.  We need to
     * resync to one of those tokens.  Note that FOLLOW(c)='^' and if
     * we resync'd to that token, we'd consume until EOF.  We need to
     * sync to context-sensitive FOLLOWs for a, b, and c: {']','^'}.
     * In this case, for input "[]", LA(1) is ']' and in the set, so we would
     * not consume anything. After printing an error, rule c would
     * return normally.  Rule b would not find the required '^' though.
     * At this point, it gets a mismatched token error and throws an
     * exception (since LA(1) is not in the viable following token
     * set).  The rule exception handler tries to recover, but finds
     * the same recovery set and doesn't consume anything.  Rule b
     * exits normally returning to rule a.  Now it finds the ']' (and
     * with the successful match exits errorRecovery mode).
     *
     * So, you can see that the parser walks up the call chain looking
     * for the token that was a member of the recovery set.
     *
     * Errors are not generated in errorRecovery mode.
     *
     * ANTLR's error recovery mechanism is based upon original ideas:
     *
     * "Algorithms + Data Structures = Programs" by Niklaus Wirth
     *
     * and
     *
     * "A note on error recovery in recursive descent parsers":
     * http://portal.acm.org/citation.cfm?id=947902.947905
     *
     * Later, Josef Grosch had some good ideas:
     *
     * "Efficient and Comfortable Error Recovery in Recursive Descent
     * Parsers":
     * ftp://www.cocolab.com/products/cocktail/doca4.ps/ell.ps.zip
     *
     * Like Grosch I implement context-sensitive FOLLOW sets that are combined
     * at run-time upon error to avoid overhead during parsing.
     */
    protected function getErrorRecoverySet(Parser $recognizer): IntervalSet
    {
        $interpreter = $recognizer->getInterpreter();

        if ($interpreter === null) {
            throw new \LogicException('Unexpected null interpreter.');
        }

        $atn = $interpreter->atn;
        $ctx = $recognizer->getContext();
        $recoverSet = new IntervalSet();

        while ($ctx !== null && $ctx->invokingState >= 0) {
            // compute what follows who invoked us
            /** @var ATNState $invokingState */
            $invokingState = $atn->states[$ctx->invokingState];
            /** @var RuleTransition $rt */
            $rt = $invokingState->getTransition(0);
            $follow = $atn->nextTokens($rt->followState);
            $recoverSet->addSet($follow);
            $ctx = $ctx->getParent();
        }

        $recoverSet->removeOne(Token::EPSILON);

        return $recoverSet;
    }

    /**
     * Consume tokens until one matches the given token set.
     */
    protected function consumeUntil(Parser $recognizer, IntervalSet $set): void
    {
        $inputStream = $recognizer->getInputStream();

        if ($inputStream === null) {
            throw new \LogicException('Unexpected null input stream.');
        }

        $ttype = $inputStream->LA(1);

        while ($ttype !== Token::EOF && !$set->contains($ttype)) {
            $recognizer->consume();
            $ttype = $inputStream->LA(1);
        }
    }
}
