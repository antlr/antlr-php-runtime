<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Exceptions;

use Antlr\Antlr4\Runtime\Atn\Transitions\PredicateTransition;
use Antlr\Antlr4\Runtime\Parser;

/**
 * A semantic predicate failed during validation. Validation of predicates
 * occurs when normally parsing the alternative just like matching a token.
 * Disambiguating predicate evaluation occurs when we test a predicate during
 * prediction.
 */
class FailedPredicateException extends RecognitionException
{
    private int $ruleIndex;

    private int $predicateIndex;

    private ?string $predicate = null;

    public function __construct(Parser $recognizer, string $predicate, ?string $message = null)
    {
        parent::__construct(
            $recognizer,
            $recognizer->getInputStream(),
            $recognizer->getContext(),
            $this->formatMessage($predicate, $message),
        );

        $interpreter = $recognizer->getInterpreter();

        if ($interpreter === null) {
            throw new \InvalidArgumentException('Unexpected null interpreter.');
        }

        $s = $interpreter->atn->states[$recognizer->getState()];

        $trans = $s->getTransition(0);

        if ($trans instanceof PredicateTransition) {
            $this->ruleIndex = $trans->ruleIndex;
            $this->predicateIndex = $trans->predIndex;
        } else {
            $this->ruleIndex = 0;
            $this->predicateIndex = 0;
        }

        $this->predicate = $predicate;
        $this->setOffendingToken($recognizer->getCurrentToken());
    }

    public function getRuleIndex(): int
    {
        return $this->ruleIndex;
    }

    public function getPredicateIndex(): int
    {
        return $this->predicateIndex;
    }

    public function getPredicate(): ?string
    {
        return $this->predicate;
    }

    public function formatMessage(string $predicate, ?string $message = null): string
    {
        if ($message !== null) {
            return $message;
        }

        return 'failed predicate: {' . $predicate . '}?';
    }
}
