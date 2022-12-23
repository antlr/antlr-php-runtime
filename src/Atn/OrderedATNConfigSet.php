<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;
use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Utils\Map;

final class OrderedATNConfigSet extends ATNConfigSet
{
    public function __construct()
    {
        parent::__construct();

        $this->configLookup = new Map(new class implements Equivalence {
            public function equivalent(Hashable $left, Hashable $right): bool
            {
                if ($left === $right) {
                    return true;
                }
                if ($left === null || $right === null) return false;
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
        });
    }
}
