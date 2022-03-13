<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\SemanticContexts;

/**
 * This is the base class for semantic context "operators", which operate on
 * a collection of semantic context "operands".
 */
abstract class Operator extends SemanticContext
{
    /**
     * Gets the operands for the semantic context operator.
     *
     * @return array<SemanticContext> A collection of {@see SemanticContext}
     *                                operands for the operator.
     */
    abstract public function getOperands(): array;
}
