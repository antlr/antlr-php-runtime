<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error;

use Antlr\Antlr4\Runtime\Error\Exceptions\InputMismatchException;
use Antlr\Antlr4\Runtime\Error\Exceptions\ParseCancellationException;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Parser;
use Antlr\Antlr4\Runtime\ParserRuleContext;
use Antlr\Antlr4\Runtime\Token;

/**
 * This implementation of {@see ANTLRErrorStrategy} responds to syntax errors
 * by immediately canceling the parse operation with a
 * {@see ParseCancellationException}. The implementation ensures that the
 * {@see ParserRuleContext::$exception} field is set for all parse tree nodes
 * that were not completed prior to encountering the error.
 *
 * This error strategy is useful in the following scenarios.
 *
 * - Two-stage parsing: This error strategy allows the first stage of two-stage
 *    parsing to immediately terminate if an error is encountered, and immediately
 *    fall back to the second stage. In addition to avoiding wasted work by
 *    attempting to recover from errors here, the empty implementation of
 *    {@see BailErrorStrategy::sync()} improves the performance of the first stage.
 * - Silent validation: When syntax errors are not being reported or logged,
 *    and the parse result is simply ignored if errors occur, the
 *    {@see BailErrorStrategy} avoids wasting work on recovering from errors
 *    when the result will be ignored either way.</li>
 *
 * `$myparser->setErrorHandler(new BailErrorStrategy());`
 *
 * @see Parser::setErrorHandler()
 */
class BailErrorStrategy extends DefaultErrorStrategy
{
    /**
     * Instead of recovering from exception `e`, re-throw it wrapped
     * in a {@see ParseCancellationException} so it is not caught by the
     * rule function catches. Use {@see Exception::getCause()} to get the
     * original {@see RecognitionException}.
     */
    public function recover(Parser $recognizer, RecognitionException $e): void
    {
        $context = $recognizer->getContext();

        while ($context !== null) {
            if (!$context instanceof ParserRuleContext) {
                throw new \InvalidArgumentException('Unexpected context type.');
            }

            $context->exception = $e;
            $context = $context->getParent();
        }

        throw ParseCancellationException::from($e);
    }

    /**
     * Make sure we don't attempt to recover inline; if the parser successfully
     * recovers, it won't throw an exception.
     *
     * @throws ParseCancellationException
     */
    public function recoverInline(Parser $recognizer): Token
    {
        $e = new InputMismatchException($recognizer);

        for ($context = $recognizer->getContext(); $context; $context = $context->getParent()) {
            if (!$context instanceof ParserRuleContext) {
                throw new \InvalidArgumentException('Unexpected context type.');
            }

            $context->exception = $e;
        }

        throw ParseCancellationException::from($e);
    }

    /**
     * Make sure we don't attempt to recover from problems in subrules.
     */
    public function sync(Parser $recognizer): void
    {
        // No-op
    }
}
