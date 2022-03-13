<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Listeners;

use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Recognizer;

final class ConsoleErrorListener extends BaseErrorListener
{
    public function syntaxError(
        Recognizer $recognizer,
        ?object $offendingSymbol,
        int $line,
        int $charPositionInLine,
        string $msg,
        ?RecognitionException $exception,
    ): void {
        \fwrite(\STDERR, \sprintf("line %d:%d %s\n", $line, $charPositionInLine, $msg));
    }
}
