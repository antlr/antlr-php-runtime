<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

use Antlr\Antlr4\Runtime\RuleContext;

interface ParseTree extends SyntaxTree
{
    /**
     * @return ParseTree|null
     */
    public function getParent(): ?Tree;

    /**
     * @return ParseTree|null
     */
    public function getChild(int $i, ?string $type = null): ?Tree;

    /**
     * Set the parent for this node.
     *
     * This is not backward compatible as it changes
     * the interface but no one was able to create custom
     * nodes anyway so I'm adding as it improves internal
     * code quality.
     *
     * One could argue for a restructuring of
     * the class/interface hierarchy so that
     * setParent, addChild are moved up to Tree
     * but that's a major change. So I'll do the
     * minimal change, which is to add this method.
     */
    public function setParent(RuleContext $parent): void;

    /**
     * The {@see ParseTreeVisitor} needs a double dispatch method.
     *
     * @template R
     *
     * @param ParseTreeVisitor<R> $visitor
     *
     * @return R
     */
    public function accept(ParseTreeVisitor $visitor): mixed;

    /**
     * Return the combined text of all leaf nodes. Does not get any
     * off-channel tokens (if any) so won't return whitespace and
     * comments if they are sent to parser on hidden channel.
     */
    public function getText(): ?string;

    /**
     * Specialize toStringTree so that it can print out more information
     * based upon the parser.
     *
     * @param array<string>|null $ruleNames
     */
    public function toStringTree(?array $ruleNames = null): string;
}
