<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Atn\ATN;
use Antlr\Antlr4\Runtime\Atn\ATNDeserializationOptions;
use Antlr\Antlr4\Runtime\Atn\ATNDeserializer;
use Antlr\Antlr4\Runtime\Atn\ParserATNSimulator;
use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Atn\Transitions\RuleTransition;
use Antlr\Antlr4\Runtime\Dfa\DFA;
use Antlr\Antlr4\Runtime\Error\ANTLRErrorStrategy;
use Antlr\Antlr4\Runtime\Error\DefaultErrorStrategy;
use Antlr\Antlr4\Runtime\Error\Exceptions\InputMismatchException;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Tree\ErrorNode;
use Antlr\Antlr4\Runtime\Tree\ErrorNodeImpl;
use Antlr\Antlr4\Runtime\Tree\ParseTreeListener;
use Antlr\Antlr4\Runtime\Tree\TerminalNode;
use Antlr\Antlr4\Runtime\Tree\TerminalNodeImpl;

/**
 * This is all the parsing support code essentially; most of it is
 * error recovery stuff.
 */
abstract class Parser extends Recognizer
{
    /**
     * This field maps from the serialized ATN string to the deserialized
     * {@see ATN} with bypass alternatives.
     *
     * @see ATNDeserializationOptions::isGenerateRuleBypassTransitions()
     */
    private static ?ATN $bypassAltsAtnCache = null;

    /**
     * The error handling strategy for the parser. The default value is a new
     * instance of {@see DefaultErrorStrategy}.
     *
     * @see Parser::getErrorHandler()
     * @see Parser::setErrorHandler()
     */
    protected ANTLRErrorStrategy $errorHandler;

    /**
     * The input stream.
     *
     * @see Parser::getInputStream()
     * @see Parser::setInputStream()
     */
    protected ?TokenStream $input = null;

    /** @var array<int> */
    protected array $precedenceStack = [0];

    /**
     * The {@see ParserRuleContext} object for the currently executing rule.
     * This is always non-null during the parsing process.
     */
    protected ?ParserRuleContext $ctx = null;

    /**
     * Specifies whether or not the parser should construct a parse tree during
     * the parsing process. The default value is `true`.
     *
     * @see Parser::getBuildParseTree()
     * @see Parser::setBuildParseTree()
     */
    protected bool $buildParseTree = true;

    /**
     * When {@see Parser::setTrace(true)} is called, a reference to the
     * {@see TraceListener} is stored here so it can be easily removed in a
     * later call to {@see Parser::setTrace(false)}. The listener itself is
     * implemented as a parser listener so this field is not directly used by
     * other parser methods.
     */
    private ?ParserTraceListener $tracer = null;

    /**
     * The list of {@see ParseTreeListener} listeners registered to receive
     * events during the parse.
     *
     * @see Parser::addParseListener
     *
     * @var array<ParseTreeListener>
     */
    protected array $parseListeners = [];

    /**
     * The number of syntax errors reported during parsing. This value is
     * incremented each time {@see Parser::notifyErrorListeners()} is called.
     */
    protected int $syntaxErrors = 0;

    /**
     * Indicates parser has matched EOF token. See {@see Parser::exitRule()}.
     */
    protected bool $matchedEOF = false;

    public function __construct(TokenStream $input)
    {
        parent::__construct();

        $this->errorHandler = new DefaultErrorStrategy();

        $this->setInputStream($input);
    }

    /** reset the parser's state */
    public function reset(): void
    {
        if ($this->input !== null) {
            $this->input->seek(0);
        }

        $this->errorHandler->reset($this);
        $this->ctx = null;
        $this->syntaxErrors = 0;
        $this->matchedEOF = false;
        $this->setTrace(false);
        $this->precedenceStack = [0];

        $interpreter = $this->getInterpreter();

        if ($interpreter !== null) {
            $interpreter->reset();
        }
    }

    /**
     * Match current input symbol against `ttype`. If the symbol type matches,
     * {@see ANTLRErrorStrategy::reportMatch()} and {@see Parser::consume()}
     * are called to complete the match process.
     *
     * If the symbol type does not match, {@see ANTLRErrorStrategy::recoverInline()}
     * is called on the current error strategy to attempt recovery. If
     * {@see Parser::getBuildParseTree()} is `true` and the token index
     * of the symbol returned by {@see ANTLRErrorStrategy::recoverInline()}
     * is -1, the symbol is added to the parse tree by calling
     * {@see Parser::createErrorNode(ParserRuleContext, Token)} then
     * {@see ParserRuleContext::addErrorNode(ErrorNode)}.
     *
     * @param int $ttype the token type to match.
     *
     * @return Token the matched symbol
     *
     * @throws InputMismatchException
     * @throws RecognitionException If the current input symbol did not match
     *                              and the error strategy could not recover
     *                              from the mismatched symbol.
     */
    public function match(int $ttype): Token
    {
        $t = $this->getCurrentToken();

        if ($t !== null && $t->getType() === $ttype) {
            if ($ttype === Token::EOF) {
                $this->matchedEOF = true;
            }

            $this->errorHandler->reportMatch($this);

            $this->consume();
        } else {
            $t = $this->errorHandler->recoverInline($this);

            if ($this->buildParseTree && $t->getTokenIndex() === -1) {
                // we must have conjured up a new token during single token insertion
                // if it's not the current symbol
                $this->context()->addErrorNode($this->createErrorNode($this->context(), $t));
            }
        }

        return $t;
    }

    /**
     * Match current input symbol as a wildcard. If the symbol type matches
     * (i.e. has a value greater than 0), {@see ANTLRErrorStrategy::reportMatch()}
     * and {@see Parser::consume()} are called to complete the match process.
     *
     * If the symbol type does not match, {@see ANTLRErrorStrategy::recoverInline()}
     * is called on the current error strategy to attempt recovery.
     * If {@see Parser::getBuildParseTree()} is `true` and the token index
     * of the symbol returned by {@see ANTLRErrorStrategy::recoverInline()} is -1,
     * the symbol is added to the parse tree by calling
     * {@see Parser::createErrorNode(ParserRuleContext, Token)}. then
     * {@see ParserRuleContext::addErrorNode(ErrorNode)}
     *
     * @return Token The matched symbol.
     *
     * @throws RecognitionException If the current input symbol did not match
     *                              a wildcard and the error strategy could not
     *                              recover from the mismatched symbol.
     */
    public function matchWildcard(): ?Token
    {
        $t = $this->token();

        if ($t->getType() > 0) {
            $this->errorHandler->reportMatch($this);
            $this->consume();
        } else {
            $t = $this->errorHandler->recoverInline($this);

            if ($this->buildParseTree && $t->getTokenIndex() === -1) {
                // we must have conjured up a new token during single token insertion
                // if it's not the current symbol
                $this->context()->addErrorNode($this->createErrorNode($this->context(), $t));
            }
        }

        return $t;
    }

    /**
     * Track the {@see ParserRuleContext} objects during the parse and hook
     * them up using the {@see ParserRuleContext::$children} list so that it
     * forms a parse tree. The {@see ParserRuleContext} returned from the start
     * rule represents the root of the parse tree.
     *
     * Note that if we are not building parse trees, rule contexts only point
     * upwards. When a rule exits, it returns the context but that gets garbage
     * collected if nobody holds a reference. It points upwards but nobody
     * points at it.
     *
     * When we build parse trees, we are adding all of these contexts to
     * {@see ParserRuleContext::$children} list. Contexts are then not
     * candidates for garbage collection.
     */
    public function setBuildParseTree(bool $buildParseTree): void
    {
        $this->buildParseTree = $buildParseTree;
    }

    /**
     * Gets whether or not a complete parse tree will be constructed while
     * parsing. This property is `true` for a newly constructed parser.
     *
     * @return bool `true` if a complete parse tree will be constructed while
     *              parsing, otherwise `false`.
     */
    public function getBuildParseTree(): bool
    {
        return $this->buildParseTree;
    }

    /**
     * @return array<ParseTreeListener>
     */
    public function getParseListeners(): array
    {
        return $this->parseListeners;
    }

    /**
     * Registers `listener` to receive events during the parsing process.
     *
     * To support output-preserving grammar transformations (including but not
     * limited to left-recursion removal, automated left-factoring, and
     * optimized code generation), calls to listener methods during the parse
     * may differ substantially from calls made by
     * {@see ParseTreeWalker::DEFAULT} used after the parse is complete. In
     * particular, rule entry and exit events may occur in a different order
     * during the parse than after the parser. In addition, calls to certain
     * rule entry methods may be omitted.
     *
     * With the following specific exceptions, calls to listener events are
     * <em>deterministic</em>, i.e. for identical input the calls to listener
     * methods will be the same.
     *
     * - Alterations to the grammar used to generate code may change the
     * behavior of the listener calls.
     * - Alterations to the command line options passed to ANTLR 4 when
     * generating the parser may change the behavior of the listener calls.
     * - Changing the version of the ANTLR Tool used to generate the parser
     * may change the behavior of the listener calls.
     *
     * @param ParseTreeListener $listener The listener to add.
     */
    public function addParseListener(ParseTreeListener $listener): void
    {
        if (!\in_array($listener, $this->parseListeners, true)) {
            $this->parseListeners[] = $listener;
        }
    }

    /**
     * Remove `listener` from the list of parse listeners.
     *
     * If `listener` is `null` or has not been added as a parse
     * listener, this method does nothing.
     *
     * @param ParseTreeListener $listener The listener to remove
     *
     * @see Parser::addParseListener()
     */
    public function removeParseListener(ParseTreeListener $listener): void
    {
        $index = \array_search($listener, $this->parseListeners, true);

        if ($index !== false) {
            unset($this->parseListeners[$index]);
        }
    }

    /**
     * Remove all parse listeners.
     *
     * @see Parser::addParseListener()
     */
    public function removeParseListeners(): void
    {
        $this->parseListeners = [];
    }

    /**
     * Notify any parse listeners of an enter rule event.
     *
     * @seeParser::addParseListener()
     */
    protected function triggerEnterRuleEvent(): void
    {
        foreach ($this->parseListeners as $listener) {
            $listener->enterEveryRule($this->context());
            $this->context()->enterRule($listener);
        }
    }

    /**
     * Notify any parse listeners of an exit rule event.
     *
     * @see Parser::addParseListener()
     */
    protected function triggerExitRuleEvent(): void
    {
        for ($i = \count($this->parseListeners) - 1; $i >= 0; $i--) {
            /** @var ParseTreeListener $listener */
            $listener = $this->parseListeners[$i];
            $this->context()->exitRule($listener);
            $listener->exitEveryRule($this->context());
        }
    }

    /**
     * Gets the number of syntax errors reported during parsing. This value is
     * incremented each time {@see Parser::notifyErrorListeners()} is called.
     *
     * @see Parser::notifyErrorListeners()
     */
    public function getNumberOfSyntaxErrors(): int
    {
        return $this->syntaxErrors;
    }

    public function getTokenFactory(): TokenFactory
    {
        return $this->tokenStream()->getTokenSource()->getTokenFactory();
    }

    /**
     * Tell our token source and error strategy about a new way to create tokens.
     */
    public function setTokenFactory(TokenFactory $factory): void
    {
        $this->tokenStream()->getTokenSource()->setTokenFactory($factory);
    }

    /**
     * The ATN with bypass alternatives is expensive to create so we create it
     * lazily.
     */
    public function getATNWithBypassAlts(): ATN
    {
        if (self::$bypassAltsAtnCache === null) {
            $deserializationOptions = new ATNDeserializationOptions();
            $deserializationOptions->setGenerateRuleBypassTransitions(true);
            self::$bypassAltsAtnCache = (new ATNDeserializer($deserializationOptions))
                ->deserialize($this->getSerializedATN());
        }

        return self::$bypassAltsAtnCache;
    }

    public function getErrorHandler(): ANTLRErrorStrategy
    {
        return $this->errorHandler;
    }

    public function setErrorHandler(ANTLRErrorStrategy $handler): void
    {
        $this->errorHandler = $handler;
    }

    /**
     * @return TokenStream|null
     */
    public function getInputStream(): ?IntStream
    {
        return $this->getTokenStream();
    }

    final public function setInputStream(IntStream $input): void
    {
        if (!$input instanceof TokenStream) {
            throw new \InvalidArgumentException('The stream must be a token stream.');
        }

        $this->setTokenStream($input);
    }

    public function getTokenStream(): ?TokenStream
    {
        return $this->input;
    }

    private function tokenStream(): TokenStream
    {
        if ($this->input === null) {
            throw new \LogicException('The current token stream is null.');
        }

        return $this->input;
    }

    /** Set the token stream and reset the parser. */
    public function setTokenStream(TokenStream $input): void
    {
        $this->input = null;
        $this->reset();
        $this->input = $input;
    }

    /**
     * Match needs to return the current input symbol, which gets put
     * into the label for the associated token ref; e.g., x=ID.
     */
    public function getCurrentToken(): ?Token
    {
        return $this->tokenStream()->LT(1);
    }

    private function token(): Token
    {
        $token = $this->getCurrentToken();

        if ($token === null) {
            throw new \LogicException('The current token is null.');
        }

        return $token;
    }

    final public function notifyErrorListeners(
        string $msg,
        ?Token $offendingToken = null,
        ?RecognitionException $e = null,
    ): void {
        if ($offendingToken === null) {
            $offendingToken = $this->token();
        }

        $this->syntaxErrors++;
        $line = $offendingToken->getLine();
        $charPositionInLine = $offendingToken->getCharPositionInLine();
        $listener = $this->getErrorListenerDispatch();
        $listener->syntaxError($this, $offendingToken, $line, $charPositionInLine, $msg, $e);
    }

    /**
     * Consume and return the {@see Parser::getCurrentToken()} current symbol.
     *
     * E.g., given the following input with `A` being the current
     * lookahead symbol, this function moves the cursor to `B` and returns
     * `A`.
     *
     * <pre>
     *  A B
     *  ^
     * </pre>
     *
     * If the parser is not in error recovery mode, the consumed symbol is added
     * to the parse tree using {@see ParserRuleContext::addTerminalNode()}, and
     * {@see ParseTreeListener::visitTerminal()} is called on any parse listeners.
     * If the parser is in error recovery mode, the consumed symbol is
     * added to the parse tree using {@see Parser::createErrorNode()} then
     * {@see ParserRuleContext::addErrorNode()} and
     * {@see ParseTreeListener::visitErrorNode()} is called on any parse
     * listeners.
     */
    public function consume(): Token
    {
        $o = $this->token();

        if ($o->getType() !== self::EOF) {
            $this->tokenStream()->consume();
        }

        if ($this->buildParseTree || \count($this->parseListeners) > 0) {
            if ($this->errorHandler->inErrorRecoveryMode($this)) {
                $node = $this->context()->addErrorNode($this->createErrorNode($this->context(), $o));

                foreach ($this->parseListeners as $listener) {
                    if ($node instanceof ErrorNode) {
                        $listener->visitErrorNode($node);
                    }
                }
            } else {
                $node = $this->context()->addTerminalNode($this->createTerminalNode($this->context(), $o));

                foreach ($this->parseListeners as $listener) {
                    if ($node instanceof TerminalNode) {
                        $listener->visitTerminal($node);
                    }
                }
            }
        }

        return $o;
    }

    /**
     * How to create a token leaf node associated with a parent.
     *
     * Typically, the terminal node to create is not a function of the parent.
     */
    public function createTerminalNode(ParserRuleContext $parent, Token $t): TerminalNode
    {
        return new TerminalNodeImpl($t);
    }

    /** How to create an error node, given a token, associated with a parent.
     *  Typically, the error node to create is not a function of the parent.
     *
     * @since 4.7
     */
    public function createErrorNode(ParserRuleContext $parent, Token $t): ErrorNode
    {
        return new ErrorNodeImpl($t);
    }

    protected function addContextToParseTree(): void
    {
        $parent = $this->context()->getParent();

        if ($parent === null) {
            return;
        }

        // add current context to parent if we have a parent
        if ($parent instanceof ParserRuleContext) {
            $parent->addChild($this->context());
        }
    }

    /**
     * Always called by generated parsers upon entry to a rule. Access field
     * {@see Parser::$ctx} get the current context.
     */
    public function enterRule(ParserRuleContext $localctx, int $state, int $ruleIndex): void
    {
        $this->setState($state);
        $this->ctx = $localctx;
        $this->context()->start = $this->tokenStream()->LT(1);

        if ($this->buildParseTree) {
            $this->addContextToParseTree();
        }

        $this->triggerEnterRuleEvent();
    }

    public function exitRule(): void
    {
        if ($this->matchedEOF) {
            // if we have matched EOF, it cannot consume past EOF so we use LT(1) here
            $this->context()->stop = $this->tokenStream()->LT(1); // LT(1) will be end of file
        } else {
            $this->context()->stop = $this->tokenStream()->LT(-1); // stop node is what we just matched
        }

        // trigger event on _ctx, before it reverts to parent
        $this->triggerExitRuleEvent();

        $this->setState($this->context()->invokingState);

        $parent = $this->context()->getParent();

        if ($parent === null || $parent instanceof ParserRuleContext) {
            $this->ctx = $parent;
        }
    }

    public function enterOuterAlt(ParserRuleContext $localctx, int $altNum): void
    {
        $localctx->setAltNumber($altNum);

        // if we have new localctx, make sure we replace existing ctx
        // that is previous child of parse tree
        if ($this->buildParseTree && $this->ctx !== $localctx) {
            /** @var ParserRuleContext|null $parent */
            $parent = $this->context()->getParent();

            if ($parent !== null) {
                $parent->removeLastChild();
                $parent->addChild($localctx);
            }
        }

        $this->ctx = $localctx;
    }

    /**
     * Get the precedence level for the top-most precedence rule.
     *
     * @return int The precedence level for the top-most precedence rule, or -1
     *             if the parser context is not nested within a precedence rule.
     */
    public function getPrecedence(): int
    {
        return $this->precedenceStack[\count($this->precedenceStack) - 1] ?? -1;
    }

    public function enterRecursionRule(ParserRuleContext $localctx, int $state, int $ruleIndex, int $precedence): void
    {
        $this->setState($state);
        $this->precedenceStack[] = $precedence;
        $this->ctx = $localctx;
        $this->context()->start = $this->tokenStream()->LT(1);

        $this->triggerEnterRuleEvent(); // simulates rule entry for left-recursive rules
    }

    /**
     * Like {@see Parser::enterRule()} but for recursive rules.
     *
     * Make the current context the child of the incoming `localctx`.
     */
    public function pushNewRecursionContext(ParserRuleContext $localctx, int $state, int $ruleIndex): void
    {
        $previous = $this->context();
        $previous->setParent($localctx);
        $previous->invokingState = $state;
        $previous->stop = $this->tokenStream()->LT(-1);

        $this->ctx = $localctx;
        $this->context()->start = $previous->start;

        if ($this->buildParseTree) {
            $this->context()->addChild($previous);
        }

        $this->triggerEnterRuleEvent(); // simulates rule entry for left-recursive rules
    }

    public function unrollRecursionContexts(?ParserRuleContext $parentctx): void
    {
        \array_pop($this->precedenceStack);

        $this->context()->stop = $this->tokenStream()->LT(-1);
        $retctx = $this->context(); // save current ctx (return value)

        // unroll so _ctx is as it was before call to recursive method

        if (\count($this->parseListeners) > 0) {
            while ($this->ctx !== $parentctx) {
                $this->triggerExitRuleEvent();
                $parent = $this->context()->getParent();

                if ($parent !== null && !$parent instanceof ParserRuleContext) {
                    throw new \LogicException('Unexpected context type.');
                }

                $this->ctx = $parent;
            }
        } else {
            $this->ctx = $parentctx;
        }

        // hook into tree
        $retctx->setParent($parentctx);

        if ($this->buildParseTree && $parentctx !== null) {
            // add return ctx into invoking rule's tree
            $parentctx->addChild($retctx);
        }
    }

    public function getInvokingContext(int $ruleIndex): ?RuleContext
    {
        $p = $this->ctx;
        while ($p !== null) {
            if ($p->getRuleIndex() === $ruleIndex) {
                return $p;
            }

            $p = $p->getParent();
        }

        return null;
    }

    public function getContext(): ?ParserRuleContext
    {
        return $this->ctx;
    }

    private function context(): ParserRuleContext
    {
        if ($this->ctx === null) {
            throw new \LogicException('The current context is null.');
        }

        return $this->ctx;
    }

    public function getCurrentRuleName(): string
    {
        return $this->getRuleNames()[$this->context()->getRuleIndex()] ?? '';
    }

    public function setContext(ParserRuleContext $ctx): void
    {
        $this->ctx = $ctx;
    }

    public function precpred(RuleContext $localctx, int $precedence): bool
    {
        return $precedence >= $this->getPrecedence();
    }

    public function inContext(string $context): bool
    {
        // TODO: useful in parser?
        return false;
    }

    /**
     * Checks whether or not `symbol` can follow the current state in the
     * ATN. The behavior of this method is equivalent to the following, but is
     * implemented such that the complete context-sensitive follow set does not
     * need to be explicitly constructed.
     *
     * <pre>
     * return getExpectedTokens().contains(symbol);
     * </pre>
     *
     * @param int $symbol The symbol type to check
     *
     * @return bool `true` if `symbol` can follow the current state in
     *              the ATN, otherwise `false`.
     */
    public function isExpectedToken(int $symbol): bool
    {
        $atn = $this->interpreter()->atn;
        /** @var ParserRuleContext $ctx */
        $ctx = $this->ctx;
        $s = $atn->states[$this->getState()];
        $following = $atn->nextTokens($s);

        if ($following->contains($symbol)) {
            return true;
        }

        if (!$following->contains(Token::EPSILON)) {
            return false;
        }

        while ($ctx !== null && $ctx->invokingState >= 0 && $following->contains(Token::EPSILON)) {
            /** @var ATNState $invokingState */
            $invokingState = $atn->states[$ctx->invokingState];
            /** @var RuleTransition $rt */
            $rt = $invokingState->getTransition(0);

            $following = $atn->nextTokens($rt->followState);

            if ($following->contains($symbol)) {
                return true;
            }

            $ctx = $ctx->getParent();
        }

        return $following->contains(Token::EPSILON) && $symbol === Token::EOF;
    }

    public function isMatchedEOF(): bool
    {
        return $this->matchedEOF;
    }

    /**
     * Computes the set of input symbols which could follow the current parser
     * state and context, as given by {@see #getState} and {@see #getContext},
     * respectively.
     *
     * @see ATN::getExpectedTokens()
     */
    public function getExpectedTokens(): IntervalSet
    {
        return $this->getATN()
            ->getExpectedTokens($this->getState(), $this->getContext());
    }

    public function getExpectedTokensWithinCurrentRule(): IntervalSet
    {
        $atn = $this->interpreter()->atn;
        $s = $atn->states[$this->getState()];

        return $atn->nextTokens($s);
    }

    /**
     * Get a rule's index (i.e., `RULE_ruleName` field) or -1 if not found.
     */
    public function getRuleIndex(string $ruleName): int
    {
        return $this->getRuleIndexMap()[$ruleName] ?? -1;
    }

    /**
     * Return the string array of the rule names in your parser instance
     * leading up to a call to the current rule. You could override if
     * you want more details such as the file/line info of where
     * in the ATN a rule is invoked.
     *
     * This is very useful for error messages.
     *
     * @return array<int, string>
     */
    public function getRuleInvocationStack(?RuleContext $p = null): array
    {
        $p ??= $this->ctx;
        $ruleNames = $this->getRuleNames();
        $stack = [];

        while ($p !== null) {
            // compute what follows who invoked us
            $ruleIndex = $p->getRuleIndex();

            if ($ruleIndex < 0) {
                $stack[] = 'n/a';
            } else {
                $stack[] = $ruleNames[$ruleIndex];
            }

            $p = $p->getParent();
        }

        return $stack;
    }

    /**
     * For debugging and other purposes.
     *
     * @return array<int, string>
     */
    public function getDFAStrings(): array
    {
        /** @var ParserATNSimulator $interp */
        $interp = $this->getInterpreter();
        $s = [];

        /** @var DFA $dfa */
        foreach ($interp->decisionToDFA as $dfa) {
            $s[] = $dfa->toString($this->getVocabulary());
        }

        return $s;
    }

    /** For debugging and other purposes. */
    public function dumpDFA(): void
    {
        /** @var ParserATNSimulator $interp */
        $interp = $this->getInterpreter();
        $seenOne = false;

        /** @var DFA $dfa */
        foreach ($interp->decisionToDFA as $dfa) {
            if ($dfa->states->isEmpty()) {
                continue;
            }

            if ($seenOne) {
                echo \PHP_EOL;
            }

            echo \sprintf("Decision %d:\n%s", $dfa->decision, $dfa->toString($this->getVocabulary()));

            $seenOne = true;
        }
    }

    public function getSourceName(): string
    {
        return $this->tokenStream()->getSourceName();
    }

    /** During a parse is sometimes useful to listen in on the rule entry and exit
     *  events as well as token matches. This is for quick and dirty debugging.
     */
    public function setTrace(bool $trace): void
    {
        if ($this->tracer !== null) {
            $this->removeParseListener($this->tracer);
        }

        if ($trace) {
            $this->tracer = new ParserTraceListener($this);
            $this->addParseListener($this->tracer);
        }
    }

    /**
     * Gets whether a {@see TraceListener} is registered as a parse listener
     * for the parser.
     *
     * @see Parser::setTrace()
     */
    public function isTrace(): bool
    {
        return $this->tracer !== null;
    }
}
