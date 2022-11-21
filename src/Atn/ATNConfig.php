<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Atn\SemanticContexts\SemanticContext;
use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContext;

/**
 * A tuple: (ATN state, predicted alt, syntactic, semantic context).
 * The syntactic context is a graph-structured stack node whose path(s)
 * to the root is the rule invocation(s) chain used to arrive at the state.
 * The semantic context is the tree of semantic predicates encountered
 * before reaching an ATN state.
 */
class ATNConfig implements Hashable
{
    /**
     * This field stores the bit mask for implementing the
     * {@see ATNConfig::isPrecedenceFilterSuppressed()} property as a bit within
     * the existing {@see ATNConfig::$reachesIntoOuterContext} field.
     */
    private const SUPPRESS_PRECEDENCE_FILTER = 0x40000000;

    /**
     * The ATN state associated with this configuration.
     */
    public ATNState $state;

    /**
     * What alt (or lexer rule) is predicted by this configuration.
     */
    public int $alt;

    /**
     * The stack of invoking states leading to the rule/states associated
     * with this config. We track only those contexts pushed during
     * execution of the ATN simulator.
     */
    public ?PredictionContext $context = null;

    /**
     * We cannot execute predicates dependent upon local context unless
     * we know for sure we are in the correct context. Because there is
     * no way to do this efficiently, we simply cannot evaluate
     * dependent predicates unless we are in the rule that initially
     * invokes the ATN simulator.
     *
     * closure() tracks the depth of how far we dip into the outer context:
     * `depth > 0`. Note that it may not be totally accurate depth since I
     * don't ever decrement. TODO: make it a boolean then
     *
     * For memory efficiency, {@see ATNConfig::isPrecedenceFilterSuppressed()}
     * is also backed by this field. Since the field is publicly accessible, the
     * highest bit which would not cause the value to become negative is used to
     * store this field. This choice minimizes the risk that code which only
     * compares this value to 0 would be affected by the new purpose of the
     * flag. It also ensures the performance of the existing {@see ATNConfig}
     * constructors as well as certain operations like
     * {@see ATNConfigSet::add()} method are completely unaffected by the change.
     */
    public int $reachesIntoOuterContext = 0;

    public SemanticContext $semanticContext;

    public function __construct(
        ?self $oldConfig,
        ?ATNState $state,
        ?PredictionContext $context = null,
        ?SemanticContext $semanticContext = null,
        ?int $alt = null,
    ) {
        if ($oldConfig === null) {
            if ($state === null) {
                throw new \InvalidArgumentException('ATN State cannot be null.');
            }

            $this->state = $state;
            $this->alt = $alt ?? 0;
            $this->context = $context;
            $this->semanticContext = $semanticContext ?? SemanticContext::none();
        } else {
            $this->state = $state ?? $oldConfig->state;
            $this->alt = $alt ?? $oldConfig->alt;
            $this->context = $context ?? $oldConfig->context;
            $this->semanticContext = $semanticContext ?? $oldConfig->semanticContext;
            $this->reachesIntoOuterContext = $oldConfig->reachesIntoOuterContext;
        }
    }

    /**
     * This method gets the value of the {@see ATNConfig::$reachesIntoOuterContext}
     * field as it existed prior to the introduction of the
     * {@see ATNConfig::isPrecedenceFilterSuppressed()} method.
     */
    public function getOuterContextDepth(): int
    {
        return $this->reachesIntoOuterContext & ~self::SUPPRESS_PRECEDENCE_FILTER;
    }

    public function isPrecedenceFilterSuppressed(): bool
    {
        return ($this->reachesIntoOuterContext & self::SUPPRESS_PRECEDENCE_FILTER) !== 0;
    }

    public function setPrecedenceFilterSuppressed(bool $value): void
    {
        if ($value) {
            $this->reachesIntoOuterContext |= self::SUPPRESS_PRECEDENCE_FILTER;
        } else {
            $this->reachesIntoOuterContext &= ~self::SUPPRESS_PRECEDENCE_FILTER;
        }
    }

    /**
     * An ATN configuration is equal to another if both have the same state, they
     * predict the same alternative, and syntactic/semantic contexts are the same.
     */
    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->alt === $other->alt
            && $this->isPrecedenceFilterSuppressed() === $other->isPrecedenceFilterSuppressed()
            && $this->semanticContext->equals($other->semanticContext)
            && Equality::equals($this->state, $other->state)
            && Equality::equals($this->context, $other->context);
    }

    public function hashCode(): int
    {
        return Hasher::hash(
            $this->state->stateNumber,
            $this->alt,
            $this->context,
            $this->semanticContext,
        );
    }

    public function toString(bool $showAlt): string
    {
        $buf = '(' . $this->state;

        if ($showAlt) {
            $buf .= ',' . $this->alt;
        }

        if ($this->context !== null) {
            $buf .= ',[' . $this->context . ']';
        }

        if (!$this->semanticContext->equals(SemanticContext::none())) {
            $buf .= ',' . $this->semanticContext;
        }

        if ($this->getOuterContextDepth() > 0) {
            $buf .= ',up=' . $this->getOuterContextDepth();
        }

        $buf .= ')';

        return $buf;
    }

    public function __toString(): string
    {
        return \sprintf(
            '(%s,%d%s%s%s)',
            $this->state,
            $this->alt,
            $this->context !== null ? ',[' . $this->context . ']' : '',
            $this->semanticContext->equals(SemanticContext::none())
                ? ''
                : ',' . $this->semanticContext,
            $this->reachesIntoOuterContext > 0 ? ',up=' . $this->reachesIntoOuterContext : '',
        );
    }
}
