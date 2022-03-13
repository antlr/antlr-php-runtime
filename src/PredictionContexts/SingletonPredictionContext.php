<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\PredictionContexts;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Hasher;

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

        if (!$other instanceof static) {
            return false;
        }

        if ($this->returnState !== $other->returnState) {
            return false;
        }

        return Equality::equals($this->parent, $other->parent);
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
        if ($this->parent === null) {
            return Hasher::hash(0);
        }

        return Hasher::hash($this->parent, $this->returnState);
    }
}
