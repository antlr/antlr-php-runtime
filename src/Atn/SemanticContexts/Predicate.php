<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\SemanticContexts;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Recognizer;
use Antlr\Antlr4\Runtime\RuleContext;

final class Predicate extends SemanticContext
{
    public int $ruleIndex;

    public int $predIndex;

    public bool $isCtxDependent;

    public function __construct(int $ruleIndex = -1, int $predIndex = -1, bool $isCtxDependent = false)
    {
        $this->ruleIndex = $ruleIndex;
        $this->predIndex = $predIndex;
        $this->isCtxDependent = $isCtxDependent;
    }

    public function eval(Recognizer $parser, RuleContext $parserCallStack): bool
    {
        $localctx = $this->isCtxDependent ? $parserCallStack : null;

        return $parser->sempred($localctx, $this->ruleIndex, $this->predIndex);
    }

    public function hashCode(): int
    {
        return Hasher::hash($this->ruleIndex, $this->predIndex, $this->isCtxDependent);
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->ruleIndex === $other->ruleIndex
            && $this->predIndex === $other->predIndex
            && $this->isCtxDependent === $other->isCtxDependent;
    }

    public function __toString(): string
    {
        return \sprintf('{%d:%d}?', $this->ruleIndex, $this->predIndex);
    }
}
