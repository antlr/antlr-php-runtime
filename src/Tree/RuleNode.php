<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

use Antlr\Antlr4\Runtime\RuleContext;

interface RuleNode extends ParseTree
{
    public function getRuleContext(): RuleContext;
}
