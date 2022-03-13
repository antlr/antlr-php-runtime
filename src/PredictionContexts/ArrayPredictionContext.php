<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\PredictionContexts;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Hasher;

final class ArrayPredictionContext extends PredictionContext
{
    /**
     * Parent can be null only if full ctx mode and we make an array from
     * {@see ArrayPredictionContext::empty()} and non-empty. We merge
     * {@see ArrayPredictionContext::empty()} by using null parent and
     * returnState === {@see ArrayPredictionContext::EMPTY_RETURN_STATE}.
     *
     * @var array<PredictionContext|null>
     */
    public array $parents;

    /**
     * Sorted for merge, no duplicates; if present,
     * {@see ArrayPredictionContext::EMPTY_RETURN_STATE} is always last.
     *
     * @var array<int>
     */
    public array $returnStates;

    /**
     * @param array<PredictionContext|null> $parents
     * @param array<int>                    $returnStates
     */
    public function __construct(array $parents, array $returnStates)
    {
        parent::__construct();

        $this->parents = $parents;
        $this->returnStates = $returnStates;
    }

    public static function fromOne(SingletonPredictionContext $ctx): self
    {
        return new ArrayPredictionContext([$ctx->parent], [$ctx->returnState]);
    }

    /**
     * @param array<PredictionContext|null> $parents
     */
    public function withParents(array $parents): self
    {
        $clone = clone $this;
        $clone->parents = $parents;

        return $clone;
    }

    public function isEmpty(): bool
    {
        // since EMPTY_RETURN_STATE can only appear in the last position, we don't need to verify that size==1
        return $this->returnStates[0] === PredictionContext::EMPTY_RETURN_STATE;
    }

    public function getLength(): int
    {
        return \count($this->returnStates);
    }

    public function getParent(int $index): ?PredictionContext
    {
        return $this->parents[$index];
    }

    public function getReturnState(int $index): int
    {
        return $this->returnStates[$index];
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        if ($this->returnStates === $other->returnStates) {
            return false;
        }

        return Equality::equals($this->parents, $other->parents);
    }

    public function __toString(): string
    {
        if ($this->isEmpty()) {
            return '[]';
        }

        $string = '[';
        for ($i = 0, $count = \count($this->returnStates); $i < $count; $i++) {
            if ($i > 0) {
                $string .= ', ';
            }

            if ($this->returnStates[$i] === PredictionContext::EMPTY_RETURN_STATE) {
                $string .= '$';

                continue;
            }

            $string .= $this->returnStates[$i];

            if ($this->parents[$i] !== null) {
                $string .= ' ' . $this->parents[$i];
            } else {
                $string .= 'null';
            }
        }

        return $string . ']';
    }

    protected function computeHashCode(): int
    {
        return Hasher::hash($this->parents, $this->returnStates);
    }
}
