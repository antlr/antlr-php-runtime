<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\PredictionContexts;

use Antlr\Antlr4\Runtime\Comparison\MurmurHash;

/**
 * Used to cache {@see PredictionContext} objects. Its used for
 * the shared context cash associated with contexts in DFA states.
 * This cache can be used for both lexers and parsers.
 */
class SingletonPredictionContext extends PredictionContext
{
    public ?PredictionContext $parent = null;

    public int $returnState;

    public function __construct(int $returnState, ?PredictionContext $parent = null)
    {
        parent::__construct();

        $this->parent = $parent;
        $this->returnState = $returnState;
    }

    public static function create(?PredictionContext $parent, int $returnState): PredictionContext
    {
        // someone can pass in the bits of an array ctx that mean $
        if ($returnState === PredictionContext::EMPTY_RETURN_STATE && $parent === null) {
            return PredictionContext::empty();
        }

        return new SingletonPredictionContext($returnState, $parent);
    }

    public function getLength(): int
    {
        return 1;
    }

    public function getParent(int $index): ?PredictionContext
    {
        if ($index !== 0) {
            throw new \InvalidArgumentException('Singleton prediction context has only one parent.');
        }

        return $this->parent;
    }

    public function getReturnState(int $index): int
    {
        if ($index !== 0) {
            throw new \InvalidArgumentException('Singleton prediction context has only one parent.');
        }

        return $this->returnState;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        // `self`, not `static`: Java checks `instanceof SingletonPredictionContext`,
        // so a singleton and an `EmptyPredictionContext` remain comparable in both
        // directions. With `static` the comparison was asymmetric.
        if (!$other instanceof self) {
            return false;
        }

        // Java is `returnState == s.returnState && (parent != null && parent.equals(s.parent))`,
        // guarded by a hash comparison. Two null-parent singletons are
        // deliberately *not* equal there, and `parent.equals(null)` is false —
        // hence both null checks.
        //
        // The return state is compared before the hash guard: both are
        // necessary conditions so the outcome is identical, but in PHP the hash
        // is two method calls where the return state is an int comparison.
        if ($this->returnState !== $other->returnState) {
            return false;
        }

        if ($this->parent === null || $other->parent === null) {
            return false;
        }

        if (($this->cachedHashCode ?? $this->hashCode()) !== ($other->cachedHashCode ?? $other->hashCode())) {
            return false;
        }

        return $this->parent->equals($other->parent);
    }

    public function __toString(): string
    {
        $up = $this->parent === null ? '' : (string) $this->parent;

        if ($up === '') {
            if ($this->returnState === PredictionContext::EMPTY_RETURN_STATE) {
                return '$';
            }

            return '' . $this->returnState;
        }

        return '' . $this->returnState . ' ' . $up;
    }

    protected function computeHashCode(): int
    {
        // `PredictionContext.calculateEmptyHashCode()` / `calculateHashCode()`.
        return $this->parent === null
            ? MurmurHash::hash([], PredictionContext::INITIAL_HASH)
            : MurmurHash::hash([$this->parent, $this->returnState], PredictionContext::INITIAL_HASH);
    }
}
