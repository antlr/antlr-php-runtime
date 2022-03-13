<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Listeners;

use Antlr\Antlr4\Runtime\Atn\ATNConfigSet;
use Antlr\Antlr4\Runtime\Dfa\DFA;
use Antlr\Antlr4\Runtime\Interval;
use Antlr\Antlr4\Runtime\Parser;
use Antlr\Antlr4\Runtime\Utils\BitSet;

/**
 * This implementation of {@see ANTLRErrorListener} can be used to identify
 * certain potential correctness and performance problems in grammars. "Reports"
 * are made by calling {@see Parser::notifyErrorListeners()} with the appropriate
 * message.
 *
 * - Ambiguities: These are cases where more than one path through the
 * grammar can match the input.
 *
 * - Weak context sensitivity: These are cases where full-context
 * prediction resolved an SLL conflict to a unique alternative which equaled the
 * minimum alternative of the SLL conflict.
 *
 * - Strong (forced) context sensitivity: These are cases where the
 * full-context prediction resolved an SLL conflict to a unique alternative,
 * and the minimum alternative of the SLL conflict was found to not be
 * a truly viable alternative. Two-stage parsing cannot be used for inputs where
 * this situation occurs.
 *
 * @author Sam Harwell
 */
class DiagnosticErrorListener extends BaseErrorListener
{
    /**
     * When `true`, only exactly known ambiguities are reported.
     */
    protected bool $exactOnly;

    public function __construct(bool $exactOnly = true)
    {
        $this->exactOnly = $exactOnly;
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
        if ($this->exactOnly && !$exact) {
            return;
        }

        $tokenStream = $recognizer->getTokenStream();

        $msg = \sprintf(
            'reportAmbiguity d=%s: ambigAlts=%s, input=\'%s\'',
            $this->getDecisionDescription($recognizer, $dfa),
            $this->getConflictingAlts($ambigAlts, $configs),
            $tokenStream === null ? '' : $tokenStream->getTextByInterval(new Interval($startIndex, $stopIndex)),
        );

        $recognizer->notifyErrorListeners($msg);
    }

    public function reportAttemptingFullContext(
        Parser $recognizer,
        DFA $dfa,
        int $startIndex,
        int $stopIndex,
        ?BitSet $conflictingAlts,
        ATNConfigSet $configs,
    ): void {
        $tokenStream = $recognizer->getTokenStream();

        $msg = \sprintf(
            'reportAttemptingFullContext d=%s, input=\'%s\'',
            $this->getDecisionDescription($recognizer, $dfa),
            $tokenStream === null ? '' : $tokenStream->getTextByInterval(new Interval($startIndex, $stopIndex)),
        );

        $recognizer->notifyErrorListeners($msg);
    }

    public function reportContextSensitivity(
        Parser $recognizer,
        DFA $dfa,
        int $startIndex,
        int $stopIndex,
        int $prediction,
        ATNConfigSet $configs,
    ): void {
        $tokenStream = $recognizer->getTokenStream();

        $msg = \sprintf(
            'reportContextSensitivity d=%s, input=\'%s\'',
            $this->getDecisionDescription($recognizer, $dfa),
            $tokenStream === null ? '' : $tokenStream->getTextByInterval(new Interval($startIndex, $stopIndex)),
        );

        $recognizer->notifyErrorListeners($msg);
    }

    protected function getDecisionDescription(Parser $recognizer, DFA $dfa): string
    {
        $decision = $dfa->decision;
        $ruleIndex = $dfa->atnStartState->ruleIndex;

        $ruleNames = $recognizer->getRuleNames();

        if ($ruleIndex < 0 || $ruleIndex >= \count($ruleNames)) {
            return (string) $decision;
        }

        $ruleName = $ruleNames[$ruleIndex];

        if (\strlen($ruleName) === 0) {
            return (string) $decision;
        }

        return \sprintf('%d (%s)', $decision, $ruleName);
    }

    /**
     * Computes the set of conflicting or ambiguous alternatives from a
     * configuration set, if that information was not already provided by the
     * parser.
     *
     * @param BitSet|null  $reportedAlts The set of conflicting or ambiguous
     *                                   alternatives, as reported by the parser.
     * @param ATNConfigSet $configs      The conflicting or ambiguous
     *                                   configuration set.
     *
     * @return BitSet `reportedAlts` if it is not `null`, otherwise returns
     *                the set of alternatives represented in `configs`.
     */
    protected function getConflictingAlts(?BitSet $reportedAlts, ATNConfigSet $configs): BitSet
    {
        if ($reportedAlts !== null) {
            return $reportedAlts;
        }

        $result = new BitSet();
        foreach ($configs->configs as $config) {
            $result->add($config->alt);
        }

        return $result;
    }
}
