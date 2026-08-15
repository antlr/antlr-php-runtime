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
        // Java's is `this == o`, which is safe there because the constructor is
        // private and `Instance` is the only instance. `PredictionContext::empty()`
        // is the only construction site here, so identity is equally safe — and
        // `instanceof self` would wrongly equate two distinct empty contexts if
        // one were ever created.
        return $this === $other;
    }

    public function __toString(): string
    {
        return '$';
    }
}
