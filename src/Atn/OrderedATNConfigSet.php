<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;

final class OrderedATNConfigSet extends ATNConfigSet
{
    public function __construct()
    {
        parent::__construct();

        $this->configLookup = new ConfigHashSet(new class implements Equivalence {
            public function equivalent(Hashable $left, Hashable $right): bool
            {
                if ($left === $right) {
                    return true;
                }

                /** @phpstan-ignore-next-line */
                if ($left === null) {
                    return false;
                }

                /** @phpstan-ignore-next-line */
                if ($right === null) {
                    return false;
                }

                return $left->equivalent($right);
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
