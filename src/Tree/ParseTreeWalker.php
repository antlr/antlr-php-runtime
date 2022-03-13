<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

use Antlr\Antlr4\Runtime\ParserRuleContext;

class ParseTreeWalker
{
    public static function default(): self
    {
        static $instance;

        return $instance ?? ($instance = new self());
    }

    public function walk(ParseTreeListener $listener, ParseTree $tree): void
    {
        if ($tree instanceof ErrorNode) {
            $listener->visitErrorNode($tree);

            return;
        }

        if ($tree instanceof TerminalNode) {
            $listener->visitTerminal($tree);

            return;
        }

        if (!$tree instanceof RuleNode) {
            throw new \InvalidArgumentException('Unexpected tree type.');
        }

        $this->enterRule($listener, $tree);

        $count = $tree->getChildCount();

        for ($i = 0; $i < $count; $i++) {
            $child = $tree->getChild($i);

            if ($child !== null) {
                $this->walk($listener, $child);
            }
        }

        $this->exitRule($listener, $tree);
    }

    /**
     * The discovery of a rule node, involves sending two events: the generic
     * {@see ParseTreeListener::enterEveryRule()} and a
     * {@see RuleContext}-specific event. First we trigger the generic and then
     * the rule specific. We to them in reverse order upon finishing the node.
     */
    protected function enterRule(ParseTreeListener $listener, RuleNode $ruleNode): void
    {
        /** @var ParserRuleContext $ctx */
        $ctx = $ruleNode->getRuleContext();

        $listener->enterEveryRule($ctx);

        $ctx->enterRule($listener);
    }

    protected function exitRule(ParseTreeListener $listener, RuleNode $ruleNode): void
    {
        /** @var ParserRuleContext $ctx */
        $ctx = $ruleNode->getRuleContext();

        $ctx->exitRule($listener);

        $listener->exitEveryRule($ctx);
    }
}
