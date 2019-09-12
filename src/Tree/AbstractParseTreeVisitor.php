<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

class AbstractParseTreeVisitor implements ParseTreeVisitor
{
    /**
     * {@inheritdoc}
     *
     * The default implementation calls {@see ParseTree::accept()} on the specified tree.
     *
     * @return mixed
     */
    public function visit(ParseTree $tree)
    {
        return $tree->accept($this);
    }

    /**
     * {@inheritdoc}
     *
     * The default implementation initializes the aggregate result to
     * {@see AbstractParseTreeVisitor::defaultResult()}. Before visiting each
     * child, it calls {@see AbstractParseTreeVisitor::shouldVisitNextChild()};
     * if the result is `false` no more children are visited and the current
     * aggregate result is returned. After visiting a child, the aggregate
     * result is updated by calling {@see AbstractParseTreeVisitor::aggregateResult()}
     * with the previous aggregate result and the result of visiting the child.
     *
     * The default implementation is not safe for use in visitors that modify
     * the tree structure. Visitors that modify the tree should override this
     * method to behave properly in respect to the specific algorithm in use.
     *
     * @return mixed
     */
    public function visitChildren(RuleNode $node)
    {
        $result = $this->defaultResult();

        $n = $node->getChildCount();

        for ($i=0; $i < $n; $i++) {
            if (!$this->shouldVisitNextChild($node, $result)) {
                break;
            }

            /** @var ParseTree $child */
            $child = $node->getChild($i);

            $childResult = $child->accept($this);

            $result = $this->aggregateResult($result, $childResult);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * The default implementation returns the result of
     * {@see AbstractParseTreeVisitor::defaultResult()}.
     *
     * @return mixed
     */
    public function visitTerminal(TerminalNode $node)
    {
        return $this->defaultResult();
    }

    /**
     * {@inheritdoc}
     *
     * The default implementation returns the result of
     * {@see AbstractParseTreeVisitor::defaultResult()}.
     *
     * @return mixed
     */
    public function visitErrorNode(ErrorNode $tree)
    {
        return $this->defaultResult();
    }

    /**
     * Gets the default value returned by visitor methods. This value is
     * returned by the default implementations of
     * {@see AbstractParseTreeVisitor::visitTerminal()},
     * {@see AbstractParseTreeVisitor::visitErrorNode()}.
     * The default implementation of {@see AbstractParseTreeVisitor::visitChildren()}
     * initializes its aggregate result to this value.
     *
     * The base implementation returns `null`.
     *
     * @return mixed
     */
    protected function defaultResult()
    {
        return null;
    }

    /**
     * Aggregates the results of visiting multiple children of a node. After
     * either all children are visited or
     * {@see AbstractParseTreeVisitor::shouldVisitNextChild()} returns `false`,
     * the aggregate value is returned as the result of
     * {@see AbstractParseTreeVisitor::visitChildren()}.
     *
     * The default implementation returns `nextResult`, meaning
     * {@see AbstractParseTreeVisitor::visitChildren()} will return the result
     * of the last child visited (or return the initial value if the node has
     * no children).
     *
     * @param mixed $aggregate  The previous aggregate value. In the default
     *                          implementation, the aggregate value is initialized
     *                          to {@see AbstractParseTreeVisitor::defaultResult()},
     *                          which is passed as the `aggregate` argument to
     *                          this method after the first child node is visited.
     * @param mixed $nextResult The result of the immediately preceeding call to
     *                          visit a child node.
     *
     * @return mixed
     */
    protected function aggregateResult($aggregate, $nextResult)
    {
        return $nextResult;
    }

    /**
     * This method is called after visiting each child in
     * {@see AbstractParseTreeVisitor::visitChildren()}. This method is first
     * called before the first child is visited; at that point `currentResult`
     * will be the initial value (in the default implementation, the initial
     * value is returned by a call to {@see AbstractParseTreeVisitor::defaultResult()}.
     * This method is not called after the last child is visited.
     *
     * The default implementation always returns `true`, indicating that
     * `visitChildren` should only return after all children are visited.
     * One reason to override this method is to provide a "short circuit"
     * evaluation option for situations where the result of visiting a single
     * child has the potential to determine the result of the visit operation as
     * a whole.
     *
     * @param RuleNode $node          The {@see RuleNode} whose children are
     *                                currently being visited.
     * @param mixed    $currentResult The current aggregate result of the children
     *                                visited to the current point.
     *
     * @return bool `true` to continue visiting children. Otherwise return `false`
     *              to stop visiting children and immediately return the current
     *              aggregate result from {@see AbstractParseTreeVisitor::visitChildren()}.
     */
    protected function shouldVisitNextChild(RuleNode $node, $currentResult) : bool
    {
        return true;
    }
}
