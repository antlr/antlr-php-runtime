<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\SemanticContexts;

use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Recognizer;
use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Utils\Set;

/**
 * A tree structure used to record the semantic context in which
 * an ATN configuration is valid. It's either a single predicate,
 * a conjunction `p1&&p2`, or a sum of products `p1 || p2`.
 *
 * I have scoped the {@see AndOperator}, {@see OrOperator}, and
 * {@see PrecedencePredicate} subclasses of {@see SemanticContext} within
 * the scope of this outer class.
 */
abstract class SemanticContext implements Hashable
{
    /**
     * The default {@see SemanticContext}, which is semantically equivalent to
     * a predicate of the form `{true}?`.
     */
    public static function none(): Predicate
    {
        static $none;

        return $none ??= new Predicate();
    }

    public static function andContext(?self $a, ?self $b): ?self
    {
        if ($a === null || $a === self::none()) {
            return $b;
        }

        if ($b === null || $b === self::none()) {
            return $a;
        }

        $result = new AndOperator($a, $b);

        return \count($result->operands) === 1 ? $result->operands[0] : $result;
    }

    public static function orContext(?self $a, ?self $b): ?self
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        if ($a === self::none() || $b === self::none()) {
            return self::none();
        }

        $result = new OrOperator($a, $b);

        return \count($result->operand) === 1 ? $result->operand[0] : $result;
    }

    /**
     * For context independent predicates, we evaluate them without a local
     * context (i.e., null context). That way, we can evaluate them without
     * having to create proper rule-specific context during prediction (as
     * opposed to the parser, which creates them naturally). In a practical
     * sense, this avoids a cast exception from RuleContext to myruleContext.
     *
     * For context dependent predicates, we must pass in a local context so that
     * references such as $arg evaluate properly as _localctx.arg. We only
     * capture context dependent predicates in the context in which we begin
     * prediction, so we passed in the outer context here in case of context
     * dependent predicate evaluation.
     */
    abstract public function eval(Recognizer $parser, RuleContext $parserCallStack): bool;

    /**
     * Evaluate the precedence predicates for the context and reduce the result.
     *
     * @param Recognizer $parser The parser instance.
     *
     * @return self|null The simplified semantic context after precedence predicates
     *                   are evaluated, which will be one of the following values.
     *
     *                   - {@see self::NONE()}: if the predicate simplifies to
     *                      `true` after precedence predicates are evaluated.
     *                   - `null`: if the predicate simplifies to `false` after
     *                      precedence predicates are evaluated.
     *                   - `this`: if the semantic context is not changed
     *                      as a result of precedence predicate evaluation.
     *                   - A non-`null` {@see SemanticContext}: if the new simplified
     *                      semantic context after precedence predicates are evaluated.
     */
    public function evalPrecedence(Recognizer $parser, RuleContext $parserCallStack): ?self
    {
        return $this;
    }

    /**
     * @return array<PrecedencePredicate>
     */
    public static function filterPrecedencePredicates(Set $set): array
    {
        $result = [];
        foreach ($set->getValues() as $context) {
            if ($context instanceof PrecedencePredicate) {
                $result[] = $context;
            }
        }

        return $result;
    }

    abstract public function __toString(): string;
}
