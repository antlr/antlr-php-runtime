<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\SemanticContexts;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Recognizer;
use Antlr\Antlr4\Runtime\RuleContext;

final class PrecedencePredicate extends SemanticContext
{
    public int $precedence;

    public function __construct(int $precedence = 0)
    {
        $this->precedence = $precedence;
    }

    public function eval(Recognizer $parser, RuleContext $parserCallStack): bool
    {
        return $parser->precpred($parserCallStack, $this->precedence);
    }

    public function evalPrecedence(Recognizer $parser, RuleContext $parserCallStack): ?SemanticContext
    {
        if ($parser->precpred($parserCallStack, $this->precedence)) {
            return SemanticContext::none();
        }

        return null;
    }

    public function hashCode(): int
    {
        return Hasher::hash(31, $this->precedence);
    }

    public function compareTo(PrecedencePredicate $other): int
    {
        return $this->precedence - $other->precedence;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->precedence === $other->precedence;
    }

    public function __toString(): string
    {
        return \sprintf('{%d>=prec}?', $this->precedence);
    }
}
