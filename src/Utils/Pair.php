<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Equatable;
use Antlr\Antlr4\Runtime\Comparison\MurmurHash;

final class Pair implements Equatable
{
    public ?object $a = null;

    public ?object $b = null;

    public function __construct(?object $a, ?object $b)
    {
        $this->a = $a;
        $this->b = $b;
    }

    public function equals(object $other): bool
    {
        if ($other === $this) {
            return true;
        }

        return $other instanceof self
            && Equality::equals($this->a, $other->a)
            && Equality::equals($this->b, $other->b);
    }

    public function hashCode(): int
    {
        return MurmurHash::hash([$this->a, $this->b]);
    }

    public function __toString(): string
    {
        // Java's `String.format("(%s, %s)", a, b)` includes the parentheses.
        return \sprintf(
            '(%s, %s)',
            $this->a === null
                ? 'null'
                : ($this->a instanceof \Stringable ? (string) $this->a : $this->a::class),
            $this->b === null
                ? 'null'
                : ($this->b instanceof \Stringable ? (string) $this->b : $this->b::class),
        );
    }
}
