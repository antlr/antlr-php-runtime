<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

use Antlr\Antlr4\Runtime\Comparison\Hasher;

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
     * @return array<int>
     */
    public function values(): array
    {
        return \array_keys($this->data);
    }

    public function minValue(): int
    {
        $values = $this->values();

        if (\count($values) === 0) {
            throw new \LogicException('BitSet is empty');
        }

        return \min($values);
    }

    public function hashCode(): int
    {
        return Hasher::hash(...$this->values());
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->data === $other->data;
    }

    public function length(): int
    {
        return \count($this->data);
    }

    public function __toString(): string
    {
        return \sprintf('{%s}', \implode(', ', $this->values()));
    }
}
