<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Comparison;

final class DefaultEquivalence implements Equivalence
{
    public function equivalent(Hashable $left, Hashable $right): bool
    {
        return $left->equals($right);
    }

    public function hash(Hashable $value): int
    {
        return $value->hashCode();
    }

    public function equals(object $other): bool
    {
        return $other instanceof self;
    }
}
