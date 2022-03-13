<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

/**
 * Represents a token that was consumed during resynchronization
 * rather than during a valid match operation. For example,
 * we will create this kind of a node during single token insertion
 * and deletion as well as during "consume until error recovery set"
 * upon no viable alternative exceptions.
 */
class ErrorNodeImpl extends TerminalNodeImpl implements ErrorNode
{
    public function accept(ParseTreeVisitor $visitor): mixed
    {
        return $visitor->visitErrorNode($this);
    }
}
