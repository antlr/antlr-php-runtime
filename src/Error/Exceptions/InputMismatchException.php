<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Exceptions;

use Antlr\Antlr4\Runtime\Parser;
use Antlr\Antlr4\Runtime\ParserRuleContext;

/**
 * This signifies any kind of mismatched input exceptions such as when
 * the current input does not match the expected token.
 */
class InputMismatchException extends RecognitionException
{
    public function __construct(Parser $recognizer, ?int $state = null, ?ParserRuleContext $ctx = null)
    {
        parent::__construct(
            $recognizer,
            $recognizer->getInputStream(),
            $ctx ?? $recognizer->getContext(),
        );

        if ($state !== null) {
            $this->setOffendingState($state);
        }

        $this->setOffendingToken($recognizer->getCurrentToken());
    }
}
