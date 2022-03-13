<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Tree\ErrorNode;
use Antlr\Antlr4\Runtime\Tree\ParseTreeListener;
use Antlr\Antlr4\Runtime\Tree\TerminalNode;

final class ParserTraceListener implements ParseTreeListener
{
    public Parser $parser;

    public function __construct(Parser $parser)
    {
        $this->parser = $parser;
    }

    public function enterEveryRule(ParserRuleContext $context): void
    {
        $stream = $this->parser->getTokenStream();
        $token = $stream?->LT(1);

        echo \sprintf(
            'enter   %s, LT(1)=%s',
            $this->parser->getRuleNames()[$context->getRuleIndex()],
            $token === null? '' : $token->getText() ?? '',
        );
    }

    public function visitTerminal(TerminalNode $node): void
    {
        echo \sprintf(
            'consume %s rule %s',
            $node->getSymbol(),
            $this->parser->getCurrentRuleName(),
        );
    }

    public function exitEveryRule(ParserRuleContext $context): void
    {
        $stream = $this->parser->getTokenStream();
        $token = $stream?->LT(1);

        echo \sprintf(
            'exit    %s, LT(1)=%s',
            $this->parser->getRuleNames()[$context->getRuleIndex()],
            $token === null? '' : $token->getText() ?? '',
        );
    }

    public function visitErrorNode(ErrorNode $node): void
    {
        // No-op
    }
}
