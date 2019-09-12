<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Utils\Set;

final class OrderedATNConfigSet extends ATNConfigSet
{
    public function __construct()
    {
        parent::__construct();

        $this->configLookup = new Set();
    }
}
