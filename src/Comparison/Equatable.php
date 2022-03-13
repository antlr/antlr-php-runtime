<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Comparison;

interface Equatable
{
    public function equals(object $other): bool;
}
