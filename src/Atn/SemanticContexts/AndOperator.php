<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\SemanticContexts;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Recognizer;
use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Utils\Set;

/**
 * A semantic context which is true whenever none of the contained contexts
 * is false.
 */
final class AndOperator extends Operator
{
    /** @var array<SemanticContext> */
    public array $operands;

    public function __construct(SemanticContext $a, SemanticContext $b)
    {
        /** @var Set<SemanticContext> $operands */
        $operands = new Set();

        if ($a instanceof self) {
            $operands->addAll($a->operands);
        } else {
            $operands->add($a);
        }

        if ($b instanceof self) {
            $operands->addAll($b->operands);
        } else {
            $operands->add($b);
        }

        /** @var array<PrecedencePredicate> $precedencePredicates */
        $precedencePredicates = self::filterPrecedencePredicates($operands);

        if (\count($precedencePredicates) !== 0) {
            // interested in the transition with the lowest precedence

            /** @var PrecedencePredicate $reduced */
            $reduced = self::minPredicate($precedencePredicates);

            $operands->add($reduced);
        }

        $this->operands = $operands->getValues();
    }

    /**
     * @return array<SemanticContext>
     */
    public function getOperands(): array
    {
        return $this->operands;
    }

    /**
     * {@inheritdoc}
     *
     * The evaluation of predicates by this context is short-circuiting, but
     * unordered.
     */
    public function eval(Recognizer $parser, RuleContext $parserCallStack): bool
    {
        foreach ($this->operands as $operand) {
            if (!$operand->eval($parser, $parserCallStack)) {
                return false;
            }
        }

        return true;
    }

    public function evalPrecedence(Recognizer $parser, RuleContext $parserCallStack): ?SemanticContext
    {
        $differs = false;

        $operands = [];
        foreach ($this->operands as $iValue) {
            $context = $iValue;
            $evaluated = $context->evalPrecedence($parser, $parserCallStack);
            $differs = $differs || $evaluated !== $context;

            // The AND context is false if any element is false
            if ($evaluated === null) {
                return null;
            }

            if ($evaluated !== SemanticContext::none()) {
                // Reduce the result by skipping true elements
                $operands[] = $evaluated;
            }
        }

        if (!$differs) {
            return $this;
        }

        // all elements were true, so the AND context is true
        if (\count($operands) === 0) {
            return SemanticContext::none();
        }

        $result = null;
        foreach ($operands as $operand) {
            $result = $result === null ? $operand : self::andContext($result, $operand);
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

        return Equality::equals($this->operands, $other->operands);
    }

    public function hashCode(): int
    {
        return Hasher::hash(41, $this->operands);
    }

    public function __toString(): string
    {
        $s = '';
        foreach ($this->operands as $o) {
            $s .= '&& ' . $o;
        }

        return \strlen($s) > 3 ? \substr($s, 3) : $s;
    }

    /**
     * @param array<PrecedencePredicate> $predicates
     */
    private static function minPredicate(array $predicates): object
    {
        $iterator = new \ArrayIterator($predicates);

        $candidate = $iterator->current();

        $iterator->next();

        while ($iterator->valid()) {
            $next = $iterator->current();

            $iterator->next();

            if ($next->compareTo($candidate) < 0) {
                $candidate = $next;
            }
        }

        return $candidate;
    }
}
