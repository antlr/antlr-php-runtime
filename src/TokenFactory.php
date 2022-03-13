<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Utils\Pair;

/**
 * The default mechanism for creating tokens. It's used by default in Lexer and
 * the error handling strategy (to create missing tokens). Notifying the parser
 * of a new factory means that it notifies its token source and error strategy.
 */
interface TokenFactory
{
    /**
     * This is the method used to create tokens in the lexer and in
     * the error handling strategy. If `text !== null`, than the start and stop
     * positions are wiped to -1 in the text override is set in the CommonToken.
     */
    public function createEx(
        Pair $source,
        int $type,
        ?string $text,
        int $channel,
        int $start,
        int $stop,
        int $line,
        int $charPositionInLine,
    ): Token;

    /**
     * Generically useful.
     */
    public function create(int $type, string $text): Token;
}
