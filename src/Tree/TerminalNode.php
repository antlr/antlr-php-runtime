<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

use Antlr\Antlr4\Runtime\Token;

interface TerminalNode extends ParseTree
{
    public function getSymbol(): Token;
}
