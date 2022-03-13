<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Atn\ATN;
use Antlr\Antlr4\Runtime\Tree\ParseTree;
use Antlr\Antlr4\Runtime\Tree\ParseTreeVisitor;
use Antlr\Antlr4\Runtime\Tree\RuleNode;
use Antlr\Antlr4\Runtime\Tree\Tree;
use Antlr\Antlr4\Runtime\Tree\Trees;

/**
 * A rule context is a record of a single rule invocation.
 *
 * We form a stack of these context objects using the parent pointer. A parent
 * pointer of null indicates that the current context is the bottom of the stack.
 * The {@see ParserRuleContext} subclass as a children list so that we can turn
 * this data structure into a tree.
 *
 * The root node always has a null pointer and invokingState of -1.
 *
 * Upon entry to parsing, the first invoked rule function creates a context object
 * (a subclass specialized for that rule such as SContext) and makes it the root
 * of a parse tree, recorded by field Parser->ctx.
 *
 *     public final s(){
 *         SContext _localctx = new SContext(_ctx, getState()); <-- create new node
 *         enterRule(_localctx, 0, RULE_s);                     <-- push it
 *         ...
 *         exitRule();                                          <-- pop back to _localctx
 *         return _localctx;
 *     }
 *
 * A subsequent rule invocation of r from the start rule s pushes a new
 * context object for r whose parent points at s and use invoking state is
 * the state with r emanating as edge label.
 *
 * The invokingState fields from a context object to the root together
 * form a stack of rule indication states where the root (bottom of the stack)
 * has a -1 sentinel value. If we invoke start symbol s then call r1,
 * which calls r2, the  would look like this:
 *
 *     SContext[-1]   <- root node (bottom of the stack)
 *     R1Context[p]   <- p in rule s called r1
 *     R2Context[q]   <- q in rule r1 called r2
 *
 * So the top of the stack, `_ctx`, represents a call to the current rule
 * and it holds the return address from another rule that invoke to this rule.
 * To invoke a rule, we must always have a current context.
 *
 * The parent contexts are useful for computing lookahead sets and getting
 * error information.
 *
 * These objects are used during parsing and prediction. For the special case
 * of parsers, we use the subclass {@see ParserRuleContext}.
 */
class RuleContext implements RuleNode
{
    /**
     * The context that invoked this rule.
     */
    public ?RuleContext $parentCtx = null;

    /**
     * What state invoked the rule associated with this context?
     * The "return address" is the followState of invokingState. If parent
     * is null, this should be -1 this context object represents the start rule.
     */
    public int $invokingState = -1;

    public function __construct(?RuleContext $parent, ?int $invokingState = null)
    {
        $this->parentCtx = $parent;
        $this->invokingState = $invokingState ?? -1;
    }

    public static function emptyContext(): ParserRuleContext
    {
        static $empty;

        return $empty ?? ($empty = new ParserRuleContext(null));
    }

    public function depth(): int
    {
        $n = 0;
        $p = $this;

        while ($p !== null) {
            $p = $p->parentCtx;
            $n++;
        }

        return $n;
    }

    /**
     * A context is empty if there is no invoking state; meaning nobody call
     * current context.
     */
    public function isEmpty(): bool
    {
        return $this->invokingState === -1;
    }

    public function getSourceInterval(): Interval
    {
        return Interval::invalid();
    }

    public function getRuleContext(): RuleContext
    {
        return $this;
    }

    public function getPayload(): RuleContext
    {
        return $this;
    }

    /**
     * Return the combined text of all child nodes. This method only considers
     * tokens which have been added to the parse tree.
     *
     * Since tokens on hidden channels (e.g. whitespace or comments) are not
     * added to the parse trees, they will not appear in the output
     * of this method.
     */
    public function getText(): string
    {
        $text = '';

        for ($i = 0, $count = $this->getChildCount(); $i < $count; $i++) {
            $child = $this->getChild($i);

            if ($child !== null) {
                $text .= $child->getText();
            }
        }

        return $text;
    }

    public function getRuleIndex(): int
    {
        return -1;
    }

    /**
     * For rule associated with this parse tree internal node, return the outer
     * alternative number used to match the input. Default implementation
     * does not compute nor store this alt num. Create a subclass of
     * {@see ParserRuleContext} with backing field and set option
     * `contextSuperClass` to set it.
     */
    public function getAltNumber(): int
    {
        return ATN::INVALID_ALT_NUMBER;
    }

    /**
     * Set the outer alternative number for this context node. Default
     * implementation does nothing to avoid backing field overhead for trees
     * that don't need it. Create a subclass of {@see ParserRuleContext} with backing
     * field and set option `contextSuperClass`.
     */
    public function setAltNumber(int $altNumber): void
    {
        // Override as needed
    }

    /**
     * @return RuleContext|null
     */
    public function getParent(): ?Tree
    {
        return $this->parentCtx;
    }

    public function setParent(?RuleContext $ctx): void
    {
        $this->parentCtx = $ctx;
    }

    /**
     * @return ParseTree|null
     */
    public function getChild(int $i, ?string $type = null): ?Tree
    {
        return null;
    }

    public function getChildCount(): int
    {
        return 0;
    }

    public function accept(ParseTreeVisitor $visitor): mixed
    {
        return $visitor->visitChildren($this);
    }

    /**
     * Print out a whole tree, not just a node, in LISP format
     * (root child1 .. childN). Print just a node if this is a leaf.
     *
     * @param array<string>|null $ruleNames
     */
    public function toStringTree(?array $ruleNames = null): string
    {
        return Trees::toStringTree($this, $ruleNames);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @param array<string>|null $ruleNames
     */
    public function toString(?array $ruleNames = null, ?RuleContext $stop = null): string
    {
        $p = $this;
        $string = '[';

        while ($p !== null && $p !== $stop) {
            if ($ruleNames === null) {
                if (!$p->isEmpty()) {
                    $string .= $p->invokingState;
                }
            } else {
                $ri = $p->getRuleIndex();
                $ruleName = $ri >= 0 && $ri < \count($ruleNames) ? $ruleNames[$ri] : (string) $ri;
                $string .= $ruleName;
            }

            if ($p->parentCtx !== null && ($ruleNames !== null || !$p->parentCtx->isEmpty())) {
                $string .= ' ';
            }

            $p = $p->parentCtx;
        }

        return $string . ']';
    }
}
