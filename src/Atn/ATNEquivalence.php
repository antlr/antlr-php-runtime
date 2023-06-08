<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContext;


/**
 * An equivalence class for configurations, {@see ATNConfigSet}.
 */
class ATNEquivalence implements Equivalence
{
    
    public function equivalent(Hashable $left, Hashable $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (!($left instanceof ATNConfig) || !($right instanceof ATNConfig)) {
            return false;
        }

        return $left->alt === $right->alt
            && $left->semanticContext->equals($right->semanticContext)
            && Equality::equals($left->state, $right->state);
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
