<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Comparison;

interface Equivalence extends Equatable
{
    public function equivalent(Hashable $left, Hashable $right): bool;

    public function hash(Hashable $value): int;
}
