<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Comparison;

final class Equality
{
    private function __construct()
    {
        // Prevent instantiation
    }

    public static function equals(mixed $left, mixed $right): bool
    {
        if ($left instanceof Equatable && $right instanceof Equatable) {
            return $left->equals($right);
        }

        if (\is_array($left) && \is_array($right)) {
            return self::deeplyEquals($left, $right);
        }

        return $left === $right;
    }

    /**
     * @param array<mixed> $left
     * @param array<mixed> $right
     */
    private static function deeplyEquals(array $left, array $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (\count($left) !== \count($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            // `array_key_exists`, not `isset`: a legitimately `null` element —
            // prediction-context parent arrays are full of them — would otherwise
            // read as a missing key and make two identical arrays compare unequal.
            if (!\array_key_exists($key, $right)) {
                return false;
            }

            if (!self::equals($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }
}
