<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Tree\ErrorNode;
use Antlr\Antlr4\Runtime\Tree\ParseTree;
use Antlr\Antlr4\Runtime\Tree\ParseTreeListener;
use Antlr\Antlr4\Runtime\Tree\TerminalNode;
use Antlr\Antlr4\Runtime\Tree\Tree;

/**
 * A rule invocation record for parsing.
 *
 * Contains all of the information about the current rule not stored
 * in the RuleContext. It handles parse tree children list, any ATN state
 * tracing, and the default values available for rule invocations: start, stop,
 * rule index, current alt number.
 *
 * Subclasses made for each rule and grammar track the parameters, return values,
 * locals, and labels specific to that rule. These are the objects
 * that are returned from rules.
 *
 * Note text is not an actual field of a rule return value; it is computed
 * from start and stop using the input stream's toString() method. I could
 * add a ctor to this so that we can pass in and store the input stream,
 * but I'm not sure we want to do that. It would seem to be undefined to get
 * the `text` property anyway if the rule matches tokens from multiple
 * input streams.
 *
 * I do not use getters for fields of objects that are used simply to group
 * values such as this aggregate. The getters/setters are there to satisfy
 * the superclass interface.
 */
class ParserRuleContext extends RuleContext
{
    /**
     * If we are debugging or building a parse tree for a visitor,
     * we need to track all of the tokens and rule invocations associated
     * with this rule's context. This is empty for parsing w/o tree constr.
     * operation because we don't the need to track the details about
     * how we parse this rule.
     *
     * @var array<ParseTree>|null
     */
    public ?array $children = null;

    public ?Token $start = null;

    public ?Token $stop = null;

    /**
     * The exception that forced this rule to return. If the rule successfully
     * completed, this is `null`.
     */
    public ?RecognitionException $exception = null;

    /**
     * COPY a context (I'm deliberately not using copy constructor) to avoid
     * confusion with creating node with parent. Does not copy children
     * (except error leaves).
     *
     * This is used in the generated parser code to flip a generic XContext
     * node for rule X to a YContext for alt label Y. In that sense, it is
     * not really a generic copy function.
     *
     * If we do an error sync() at start of a rule, we might add error nodes
     * to the generic XContext so this function must copy those nodes to
     * the YContext as well else they are lost!
     */
    public function copyFrom(ParserRuleContext $ctx): void
    {
        // from RuleContext
        $this->parentCtx = $ctx->parentCtx;
        $this->invokingState = $ctx->invokingState;
        $this->children = null;
        $this->start = $ctx->start;
        $this->stop = $ctx->stop;

        // copy any error nodes to alt label node
        if ($ctx->children !== null) {
            $this->children = [];

            // reset parent pointer for any error nodes
            foreach ($ctx->children as $child) {
                if ($child instanceof ErrorNode) {
                    $this->addErrorNode($child);
                }
            }
        }
    }

    public function enterRule(ParseTreeListener $listener): void
    {
        // No-op
    }

    public function exitRule(ParseTreeListener $listener): void
    {
        // No-op
    }

    public function addTerminalNode(TerminalNode $t): ParseTree
    {
        $t->setParent($this);

        return $this->addChild($t);
    }

    public function addErrorNode(ErrorNode $errorNode): ParseTree
    {
        $errorNode->setParent($this);

        return $this->addChild($errorNode);
    }

    /**
     * Add a parse tree node to this as a child. Works for
     * internal and leaf nodes. Does not set parent link;
     * other add methods must do that. Other addChild methods
     * call this.
     *
     * We cannot set the parent pointer of the incoming node
     * because the existing interfaces do not have a `setParent()`
     * method and I don't want to break backward compatibility for this.
     */
    public function addChild(ParseTree $child): ParseTree
    {
        if ($this->children === null) {
            $this->children = [];
        }

        $this->children[] = $child;

        return $child;
    }

    /**
     * Used by enterOuterAlt to toss out a RuleContext previously added as
     * we entered a rule. If we have # label, we will need to remove
     * generic `ruleContext` object.
     */
    public function removeLastChild(): void
    {
        if ($this->children !== null) {
            \array_pop($this->children);
        }
    }

    /**
     * @return RuleContext|null
     */
    public function getParent(): ?Tree
    {
        return $this->parentCtx;
    }

    /**
     * @return ParseTree|null
     */
    public function getChild(int $i, ?string $type = null): ?Tree
    {
        if ($this->children === null || $i < 0 || $i >= \count($this->children)) {
            return null;
        }

        if ($type === null) {
            return $this->children[$i];
        }

        foreach ($this->children as $child) {
            if ($child instanceof $type) {
                if ($i === 0) {
                    return $child;
                }

                $i--;
            }
        }

        return null;
    }

    public function getToken(int $ttype, int $i): ?TerminalNode
    {
        if ($this->children === null || $i < 0 || $i >= \count($this->children)) {
            return null;
        }

        foreach ($this->children as $child) {
            if ($child instanceof TerminalNode && $child->getSymbol()->getType() === $ttype) {
                if ($i === 0) {
                    return $child;
                }

                $i--;
            }
        }

        return null;
    }

    /**
     * @return array<TerminalNode>
     */
    public function getTokens(int $ttype): array
    {
        if ($this->children === null) {
            return [];
        }

        $tokens = [];
        foreach ($this->children as $child) {
            if ($child instanceof TerminalNode && $child->getSymbol()->getType() === $ttype) {
                $tokens[] = $child;
            }
        }

        return $tokens;
    }

    public function getTypedRuleContext(string $ctxType, int $i): ?ParseTree
    {
        return $this->getChild($i, $ctxType);
    }

    /**
     * @return array<ParseTree>
     */
    public function getTypedRuleContexts(string $ctxType): array
    {
        if ($this->children=== null) {
            return [];
        }

        $contexts = [];
        foreach ($this->children as $child) {
            if ($child instanceof $ctxType) {
                $contexts[] = $child;
            }
        }

        return $contexts;
    }

    public function getChildCount(): int
    {
        return $this->children !== null ? \count($this->children) : 0;
    }

    public function getSourceInterval(): Interval
    {
        if ($this->start === null || $this->stop === null) {
            return Interval::invalid();
        }

        return new Interval($this->start->getTokenIndex(), $this->stop->getTokenIndex());
    }

    /**
     * Get the initial token in this context.
     *
     * Note that the range from start to stop is inclusive, so for rules that
     * do not consume anything (for example, zero length or error productions)
     * this token may exceed stop.
     */
    public function getStart(): ?Token
    {
        return $this->start;
    }

    /**
     * Get the final token in this context.
     *
     * Note that the range from start to stop is inclusive, so for rules that
     * do not consume anything (for example, zero length or error productions)
     * this token may precede start.
     */
    public function getStop(): ?Token
    {
        return $this->stop;
    }
}
