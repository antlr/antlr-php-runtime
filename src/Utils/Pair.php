<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Equatable;
use Antlr\Antlr4\Runtime\Comparison\Hasher;

final class Pair implements Equatable
{
    /** @var object|null */
    public $a;

    /** @var object|null */
    public $b;

    public function __construct(?object $a, ?object $b)
    {
        $this->a = $a;
        $this->b = $b;
    }

    public function equals(object $other) : bool
    {
        if ($other === $this) {
            return true;
        }

        return $other instanceof self
            && Equality::equals($this->a, $other->a)
            && Equality::equals($this->b, $other->b);
    }

    public function hashCode() : int
    {
        return Hasher::hash($this->a, $this->b);
    }

    public function __toString() : string
    {
        return \sprintf('%s, %s', (string) $this->a, (string) $this->b);
    }
}
