<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Comparison;

interface Hashable extends Equatable
{
    public function hashCode(): int;
}
