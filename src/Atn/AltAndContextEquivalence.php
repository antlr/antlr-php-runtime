<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Comparison\MurmurHash;

/**
 * Keys configurations by `(state, context)` only, ignoring the alternative.
 *
 * {@see PredictionMode::getConflictingAltSubsets()} uses it to group the
 * configurations that differ solely in which alternative they predict.
 *
 * Ported from `PredictionMode.AltAndContextConfigEqualityComparator`, which is
 * likewise a singleton — this one used to be an anonymous class rebuilt on every
 * call, on a path that runs once per prediction.
 */
final class AltAndContextEquivalence implements Equivalence
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function equivalent(Hashable $left, Hashable $right): bool
    {
        return $left instanceof ATNConfig
            && $right instanceof ATNConfig
            && $left->state->stateNumber === $right->state->stateNumber
            && Equality::equals($left->context, $right->context);
    }

    public function hash(Hashable $value): int
    {
        if (!$value instanceof ATNConfig) {
            throw new \InvalidArgumentException('Unsupported value.');
        }

        // The hash is a function of the state number and the context only.
        return MurmurHash::hash([$value->state->stateNumber, $value->context], 7);
    }

    public function equals(object $other): bool
    {
        return $other instanceof self;
    }
}
