<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error;

use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Parser;
use Antlr\Antlr4\Runtime\Token;

/**
 * The interface for defining strategies to deal with syntax errors encountered
 * during a parse by ANTLR-generated parsers. We distinguish between three
 * different kinds of errors:
 *
 * - The parser could not figure out which path to take in the ATN (none of
 *    the available alternatives could possibly match)
 * - The current input does not match what we were looking for
 * - A predicate evaluated to false
 *
 * Implementations of this interface report syntax errors by calling
 * {@see Parser::notifyErrorListeners()}.
 *
 * TODO: what to do about lexers
 */
interface ANTLRErrorStrategy
{
    /**
     * Reset the error handler state for the specified `recognizer`.
     *
     * @param Parser $recognizer the parser instance
     */
    public function reset(Parser $recognizer): void;

    /**
     * This method is called when an unexpected symbol is encountered during an
     * inline match operation, such as {@see Parser::match()}. If the error
     * strategy successfully recovers from the match failure, this method
     * returns the {@see Token} instance which should be treated as the
     * successful result of the match.
     *
     * This method handles the consumption of any tokens - the caller should
     * not call {@see Parser::consume()} after a successful recovery.
     *
     * Note that the calling code will not report an error if this method
     * returns successfully. The error strategy implementation is responsible
     * for calling {@see Parser::notifyErrorListeners()} as appropriate.
     *
     * @param Parser $recognizer The parser instance
     *
     * @throws RecognitionException If the error strategy was not able to
     *                              recover from the unexpected input symbol.
     */
    public function recoverInline(Parser $recognizer): Token;

    /**
     * This method is called to recover from exception `e`. This method is
     * called after {@see ANTLRErrorStrategy::reportError()} by the default
     * exception handler generated for a rule method.
     *
     * @param Parser               $recognizer The parser instance
     * @param RecognitionException $e          The recognition exception to
     *                                         recover from
     *
     * @throws RecognitionException If the error strategy could not recover
     *                              from the recognition exception.
     *
     * @see ANTLRErrorStrategy::reportError
     */
    public function recover(Parser $recognizer, RecognitionException $e): void;

    /**
     * This method provides the error handler with an opportunity to handle
     * syntactic or semantic errors in the input stream before they result in a
     * {@see RecognitionException}.
     *
     * The generated code currently contains calls to
     * {@see ANTLRErrorStrategy::sync()} after entering the decision state
     * of a closure block (`(...)*` or `(...)+`).
     *
     * For an implementation based on Jim Idle's "magic sync" mechanism, see
     * {@see DefaultErrorStrategy::sync()}.
     *
     * @param Parser $recognizer the parser instance
     *
     * @throws RecognitionException If an error is detected by the error strategy
     *                              but cannot be automatically recovered at the
     *                              current state in the parsing process.
     *
     * @see DefaultErrorStrategy::sync()
     */
    public function sync(Parser $recognizer): void;

    /**
     * Tests whether or not `recognizer` is in the process of recovering
     * from an error. In error recovery mode, {@see Parser::consume()} adds
     * symbols to the parse tree by calling {@see Parser::createErrorNode()}
     * then {@see ParserRuleContext::addErrorNode()} instead of
     * {@see Parser::createTerminalNode()}.
     *
     * @param Parser $recognizer The parser instance.
     *
     * @return bool `true` if the parser is currently recovering from a parse
     *               error, otherwise `false`.
     */
    public function inErrorRecoveryMode(Parser $recognizer): bool;

    /**
     * This method is called by when the parser successfully matches an input symbol.
     *
     * @param Parser $recognizer The parser instance.
     */
    public function reportMatch(Parser $recognizer): void;

    /**
     * Report any kind of {@see RecognitionException}. This method is called by
     * the default exception handler generated for a rule method.
     *
     * @param Parser               $recognizer The parser instance.
     * @param RecognitionException $e          The recognition exception to report.
     */
    public function reportError(Parser $recognizer, RecognitionException $e): void;
}
