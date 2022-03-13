<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Exceptions;

use Antlr\Antlr4\Runtime\Atn\ATNConfigSet;
use Antlr\Antlr4\Runtime\Parser;
use Antlr\Antlr4\Runtime\ParserRuleContext;
use Antlr\Antlr4\Runtime\Token;
use Antlr\Antlr4\Runtime\TokenStream;

/**
 * Indicates that the parser could not decide which of two or more paths
 * to take based upon the remaining input. It tracks the starting token
 * of the offending input and also knows where the parser was
 * in the various paths when the error. Reported by reportNoViableAlternative()
 */
class NoViableAltException extends RecognitionException
{
    /**
     * The token object at the start index; the input stream might
     * not be buffering tokens so get a reference to it. (At the
     * time the error occurred, of course the stream needs to keep a
     * buffer all of the tokens but later we might not have access to those.)
     */
    private ?Token $startToken = null;

    /**
     * Which configurations did we try at $input->index() that couldn't
     * match $input->LT(1)?
     */
    private ?ATNConfigSet $deadEndConfigs = null;

    public function __construct(
        Parser $recognizer,
        ?TokenStream $input = null,
        ?Token $startToken = null,
        ?Token $offendingToken = null,
        ?ATNConfigSet $deadEndConfigs = null,
        ?ParserRuleContext $ctx = null,
    ) {
        if ($ctx === null) {
            $ctx = $recognizer->getContext();
        }

        if ($offendingToken === null) {
            $offendingToken = $recognizer->getCurrentToken();
        }

        if ($startToken === null) {
            $startToken = $recognizer->getCurrentToken();
        }

        if ($input === null) {
            $input = $recognizer->getInputStream();
        }

        parent::__construct($recognizer, $input, $ctx);

        $this->deadEndConfigs = $deadEndConfigs;
        $this->startToken = $startToken;
        $this->setOffendingToken($offendingToken);
    }

    public function getStartToken(): ?Token
    {
        return $this->startToken;
    }

    public function getDeadEndConfigs(): ?ATNConfigSet
    {
        return $this->deadEndConfigs;
    }
}
