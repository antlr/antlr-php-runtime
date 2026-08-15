<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;

/**
 * Treats two configurations as the same key when they share `(s, i, pi)` —
 * state, alternative and semantic context — ignoring the prediction context.
 *
 * This is the whole point of {@see ATNConfigSet::add()}: find the configuration
 * that already covers `(s, i, pi)` and merge the two prediction contexts into
 * it, rather than letting the set grow one entry per distinct context.
 *
 * The hash must therefore be built from exactly the fields
 * {@see ConfigEquivalence::equivalent()} compares. Including the context here —
 * as an earlier version of this class did by delegating to
 * {@see ATNConfig::hashCode()} — puts equivalent configurations in different
 * buckets, so the merge never fires and the set grows without bound.
 *
 * Ported from `ATNConfigSet.ConfigEqualityComparator`, including its 31-based
 * hash (the reference uses a plain accumulator here, not MurmurHash).
 */
final class ConfigEquivalence implements Equivalence
{
    public function equivalent(Hashable $left, Hashable $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (!$left instanceof ATNConfig || !$right instanceof ATNConfig) {
            return false;
        }

        return $left->state->stateNumber === $right->state->stateNumber
            && $left->alt === $right->alt
            && $left->semanticContext->equals($right->semanticContext);
    }

    public function hash(Hashable $value): int
    {
        if (!$value instanceof ATNConfig) {
            return 0;
        }

        $hash = 7;
        $hash = 31 * $hash + $value->state->stateNumber;
        $hash = 31 * $hash + $value->alt;

        return 31 * $hash + $value->semanticContext->hashCode();
    }

    public function equals(object $other): bool
    {
        return $other instanceof self;
    }
}
