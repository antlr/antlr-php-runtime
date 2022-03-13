<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

/**
 * A source of characters for an ANTLR lexer.
 */
interface CharStream extends IntStream
{
    /**
     * This method returns the text for a range of characters within this input
     * stream. This method is guaranteed to not throw an exception if the
     * specified `interval` lies entirely within a marked range. For more
     * information about marked ranges, see {@see IntStream::mark()}.
     *
     * @return string The text of the specified interval.
     */
    public function getText(int $start, int $stop): string;
}
