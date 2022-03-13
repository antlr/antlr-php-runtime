<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\PredictionContexts;

final class EmptyPredictionContext extends SingletonPredictionContext
{
    public function __construct()
    {
        parent::__construct(PredictionContext::EMPTY_RETURN_STATE);
    }

    public function getLength(): int
    {
        return 1;
    }

    public function isEmpty(): bool
    {
        return true;
    }

    public function getParent(int $index): ?PredictionContext
    {
        return null;
    }

    public function equals(object $other): bool
    {
        return $other instanceof self;
    }

    public function __toString(): string
    {
        return '$';
    }
}
