<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Dfa;

use Antlr\Antlr4\Runtime\Utils\StringUtils;
use Antlr\Antlr4\Runtime\VocabularyImpl;

final class LexerDFASerializer extends DFASerializer
{
    public function __construct(DFA $dfa)
    {
        parent::__construct($dfa, new VocabularyImpl());
    }

    protected function getEdgeLabel(int $i): string
    {
        return \sprintf('\'%s\'', StringUtils::char($i));
    }
}
