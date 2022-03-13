<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Dfa;

use Antlr\Antlr4\Runtime\Atn\SemanticContexts\SemanticContext;

/**
 * Map a predicate to a predicted alternative.
 */
final class PredPrediction
{
    public SemanticContext $pred;

    public int $alt;

    public function __construct(SemanticContext $pred, int $alt)
    {
        $this->pred = $pred;
        $this->alt = $alt;
    }

    public function __toString(): string
    {
        return \sprintf('(%s, %d)', (string) $this->pred, $this->alt);
    }
}
