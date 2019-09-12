<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Error\Exceptions;

use Antlr\Antlr4\Runtime\Atn\ATNConfigSet;
use Antlr\Antlr4\Runtime\CharStream;
use Antlr\Antlr4\Runtime\Lexer;
use Antlr\Antlr4\Runtime\Utils\StringUtils;

class LexerNoViableAltException extends RecognitionException
{
    /**
     * Matching attempted at what input index?
     *
     * @var int
     */
    private $startIndex;

    /**
     * Which configurations did we try at $input->index() that couldn't match $input->LA(1)?
     *
     * @var ATNConfigSet
     */
    private $deadEndConfigs;

    public function __construct(Lexer $lexer, CharStream $input, int $startIndex, ATNConfigSet $deadEndConfigs)
    {
        parent::__construct($lexer, $input, null);

        $this->startIndex = $startIndex;
        $this->deadEndConfigs = $deadEndConfigs;
    }

    public function getDeadEndConfigs() : ATNConfigSet
    {
        return $this->deadEndConfigs;
    }

    public function __toString() : string
    {
        $symbol = '';
        $input = $this->getInputStream();

        if (!$input instanceof CharStream) {
            throw new \RuntimeException('Unexpected stream type.');
        }

        if ($input !== null && $this->startIndex >= 0 && $this->startIndex < $input->getLength()) {
            $symbol = $input->getText($this->startIndex, $this->startIndex);
            $symbol = StringUtils::escapeWhitespace($symbol, false);
        }

        return \sprintf('%s(\'%s\')', self::class, $symbol);
    }
}
