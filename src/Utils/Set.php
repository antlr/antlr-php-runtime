<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

use Antlr\Antlr4\Runtime\Comparison\DefaultEquivalence;
use Antlr\Antlr4\Runtime\Comparison\Equatable;
use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;

/**
 * @template T of Hashable
 */
final class Set implements Equatable, \IteratorAggregate, \Countable
{
    /** @var array<int, array<T>> */
    private array $table = [];

    /** @var int<0, max>  */
    private int $size = 0;

    private DefaultEquivalence|Equivalence $equivalence;

    public function __construct(?Equivalence $equivalence = null)
    {
        $this->equivalence = $equivalence ?? new DefaultEquivalence();
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    public function count(): int
    {
        return $this->size;
    }

    public function contains(mixed $value): bool
    {
        if (!$value instanceof Hashable) {
            return false;
        }

        $hash = $this->equivalence->hash($value);

        if (!isset($this->table[$hash])) {
            return false;
        }

        foreach ($this->table[$hash] as $entry) {
            if ($this->equivalence->equivalent($value, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param T $value
     *
     * @return T
     */
    public function getOrAdd(Hashable $value): Hashable
    {
        $hash = $this->equivalence->hash($value);

        if (!isset($this->table[$hash])) {
            $this->table[$hash] = [];
        }

        foreach ($this->table[$hash] as $index => $entry) {
            if ($this->equivalence->equivalent($value, $entry)) {
                return $entry;
            }
        }

        $this->table[$hash][] = $value;

        $this->size++;

        return $value;
    }

    /**
     * @param T $value
     *
     * @return T|null
     */
    public function get(Hashable $value): ?Hashable
    {
        $hash = $this->equivalence->hash($value);

        if (!isset($this->table[$hash])) {
            return null;
        }

        foreach ($this->table[$hash] as $index => $entry) {
            if ($this->equivalence->equivalent($value, $entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param iterable<T> $values
     */
    public function addAll(iterable $values): void
    {
        foreach ($values as $value) {
            $this->add($value);
        }
    }

    /**
     * @param T $value
     */
    public function add(Hashable $value): bool
    {
        $hash = $this->equivalence->hash($value);

        if (!isset($this->table[$hash])) {
            $this->table[$hash] = [];
        }

        foreach ($this->table[$hash] as $index => $entry) {
            if ($this->equivalence->equivalent($value, $entry)) {
                return false;
            }
        }

        $this->table[$hash][] = $value;

        $this->size++;

        return true;
    }

    /**
     * @param T $value
     */
    public function remove(Hashable $value): void
    {
        $hash = $this->equivalence->hash($value);

        if (!isset($this->table[$hash])) {
            return;
        }

        foreach ($this->table[$hash] as $index => $entry) {
            if ($this->equivalence->equivalent($value, $entry)) {
                continue;
            }

            unset($this->table[$hash][$index]);

            if (\count($this->table[$hash]) === 0) {
                unset($this->table[$hash]);
            }

            if ($this->size > 0) {
                $this->size--;
            }

            return;
        }
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self
            || $this->size !== $other->size
            || !$this->equivalence->equals($other)) {
            return false;
        }

        foreach ($this->table as $hash => $bucket) {
            if (!isset($other->table[$hash]) || \count($bucket) !== \count($other->table[$hash])) {
                return false;
            }

            $otherBucket = $other->table[$hash];

            foreach ($bucket as $index => $value) {
                if (!$value->equals($otherBucket[$index])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<T>
     */
    public function getValues(): array
    {
        $values = [];
        foreach ($this->table as $bucket) {
            foreach ($bucket as $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @return \Iterator<T>
     */
    public function getIterator(): \Iterator
    {
        foreach ($this->table as $bucket) {
            foreach ($bucket as $value) {
                yield $value;
            }
        }
    }
}
