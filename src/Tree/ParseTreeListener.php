<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

use Antlr\Antlr4\Runtime\ParserRuleContext;

interface ParseTreeListener
{
    public function visitTerminal(TerminalNode $node): void;

    public function visitErrorNode(ErrorNode $node): void;

    public function enterEveryRule(ParserRuleContext $ctx): void;

    public function exitEveryRule(ParserRuleContext $ctx): void;
}
