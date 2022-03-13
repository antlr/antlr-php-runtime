<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Listeners;

use Antlr\Antlr4\Runtime\Atn\ATNConfigSet;
use Antlr\Antlr4\Runtime\Dfa\DFA;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Parser;
use Antlr\Antlr4\Runtime\Recognizer;
use Antlr\Antlr4\Runtime\Utils\BitSet;

/**
 * This implementation of {@see ANTLRErrorListener} dispatches all calls to a
 * collection of delegate listeners. This reduces the effort required to support
 * multiple listeners.
 *
 * @author Sam Harwell
 */
class ProxyErrorListener implements ANTLRErrorListener
{
    /** @var array<ANTLRErrorListener> */
    private array $delegates;

    /**
     * @param array<ANTLRErrorListener> $delegates
     */
    public function __construct(array $delegates)
    {
        $this->delegates = $delegates;
    }

    public function syntaxError(
        Recognizer $recognizer,
        ?object $offendingSymbol,
        int $line,
        int $charPositionInLine,
        string $msg,
        ?RecognitionException $exception,
    ): void {
        foreach ($this->delegates as $listener) {
            $listener->syntaxError($recognizer, $offendingSymbol, $line, $charPositionInLine, $msg, $exception);
        }
    }

    public function reportAmbiguity(
        Parser $recognizer,
        DFA $dfa,
        int $startIndex,
        int $stopIndex,
        bool $exact,
        ?BitSet $ambigAlts,
        ATNConfigSet $configs,
    ): void {
        foreach ($this->delegates as $listener) {
            $listener->reportAmbiguity($recognizer, $dfa, $startIndex, $stopIndex, $exact, $ambigAlts, $configs);
        }
    }

    public function reportAttemptingFullContext(
        Parser $recognizer,
        DFA $dfa,
        int $startIndex,
        int $stopIndex,
        ?BitSet $conflictingAlts,
        ATNConfigSet $configs,
    ): void {
        foreach ($this->delegates as $listener) {
            $listener->reportAttemptingFullContext(
                $recognizer,
                $dfa,
                $startIndex,
                $stopIndex,
                $conflictingAlts,
                $configs,
            );
        }
    }

    public function reportContextSensitivity(
        Parser $recognizer,
        DFA $dfa,
        int $startIndex,
        int $stopIndex,
        int $prediction,
        ATNConfigSet $configs,
    ): void {
        foreach ($this->delegates as $listener) {
            $listener->reportContextSensitivity($recognizer, $dfa, $startIndex, $stopIndex, $prediction, $configs);
        }
    }
}
