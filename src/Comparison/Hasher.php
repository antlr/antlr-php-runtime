<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Comparison;

final class Hasher
{
    private function __construct()
    {
        // Prevent instantiation
    }

    public static function hash(mixed ...$values): int
    {
        return self::hashArray($values);
    }

    /**
     * @param array<mixed> $values
     */
    private static function hashArray(array $values): int
    {
        $result = 1;
        foreach ($values as $value) {
            $elementHash = self::hashValue($value);
            $result = (31 * $result + $elementHash) & 0xffffffff;
        }

        return $result;
    }

    public static function hashValue(mixed $value): int
    {
        if (\is_string($value)) {
            return \crc32($value);
        }

        if (\is_int($value)) {
            return $value;
        }

        if ($value instanceof Hashable) {
            return $value->hashCode();
        }

        if (\is_object($value)) {
            return \spl_object_id($value);
        }

        if (\is_array($value)) {
            return self::hashArray($value);
        }

        if (\is_bool($value) || \is_float($value) || \is_resource($value)) {
            return (int) $value;
        }

        // Null
        return 0;
    }
}
