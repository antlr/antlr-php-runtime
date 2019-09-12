<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

/**
 * This interface defines the basic notion of a parse tree visitor. Generated
 * visitors implement this interface and the `XVisitor` interface for grammar `X`.
 */
interface ParseTreeVisitor
{
    /**
     * Visit a parse tree, and return a user-defined result of the operation.
     * Must return the result of visiting the parse tree.
     *
     * @param ParseTree $tree The {@see ParseTree} to visit.
     */
    public function visit(ParseTree $tree);

    /**
     * Visit the children of a node, and return a user-defined result of the
     * operation. Must return the result of visiting the parse tree.
     *
     * @param RuleNode $node The {@see RuleNode} whose children should be visited.
     */
    public function visitChildren(RuleNode $node);

    /**
     * Visit a terminal node, and return a user-defined result of the operation.
     * Must return the result of visiting the parse tree.
     *
     * @param TerminalNode $node The {@see TerminalNode} to visit.
     */
    public function visitTerminal(TerminalNode $node);

    /**
     * Visit an error node, and return a user-defined result of the operation.
     * Must return the result of visiting the parse tree.
     *
     * @param ErrorNode $node The {@see ErrorNode} to visit.
     */
    public function visitErrorNode(ErrorNode $node);
}
