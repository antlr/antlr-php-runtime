<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Equatable;
use Antlr\Antlr4\Runtime\Utils\StringUtils;

/**
 * This class implements the {@see IntSet} backed by a sorted array of
 * non-overlapping intervals. It is particularly efficient for representing
 * large collections of numbers, where the majority of elements appear as part
 * of a sequential range of numbers that are all part of the set. For example,
 * the set { 1, 2, 3, 4, 7, 8 } may be represented as { [1, 4], [7, 8] }.
 *
 * This class is able to represent sets containing any combination of values in
 * the range {@see Integer::MIN_VALUE} to {@see Integer::MAX_VALUE}
 * (inclusive).
 */
final class IntervalSet implements Equatable
{
    /** @var array<Interval> */
    protected array $intervals = [];

    protected bool $readOnly = false;

    /**
     * Create a set with a single element, el.
     */
    public static function fromInt(int $number): self
    {
        $set = new self();

        $set->addOne($number);

        return $set;
    }

    /**
     * Create a set with all ints within range [start..end] (inclusive).
     */
    public static function fromRange(int $start, int $end): self
    {
        $set = new self();

        $set->addRange($start, $end);

        return $set;
    }

    public function complement(IntervalSet $set): ?self
    {
        if ($set->isNull()) {
            return null;
        }

        return $set->subtract($this);
    }

    public function subtract(IntervalSet $set): self
    {
        if ($set->isNull()) {
            return $this;
        }

        return self::subtractSets($this, $set);
    }

    public function orSet(IntervalSet $other): self
    {
        $result = new self();

        $result->addSet($this);
        $result->addSet($other);

        return $result;
    }

    /**
     * Compute the set difference between two interval sets. The specific
     * operation is `left - right`. If either of the input sets is `null`,
     * it is treated as though it was an empty set.
     */
    public static function subtractSets(IntervalSet $left, IntervalSet $right): self
    {
        if ($left->isNull()) {
            return new self();
        }

        if ($right->isNull()) {
            // right set has no elements; just return the copy of the current set
            return $left;
        }

        $result = $left;
        $resultI = 0;
        $rightI = 0;
        while ($resultI < \count($result->intervals) && $rightI < \count($right->intervals)) {
            $resultInterval = $result->intervals[$resultI];
            $rightInterval = $right->intervals[$rightI];

            // operation: (resultInterval - rightInterval) and update indexes

            if ($rightInterval->stop < $resultInterval->start) {
                $rightI++;

                continue;
            }

            if ($rightInterval->start > $resultInterval->stop) {
                $resultI++;

                continue;
            }

            $beforeCurrent = null;
            $afterCurrent = null;
            if ($rightInterval->start > $resultInterval->start) {
                $beforeCurrent = new Interval($resultInterval->start, $rightInterval->start - 1);
            }

            if ($rightInterval->stop < $resultInterval->stop) {
                $afterCurrent = new Interval($rightInterval->stop + 1, $resultInterval->stop);
            }

            if ($beforeCurrent !== null) {
                if ($afterCurrent !== null) {
                    // split the current interval into two
                    $result->intervals[$resultI] = $beforeCurrent;
                    $result->intervals[$resultI + 1] = $afterCurrent;
                    $resultI++;
                    $rightI++;

                    continue;
                }

                // replace the current interval
                $result->intervals[$resultI] = $beforeCurrent;
                $resultI++;

                continue;
            }

            if ($afterCurrent !== null) {
                // replace the current interval
                $result->intervals[$resultI] = $afterCurrent;
                $rightI++;

                continue;
            }

            // remove the current interval (thus no need to increment resultI)
            \array_splice($result->intervals, $resultI, 1);

            continue;
        }

        // If rightI reached right.intervals.size(), no more intervals to subtract from result.
        // If resultI reached result.intervals.size(), we would be subtracting from an empty set.
        // Either way, we are done.
        return $result;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function setReadOnly(bool $readOnly): void
    {
        $this->readOnly = $readOnly;
    }

    public function first(): int
    {
        if (\count($this->intervals) === 0) {
            return Token::INVALID_TYPE;
        }

        return $this->intervals[0]->start;
    }

    public function addOne(int $value): void
    {
        $this->addInterval(new Interval($value, $value));
    }

    public function addRange(int $left, int $right): void
    {
        $this->addInterval(new Interval($left, $right));
    }

    protected function addInterval(Interval $addition): void
    {
        if ($this->readOnly) {
            throw new \InvalidArgumentException('Can\'t alter readonly IntervalSet.');
        }

        if ($addition->stop < $addition->start) {
            return;
        }

        // find position in list
        // Use iterators as we modify list in place
        for ($i = 0, $count = \count($this->intervals); $i < $count; $i++) {
            /** @var Interval $resilt */
            $resilt = $this->intervals[$i];

            if ($addition->equals($resilt)) {
                return;
            }

            if ($addition->adjacent($resilt) || !$addition->disjoint($resilt)) {
                // next to each other, make a single larger interval
                $bigger = $addition->union($resilt);
                $this->intervals[$i] = $bigger;

                // make sure we didn't just create an interval that
                // should be merged with next interval in list
                $i++;
                while ($i < \count($this->intervals)) {
                    $next = $this->intervals[$i];

                    if (!$bigger->adjacent($next) && $bigger->disjoint($next)) {
                        break;
                    }

                    // if we bump up against or overlap next, merge
                    \array_splice($this->intervals, $i, 1); // remove this one

                    $i--; // move backwards to what we just set

                    $this->intervals[$i] = $bigger->union($next); // set to 3 merged ones

                    $i++; // first call to next after previous duplicates the result
                }

                return;
            }

            if ($addition->startsBeforeDisjoint($resilt)) {
                // insert before r
                \array_splice($this->intervals, $i, 0, [$addition]);

                return;
            }

            // if disjoint and after r, a future iteration will handle it
        }

        // ok, must be after last interval (and disjoint from last interval) just add it
        $this->intervals[] = $addition;
    }

    public function addSet(IntervalSet $other): self
    {
        foreach ($other->intervals as $i) {
            $this->addInterval(new Interval($i->start, $i->stop));
        }

        return $this;
    }

    public function contains(int $item): bool
    {
        $count = \count($this->intervals);
        $left = 0;
        $right = $count - 1;
        // Binary search for the element in the (sorted, disjoint) array of intervals.

        while ($left <= $right) {
            $m = \intval($left + $right, 2);

            $interval = $this->intervals[$m];
            $start = $interval->start;
            $stop = $interval->stop;

            if ($stop < $item) {
                $left = $m + 1;
            } elseif ($start > $item) {
                $right = $m - 1;
            } else { // item >= start && item <= stop
                return true;
            }
        }

        return false;
    }

    public function length(): int
    {
        $length = 0;

        foreach ($this->intervals as $i) {
            $length += $i->getLength();
        }

        return $length;
    }

    public function removeOne(int $v): void
    {
        foreach ($this->intervals as $k => $i) {
            // intervals is ordered
            if ($v < $i->start) {
                return;
            }

            // check for single value range
            if ($v === $i->start && $v === $i->stop) {
                \array_splice($this->intervals, $k, 1);

                return;
            }

            // check for lower boundary
            if ($v === $i->start) {
                $this->intervals[$k] = new Interval($i->start + 1, $i->stop);

                return;
            }

            // check for upper boundary
            if ($v === $i->stop - 1) {
                $this->intervals[$k] = new Interval($i->start, $i->stop - 1);

                return;
            }

            // split existing range
            if ($v < $i->stop - 1) {
                $x = new Interval($i->start, $v);

                $i->start = $v + 1;

                \array_splice($this->intervals, $k, 0, [$x]);

                return;
            }
        }
    }

    public function isNull(): bool
    {
        return \count($this->intervals) === 0;
    }

    /**
     * Returns the maximum value contained in the set if not isNull().
     *
     * @return int The maximum value contained in the set.
     *
     * @throws \LogicException If set is empty.
     */
    public function getMaxElement(): int
    {
        if ($this->isNull()) {
            throw new \LogicException('The set is empty.');
        }

        return $this->intervals[\count($this->intervals)-1]->stop;
    }

    /**
     * Returns the minimum value contained in the set if not isNull().
     *
     * @return int The minimum value contained in the set.
     *
     * @throws \LogicException If set is empty.
     */
    public function getMinElement(): int
    {
        if ($this->isNull()) {
            throw new \LogicException('The set is empty.');
        }

        return $this->intervals[0]->start;
    }

    public function toStringChars(bool $elemAreChar): string
    {
        if (\count($this->intervals) === 0) {
            return '{}';
        }

        $buf = '';

        if ($this->length() > 1) {
            $buf .= '{';
        }

        $iter = new \ArrayIterator($this->intervals);

        while ($iter->valid()) {
            $interval = $iter->current();
            $iter->next();
            $start = $interval->start;
            $stop = $interval->stop;

            if ($start === $stop) {
                if ($start === Token::EOF) {
                    $buf .= '<EOF>';
                } elseif ($elemAreChar) {
                    $buf .= '\'' . StringUtils::char($start) . '\'';
                } else {
                    $buf .= $start;
                }
            } else {
                if ($elemAreChar) {
                    $buf .= \sprintf(
                        '\'%s\'..\'%s\'',
                        StringUtils::char($start),
                        StringUtils::char($stop),
                    );
                } else {
                    $buf .= \sprintf('%s..%s', $start, $stop);
                }
            }

            if ($iter->valid()) {
                $buf .= ', ';
            }
        }

        if ($this->length() > 1) {
            $buf .= '}';
        }

        return $buf;
    }

    public function toStringVocabulary(Vocabulary $vocabulary): string
    {
        if (\count($this->intervals) === 0) {
            return '{}';
        }

        $buf = '';
        if ($this->length() > 1) {
            $buf .= '{';
        }

        $iterator = new \ArrayIterator($this->intervals);

        while ($iterator->valid()) {
            $interval = $iterator->current();
            $iterator->next();
            $start = $interval->start;
            $stop = $interval->stop;

            if ($start === $stop) {
                $buf .= $this->elementName($vocabulary, $start);
            } else {
                for ($i = $start; $i <= $stop; $i++) {
                    if ($i > $start) {
                        $buf .= ', ';
                    }

                    $buf .= $this->elementName($vocabulary, $i);
                }
            }

            if ($iterator->valid()) {
                $buf .= ', ';
            }
        }

        if ($this->length() > 1) {
            $buf .= '}';
        }

        return $buf;
    }

    protected function elementName(Vocabulary $vocabulary, int $a): string
    {
        if ($a === Token::EOF) {
            return '<EOF>';
        }

        if ($a === Token::EPSILON) {
            return '<EPSILON>';
        }

        return $vocabulary->getDisplayName($a);
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->readOnly === $other->readOnly
            && Equality::equals($this->intervals, $other->intervals);
    }

    /**
     * @return array<int>
     */
    public function toArray(): array
    {
        $values = [];
        foreach ($this->intervals as $interval) {
            $start = $interval->start;
            $stop = $interval->stop;

            for ($value = $start; $value <= $stop; $value++) {
                $values[] = $value;
            }
        }

        return $values;
    }

    public function __toString(): string
    {
        return $this->toStringChars(false);
    }
}
