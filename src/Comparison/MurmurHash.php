<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Comparison;

/**
 * A port of the Java runtime's `org.antlr.v4.runtime.misc.MurmurHash`.
 *
 * Every `hashCode()` in the reference runtime is built from this function, so
 * matching it bit for bit is what makes PHP hash values comparable with Java's
 * — and, just as importantly, gives the runtime's {@see Set} and {@see Map} the
 * bucket distribution the ATN simulator assumes.
 *
 * Java exposes the algorithm as a fold — `initialize(seed)`, one `update()` per
 * word, then `finish(hash, wordCount)`:
 *
 *     int hash = MurmurHash.initialize(7);
 *     hash = MurmurHash.update(hash, state.stateNumber);
 *     hash = MurmurHash.update(hash, alt);
 *     hash = MurmurHash.finish(hash, 2);
 *
 * which is the same thing as MurmurHash3 x86 32-bit over those words as
 * little-endian 4-byte blocks. Since every caller here knows all of its words up
 * front, this class takes them as a list instead, which lets it hand the whole
 * buffer to PHP's native `murmur3a` — about seven times faster than doing the
 * mixing in PHP. The pure-PHP path below stays as the fallback for PHP 8.0,
 * where `murmur3a` is not yet available, and as executable documentation of what
 * the native call computes.
 */
final class MurmurHash
{
    private const DEFAULT_SEED = 0;

    private const MASK = 0xFFFFFFFF;

    private static ?bool $native = null;

    private function __construct()
    {
        // Prevent instantiation
    }

    /**
     * Hashes a list of values, mirroring Java's
     * `initialize -> update... -> finish` for the same list.
     *
     * `null` contributes 0 and a {@see Hashable} contributes its own
     * `hashCode()`, exactly as Java's `update(int, Object)` overload does.
     *
     * @param array<mixed> $values
     */
    public static function hash(array $values, int $seed = self::DEFAULT_SEED): int
    {
        $words = [];

        foreach ($values as $value) {
            // `hashCode()` is the hottest call in the simulator, and the vast
            // majority of words are already integers, so the common cases are
            // inlined rather than dispatched through `valueOf()`. `pack('V')`
            // truncates to 32 bits on its own, so no masking is needed here.
            if (\is_int($value)) {
                $words[] = $value;
            } elseif ($value instanceof Hashable) {
                $words[] = $value->hashCode();
            } else {
                $words[] = self::valueOf($value);
            }
        }

        self::$native ??= \in_array('murmur3a', \hash_algos(), true);

        return self::$native
            ? self::nativeHash($words, $seed)
            : self::fallbackHash($words, $seed);
    }

    /**
     * Java's `String.hashCode()`: `s[0]*31^(n-1) + ... + s[n-1]`, over UTF-16
     * code units, wrapping at 32 bits.
     */
    public static function hashString(string $value): int
    {
        $hash = 0;

        // Java hashes UTF-16 code units, so anything outside the BMP has to
        // contribute its surrogate pair rather than its code point.
        $utf16 = \mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        $length = \strlen($utf16);

        for ($i = 0; $i < $length; $i += 2) {
            $unit = (\ord($utf16[$i]) << 8) | \ord($utf16[$i + 1]);
            $hash = self::multiply($hash, 31) + $unit & self::MASK;
        }

        return self::toSigned($hash);
    }

    /**
     * @param array<int> $words
     */
    private static function nativeHash(array $words, int $seed): int
    {
        $digest = \hash(
            'murmur3a',
            \pack('V*', ...$words),
            true,
            ['seed' => $seed & self::MASK],
        );

        /** @var array{1: int} $unpacked */
        $unpacked = \unpack('N', $digest);

        return self::toSigned($unpacked[1]);
    }

    /**
     * The reference fold, kept verbatim so the native path above has something
     * to be checked against.
     *
     * @param array<int> $words
     */
    private static function fallbackHash(array $words, int $seed): int
    {
        $hash = $seed & self::MASK;

        foreach ($words as $word) {
            $k = self::multiply($word, 0xCC9E2D51);
            $k = self::rotateLeft($k, 15);
            $k = self::multiply($k, 0x1B873593);

            $hash = self::rotateLeft(($hash ^ $k) & self::MASK, 13);
            $hash = self::multiply($hash, 5) + 0xE6546B64 & self::MASK;
        }

        $hash = ($hash ^ \count($words) * 4) & self::MASK;
        $hash ^= $hash >> 16;
        $hash = self::multiply($hash, 0x85EBCA6B);
        $hash ^= $hash >> 13;
        $hash = self::multiply($hash, 0xC2B2AE35);
        $hash ^= $hash >> 16;

        return self::toSigned($hash & self::MASK);
    }

    private static function valueOf(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if ($value === null) {
            return 0;
        }

        if ($value instanceof Hashable) {
            return $value->hashCode();
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (\is_string($value)) {
            return self::hashString($value);
        }

        if (\is_object($value)) {
            // Java folds in `Object.hashCode()`, which is identity-based for a
            // type that does not override it. `Pair` holds exactly such values.
            return \spl_object_id($value);
        }

        throw new \InvalidArgumentException(\sprintf(
            'Cannot hash a value of type "%s".',
            \get_debug_type($value),
        ));
    }

    /**
     * Multiplies two 32-bit values with Java's wrap-around semantics.
     *
     * The operands are split into 16-bit halves because a full 32x32 product
     * overflows PHP's signed 64-bit integer and would silently become a float.
     */
    private static function multiply(int $a, int $b): int
    {
        $a &= self::MASK;
        $b &= self::MASK;

        $high = ($a >> 16) & 0xFFFF;
        $low = $a & 0xFFFF;

        return (($high * $b & 0xFFFF) << 16) + ($low * $b) & self::MASK;
    }

    private static function rotateLeft(int $value, int $bits): int
    {
        $value &= self::MASK;

        return ($value << $bits | $value >> 32 - $bits) & self::MASK;
    }

    private static function toSigned(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
