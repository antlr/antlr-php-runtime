<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

interface ErrorNode extends TerminalNode
{
    public function __toString(): string;
}
