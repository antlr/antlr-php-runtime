<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

use Antlr\Antlr4\Runtime\Atn\ATN;
use Antlr\Antlr4\Runtime\ParserRuleContext;
use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Token;
use Antlr\Antlr4\Runtime\Utils\StringUtils;

/**
 * A set of utility routines useful for all kinds of ANTLR trees.
 */
class Trees
{
    /**
     * Print out a whole tree in LISP form. {@see Trees::getNodeText()} is used
     * on the node payloads to get the text for the nodes. Detect parse trees
     * and extract data appropriately.
     *
     * @param array<string>|null $ruleNames
     */
    public static function toStringTree(Tree $tree, ?array $ruleNames = null): string
    {
        $string = self::getNodeText($tree, $ruleNames);
        $string = StringUtils::escapeWhitespace($string, false);

        $childCount = $tree->getChildCount();

        if ($childCount === 0) {
            return $string;
        }

        $result = '(' . $string . ' ';

        for ($i = 0; $i < $childCount; $i++) {
            $child = $tree->getChild($i);

            if ($child !== null) {
                $result .= ($i > 0 ? ' ' : '') . self::toStringTree($child, $ruleNames);
            }
        }

        return $result . ')';
    }

    /**
     * @param array<string>|null $ruleNames
     */
    public static function getNodeText(Tree $tree, ?array $ruleNames): string
    {
        if ($ruleNames !== null) {
            if ($tree instanceof RuleContext) {
                $ruleIndex = $tree->getRuleContext()->getRuleIndex();
                $ruleName = $ruleNames[$ruleIndex];
                $altNumber = $tree->getAltNumber();

                if ($altNumber !== ATN::INVALID_ALT_NUMBER) {
                    return \sprintf('%s:%s', $ruleName, $altNumber);
                }

                return $ruleName;
            }

            if ($tree instanceof ErrorNode) {
                return (string) $tree;
            }

            if ($tree instanceof TerminalNode) {
                return $tree->getSymbol()->getText() ?? '';
            }
        }

        // no recog for rule names
        $payload = $tree->getPayload();

        if ($payload instanceof Token) {
            return $payload->getText() ?? '';
        }

        return (string) $tree->getPayload();
    }

    /**
     * Return ordered list of all children of this node
     *
     * @return array<Tree|null>
     */
    public static function getChildren(Tree $tree): array
    {
        $list = [];
        for ($i=0; $i < $tree->getChildCount(); $i++) {
            $list[] = $tree->getChild($i);
        }

        return $list;
    }

    /**
     * Return a list of all ancestors of this node. The first node of list
     * is the root and the last is the parent of this node.
     *
     * @return array<Tree>
     */
    public static function getAncestors(Tree $tree): array
    {
        $ancestors = [];
        $tree = $tree->getParent();

        while ($tree !== null) {
            \array_unshift($ancestors, $tree);

            $tree = $tree->getParent();
        }

        return $ancestors;
    }

    /**
     * @return array<ParseTree>
     */
    public static function findAllTokenNodes(ParseTree $tree, int $ttype): array
    {
        return self::findAllNodes($tree, $ttype, true);
    }

    /**
     * @return array<ParseTree>
     */
    public static function findAllRuleNodes(ParseTree $tree, int $ruleIndex): array
    {
        return self::findAllNodes($tree, $ruleIndex, false);
    }

    /**
     * @return array<ParseTree>
     */
    public static function findAllNodes(ParseTree $tree, int $index, bool $findTokens): array
    {
        return self::findNodesInTree($tree, $index, $findTokens, []);
    }

    /**
     * @param array<ParseTree> $nodes
     *
     * @return array<ParseTree>
     */
    private static function findNodesInTree(ParseTree $tree, int $index, bool $findTokens, array $nodes): array
    {
        // check this node (the root) first
        if ($findTokens && $tree instanceof TerminalNode && $tree->getSymbol()->getType() === $index) {
            $nodes[] = $tree;
        } elseif (!$findTokens && $tree instanceof ParserRuleContext && $tree->getRuleIndex() === $index) {
            $nodes[] = $tree;
        }

        // check children
        for ($i = 0; $i < $tree->getChildCount(); $i++) {
            $child = $tree->getChild($i);

            if ($child !== null) {
                $nodes = self::findNodesInTree($child, $index, $findTokens, $nodes);
            }
        }

        return $nodes;
    }

    /**
     * @return array<ParseTree>
     */
    public static function descendants(ParseTree $tree): array
    {
        $nodes = [[$tree]];
        for ($i = 0; $i < $tree->getChildCount(); $i++) {
            $child = $tree->getChild($i);

            if ($child !== null) {
                $nodes[] = self::descendants($child);
            }
        }

        return \array_merge(...$nodes);
    }
}
