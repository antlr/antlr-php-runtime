<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

use Antlr\Antlr4\Runtime\Comparison\DefaultEquivalence;
use Antlr\Antlr4\Runtime\Comparison\Equatable;
use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;

final class Map implements Equatable, \Countable, \IteratorAggregate
{
    /** @var array<int, array<array<Hashable, mixed>>> */
    private $table = [];

    /** @var int */
    private $size = 0;

    /** @var Equivalence */
    private $equivalence;

    public function __construct(?Equivalence $equivalence = null)
    {
        $this->equivalence = $equivalence ?? new DefaultEquivalence();
    }

    public function count() : int
    {
        return $this->size;
    }

    public function contains(Hashable $key) : bool
    {
        $hash = $this->equivalence->hash($key);

        if (!isset($this->table[$hash])) {
            return false;
        }

        foreach ($this->table[$hash] as [$entryKey]) {
            if ($this->equivalence->equivalent($key, $entryKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function get(Hashable $key)
    {
        $hash = $this->equivalence->hash($key);

        if (!isset($this->table[$hash])) {
            return null;
        }

        foreach ($this->table[$hash] as [$entryKey, $entryValue]) {
            if ($this->equivalence->equivalent($key, $entryKey)) {
                return $entryValue;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    public function put(Hashable $key, $value) : void
    {
        $hash = $this->equivalence->hash($key);

        if (!isset($this->table[$hash])) {
            $this->table[$hash] = [];
        }

        foreach ($this->table[$hash] as $index => [$entryKey]) {
            if ($this->equivalence->equivalent($key, $entryKey)) {
                $this->table[$hash][$index] = [$key, $value];

                return;
            }
        }

        $this->table[$hash][] = [$key, $value];

        $this->size++;
    }

    public function remove(Hashable $key) : void
    {
        $hash = $this->equivalence->hash($key);

        if (!isset($this->table[$hash])) {
            return;
        }

        foreach ($this->table[$hash] as $index => [$entryKey]) {
            if (!$this->equivalence->equivalent($key, $entryKey)) {
                continue;
            }

            unset($this->table[$hash][$index]);

            if (\count($this->table[$hash]) === 0) {
                unset($this->table[$hash]);
            }

            $this->size--;

            return;
        }
    }

    public function equals(object $other) : bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self
            || $this->size !== $other->size
            || !$this->equivalence->equals($other->equivalence)) {
            return false;
        }

        foreach ($this->table as $hash => $bucket) {
            if (!isset($other->table[$hash]) || \count($bucket) !== \count($other->table[$hash])) {
                return false;
            }

            $otherBucket = $other->table[$hash];

            foreach ($bucket as $index => [$key, $value]) {
                [$otherKey, $otherValue] = $otherBucket[$index];

                if (!$this->equivalence->equivalent($key, $otherKey)
                    || !self::isEqual($value, $otherValue)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<Hashable>
     */
    public function getKeys() : array
    {
        $values = [];
        foreach ($this->table as $bucket) {
            foreach ($bucket as [$key]) {
                $values[] = $key;
            }
        }

        return $values;
    }

    /**
     * @return array<mixed>
     */
    public function getValues() : array
    {
        $values = [];
        foreach ($this->table as $bucket) {
            foreach ($bucket as [, $value]) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function getIterator() : \Iterator
    {
        foreach ($this->table as $bucket) {
            foreach ($bucket as [$key, $value]) {
                yield $key => $value;
            }
        }
    }

    private static function isEqual($left, $right) : bool
    {
        if ($left instanceof Equatable && $right instanceof Equatable) {
            return $left->equals($right);
        }

        return $left === $right;
    }
}
