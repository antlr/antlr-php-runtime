<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Token;

/**
 * The basic notion of a tree has a parent, a payload, and a list of children.
 * It is the most abstract interface for all the trees used by ANTLR.
 */
interface Tree
{
    /**
     * The parent of this node. If the return value is null, then this
     * node is the root of the tree.
     */
    public function getParent(): ?Tree;

    /**
     * This method returns whatever object represents the data at this note. For
     * example, for parse trees, the payload can be a {@see Token} representing
     * a leaf node or a {@see RuleContext} object representing a rule
     * invocation. For abstract syntax trees (ASTs), this is a {@see Token}
     * object.
     */
    public function getPayload(): RuleContext|Token;

    /**
     * If there are children, get the `i`th value indexed from 0.
     */
    public function getChild(int $i, ?string $type = null): ?Tree;

    /**
     * How many children are there? If there is none, then this node represents a leaf node.
     */
    public function getChildCount(): int;

    /**
     * Print out a whole tree, not just a node, in LISP format
     * `(root child1 .. childN)`. Print just a node if this is a leaf.
     *
     * @param array<string>|null $ruleNames
     */
    public function toStringTree(?array $ruleNames = null): string;
}
