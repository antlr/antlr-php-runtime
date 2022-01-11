<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

use Antlr\Antlr4\Runtime\Interval;
use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Token;

class TerminalNodeImpl implements TerminalNode
{
    /** @var Token */
    public $symbol;

    /** @var ParseTree|null */
    public $parent;

    public function __construct(Token $symbol)
    {
        $this->symbol = $symbol;
    }

    public function getChild(int $i, ?string $type = null) : ?Tree
    {
        return null;
    }

    public function getSymbol() : Token
    {
        return $this->symbol;
    }

    /**
     * @return ParseTree|null
     */
    public function getParent() : ?Tree
    {
        return $this->parent;
    }

    public function setParent(RuleContext $parent) : void
    {
        $this->parent = $parent;
    }

    public function getPayload() : Token
    {
        return $this->symbol;
    }

    public function getSourceInterval() : Interval
    {
        if ($this->symbol === null) {
            return new Interval(-1, -2);
        }

        $tokenIndex = $this->symbol->getTokenIndex();

        return new Interval($tokenIndex, $tokenIndex);
    }

    public function getChildCount() : int
    {
        return 0;
    }

    /**
     * @return mixed
     */
    public function accept(ParseTreeVisitor $visitor)
    {
        return $visitor->visitTerminal($this);
    }

    public function getText() : ?string
    {
        return $this->symbol->getText();
    }

    /**
     * @param array<string>|null $ruleNames
     */
    public function toStringTree(?array $ruleNames = null) : string
    {
        return (string) $this;
    }

    public function __toString() : string
    {
        if ($this->symbol->getType() === Token::EOF) {
            return '<EOF>';
        }

        return $this->symbol->getText() ?? '';
    }
}
