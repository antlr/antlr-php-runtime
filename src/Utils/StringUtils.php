<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

final class StringUtils
{
    private const ENCODING = 'UTF-8';

    private function __construct()
    {
        // Prevent instantiation
    }

    public static function escapeWhitespace(string $string, bool $escapeSpaces = false): string
    {
        if ($string === '') {
            return $string;
        }

        $string = \str_replace(["\n", "\r", "\t"], ['\n', '\r', '\t'], $string);

        if ($escapeSpaces) {
            $string = \preg_replace('/ /', "\u00B7", $string);
        }

        return $string ?? '';
    }

    public static function char(int $code): string
    {
        return \mb_chr($code, self::ENCODING);
    }

    public static function codePoint(string $code): int
    {
        return \mb_ord($code, self::ENCODING);
    }
}
