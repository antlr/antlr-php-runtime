<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Exceptions;

use Antlr\Antlr4\Runtime\IntervalSet;
use Antlr\Antlr4\Runtime\IntStream;
use Antlr\Antlr4\Runtime\ParserRuleContext;
use Antlr\Antlr4\Runtime\Recognizer;
use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Token;

/**
 * The root of the ANTLR exception hierarchy. In general, ANTLR tracks just
 * 3 kinds of errors: prediction errors, failed predicate errors, and
 * mismatched input errors. In each case, the parser knows where it is
 * in the input, where it is in the ATN, the rule invocation stack,
 * and what kind of problem occurred.
 */
class RecognitionException extends \RuntimeException
{
    /**
     * The {@see Recognizer} where this exception originated.
     */
    private ?Recognizer $recognizer = null;

    private ?RuleContext $ctx = null;

    private ?IntStream $input = null;

    /**
     * The current {@see Token} when an error occurred. Since not all streams
     * support accessing symbols by index, we have to track the {@see Token}
     * instance itself.
     */
    private ?Token $offendingToken = null;

    private int $offendingState = -1;

    public function __construct(
        ?Recognizer $recognizer,
        ?IntStream $input,
        ?ParserRuleContext $ctx,
        string $message = '',
    ) {
        parent::__construct($message);

        $this->recognizer = $recognizer;
        $this->input = $input;
        $this->ctx = $ctx;

        if ($this->recognizer !== null) {
            $this->offendingState = $this->recognizer->getState();
        }
    }

    /**
     * Get the ATN state number the parser was in at the time the error
     * occurred. For {@see NoViableAltException} and
     * {@see LexerNoViableAltException} exceptions, this is the
     * {@see DecisionState} number. For others, it is the state whose outgoing
     * edge we couldn't match.
     *
     * If the state number is not known, this method returns -1.
     */
    public function getOffendingState(): int
    {
        return $this->offendingState;
    }

    public function setOffendingState(int $offendingState): void
    {
        $this->offendingState = $offendingState;
    }

    /**
     * If the state number is not known, this method returns -1.
     *
     * Gets the set of input symbols which could potentially follow the
     * previously matched symbol at the time this exception was thrown.
     *
     * If the set of expected tokens is not known and could not be computed,
     * this method returns `null`.
     *
     * @return IntervalSet|null The set of token types that could potentially follow
     *                          the current state in the ATN, or `null` if
     *                          the information is not available.
     */
    public function getExpectedTokens(): ?IntervalSet
    {
        if ($this->recognizer === null) {
            return null;
        }

        if ($this->ctx === null) {
            throw new \LogicException('Unexpected null context.');
        }

        return $this->recognizer->getATN()->getExpectedTokens($this->offendingState, $this->ctx);
    }

    /**
     * Gets the {@see RuleContext} at the time this exception was thrown.
     *
     * If the context is not available, this method returns `null`.
     *
     * @return RuleContext|null The {@see RuleContext} at the time this exception
     *                          was thrown. If the context is not available, this
     *                          method returns `null`.
     */
    public function getCtx(): ?RuleContext
    {
        return $this->ctx;
    }

    /**
     * Gets the input stream which is the symbol source for the recognizer where
     * this exception was thrown.
     *
     * If the input stream is not available, this method returns `null`.
     *
     * @return IntStream|null The input stream which is the symbol source for
     *                        the recognizer where this exception was thrown, or
     *                        `null` if the stream is not available.
     */
    public function getInputStream(): ?IntStream
    {
        return $this->input;
    }

    public function getOffendingToken(): ?Token
    {
        return $this->offendingToken;
    }

    public function setOffendingToken(?Token $offendingToken): void
    {
        $this->offendingToken = $offendingToken;
    }

    /**
     * Gets the {@see Recognizer} where this exception occurred.
     *
     * If the recognizer is not available, this method returns `null`.
     *
     * @return Recognizer|null The recognizer where this exception occurred, or
     *                         `null` if the recognizer is not available.
     */
    public function getRecognizer(): ?Recognizer
    {
        return $this->recognizer;
    }

    public function __toString(): string
    {
        return $this->message;
    }
}
