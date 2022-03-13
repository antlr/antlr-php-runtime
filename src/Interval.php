<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Comparison\Equatable;

/**
 * An immutable inclusive interval start..stop (both start and stop included).
 */
final class Interval implements Equatable
{
    public int $start;

    public int $stop;

    public function __construct(int $start, int $stop)
    {
        $this->start = $start;
        $this->stop = $stop;
    }

    public static function invalid(): self
    {
        static $invalid;

        return $invalid ?? $invalid = new Interval(-1, -2);
    }

    public function contains(int $item): bool
    {
        return $item >= $this->start && $item <= $this->stop;
    }

    public function getLength(): int
    {
        return $this->stop - $this->start + 1;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->start === $other->start
            && $this->stop === $other->stop;
    }

    /**
     * Does this start completely before other? Disjoint.
     */
    public function startsBeforeDisjoint(Interval $other): bool
    {
        return $this->start < $other->start && $this->stop < $other->start;
    }

    /**
     * Does this start at or before other? Nondisjoint.
     */
    public function startsBeforeNonDisjoint(Interval $other): bool
    {
        return $this->start <= $other->start && $this->stop >= $other->start;
    }

    /**
     * Does this.a start after other.b? May or may not be disjoint.
     */
    public function startsAfter(Interval $other): bool
    {
        return $this->start > $other->start;
    }

    /**
     * Does this start completely after other? Disjoint.
     */
    public function startsAfterDisjoint(Interval $other): bool
    {
        return $this->start > $other->stop;
    }

    /**
     * Does this start after other? NonDisjoint
     */
    public function startsAfterNonDisjoint(Interval $other): bool
    {
        // this.b >= other.b implied
        return $this->start > $other->start && $this->start <= $other->stop;
    }

    /**
     * Are both ranges disjoint? I.e., no overlap?
     */
    public function disjoint(Interval $other): bool
    {
        return $this->startsBeforeDisjoint($other) || $this->startsAfterDisjoint($other);
    }

    /**
     * Are two intervals adjacent such as 0..41 and 42..42?
     */
    public function adjacent(Interval $other): bool
    {
        return $this->start === $other->stop + 1 || $this->stop === $other->start - 1;
    }

    /**
     * Return the interval computed from combining this and other
     */
    public function union(Interval $other): self
    {
        return new self(\min($this->start, $other->start), \max($this->stop, $other->stop));
    }

    /**
     * Return the interval in common between this and o
     */
    public function intersection(Interval $other): self
    {
        return new self(\max($this->start, $other->start), \min($this->stop, $other->stop));
    }

    public function __toString(): string
    {
        if ($this->start === $this->stop) {
            return (string) $this->start;
        }

        return $this->start . '..' . $this->stop;
    }
}
