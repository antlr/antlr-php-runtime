<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

use Antlr\Antlr4\Runtime\Comparison\MurmurHash;

final class BitSet
{
    /** @var array<int, bool> */
    private array $data = [];

    public function add(int $value): void
    {
        $this->data[$value] = true;
    }

    public function or(BitSet $set): void
    {
        $this->data += $set->data;
    }

    public function remove(int $value): void
    {
        unset($this->data[$value]);
    }

    public function contains(int $value): bool
    {
        return \array_key_exists($value, $this->data);
    }

    /**
     * The set bits, in ascending order.
     *
     * A `BitSet` is ordered by bit position by definition, but the backing array
     * is keyed by bit index and so iterates in *insertion* order. Returning that
     * raw order made `{2, 1}` and `{1, 2}` distinguishable — visible to users,
     * because `DiagnosticErrorListener` formats an alternative set straight into
     * the `ambigAlts=` of a parser message.
     *
     * @return array<int>
     */
    public function values(): array
    {
        $values = \array_keys($this->data);

        \sort($values);

        return $values;
    }

    public function minValue(): int
    {
        if ($this->data === []) {
            throw new \LogicException('BitSet is empty');
        }

        // `nextSetBit(0)`: the lowest set bit, independent of insertion order.
        return \min(\array_keys($this->data));
    }

    public function hashCode(): int
    {
        // Hashed over the ordered bits so that equal sets hash alike — the same
        // hash/equals contract that `equals()` below now honours.
        return MurmurHash::hash($this->values());
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        // `===` on the backing arrays compares key *order* as well as content, so
        // two identical alternative sets built in different orders compared
        // unequal. Java's `BitSet.equals` is positional and order-free.
        return $this->values() === $other->values();
    }

    /**
     * The number of set bits — Java's `cardinality()`.
     *
     * Note this is not Java's `BitSet.length()`, which is the highest set bit
     * plus one. Every call site here means cardinality.
     */
    public function length(): int
    {
        return \count($this->data);
    }

    public function __toString(): string
    {
        return \sprintf('{%s}', \implode(', ', $this->values()));
    }
}
