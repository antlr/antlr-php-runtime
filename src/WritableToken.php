<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

interface WritableToken extends Token
{
    public function setText(string $text): void;

    public function setType(int $ttype): void;

    public function setLine(int $line): void;

    public function setCharPositionInLine(int $pos): void;

    public function setChannel(int $channel): void;

    public function setTokenIndex(int $index): void;
}
