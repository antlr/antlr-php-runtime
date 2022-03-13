<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\SemanticContexts;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Recognizer;
use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Utils\Set;

/**
 * A semantic context which is true whenever at least one of the contained
 * contexts is true.
 */
final class OrOperator extends Operator
{
    /** @var array<SemanticContext> */
    public array $operand;

    public function __construct(SemanticContext $a, SemanticContext $b)
    {
        /** @var Set<SemanticContext> $operands */
        $operands = new Set();

        if ($a instanceof self) {
            foreach ($a->operand as $o) {
                $operands->add($o);
            }
        } else {
            $operands->add($a);
        }

        if ($b instanceof self) {
            foreach ($b->operand as $o) {
                $operands->add($o);
            }
        } else {
            $operands->add($b);
        }

        $precedencePredicates = self::filterPrecedencePredicates($operands);

        if (\count($precedencePredicates) !== 0) {
            // interested in the transition with the highest precedence
            \usort($precedencePredicates, static function (PrecedencePredicate $a, PrecedencePredicate $b) {
                return $a->precedence - $b->precedence;
            });
            $reduced = $precedencePredicates[\count($precedencePredicates) - 1];
            $operands->add($reduced);
        }

        $this->operand = $operands->getValues();
    }

    /**
     * @return array<SemanticContext>
     */
    public function getOperands(): array
    {
        return $this->operand;
    }

    /**
     * {@inheritdoc}
     *
     * The evaluation of predicates by this context is short-circuiting, but
     * unordered.
     */
    public function eval(Recognizer $parser, RuleContext $parserCallStack): bool
    {
        foreach ($this->operand as $operand) {
            if ($operand->eval($parser, $parserCallStack)) {
                return true;
            }
        }

        return false;
    }

    public function evalPrecedence(Recognizer $parser, RuleContext $parserCallStack): ?SemanticContext
    {
        $differs = false;

        $operands = [];
        foreach ($this->operand as $context) {
            $evaluated = $context->evalPrecedence($parser, $parserCallStack);
            $differs = $differs || $evaluated !== $context;

            if ($evaluated === SemanticContext::none()) {
                // The OR context is true if any element is true
                return SemanticContext::none();
            }

            if ($evaluated !== null) {
                // Reduce the result by skipping false elements
                $operands[] = $evaluated;
            }
        }

        if (!$differs) {
            return $this;
        }

        // all elements were false, so the OR context is false
        if (\count($operands) === 0) {
            return null;
        }

        $result = null;
        foreach ($operands as $operand) {
            $result = $result === null ? $operand : SemanticContext::orContext($result, $operand);
        }

        return $result;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return Equality::equals($this->operand, $other->operand);
    }

    public function hashCode(): int
    {
        return Hasher::hash(37, $this->operand);
    }

    public function __toString(): string
    {
        $s = '';
        foreach ($this->operand as $o) {
            $s .= '|| ' . $o;
        }

        return \strlen($s) > 3 ? \substr($s, 3) : $s;
    }
}
