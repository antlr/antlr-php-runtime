<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\PredictionContexts;

use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Utils\Map;

/**
 * @extends Map<PredictionContext,PredictionContext>
 */
class IdentityHashMap extends Map
{
    public function __construct()
    {
        parent::__construct(new class implements Equivalence {
            public function equivalent(Hashable $left, Hashable $right): bool
            {
                if (! $left instanceof PredictionContext) {
                    return false;
                }

                if (! $right instanceof PredictionContext) {
                    return false;
                }

                return $left === $right;
            }

            public function hash(Hashable $value): int
            {
                if (! $value instanceof PredictionContext) {
                    return 0;
                }

                return $value->hashCode();
            }

            public function equals(object $other): bool
            {
                return $other instanceof self;
            }
        });
    }
}
