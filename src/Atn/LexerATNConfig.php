<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\Atn\States\DecisionState;
use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContext;

final class LexerATNConfig extends ATNConfig
{
    private ?LexerActionExecutor $lexerActionExecutor = null;

    private bool $passedThroughNonGreedyDecision;

    public function __construct(
        ?self $oldConfig,
        ?ATNState $state,
        ?PredictionContext $context = null,
        ?LexerActionExecutor $executor = null,
        ?int $alt = null,
    ) {
        parent::__construct($oldConfig, $state, $context, null, $alt);

        $this->lexerActionExecutor = $executor ?? ($oldConfig->lexerActionExecutor ?? null);
        $this->passedThroughNonGreedyDecision = $oldConfig !== null ?
            self::checkNonGreedyDecision($oldConfig, $this->state) :
            false;
    }

    public function getLexerActionExecutor(): ?LexerActionExecutor
    {
        return $this->lexerActionExecutor;
    }

    public function isPassedThroughNonGreedyDecision(): bool
    {
        return $this->passedThroughNonGreedyDecision;
    }

    public function hashCode(): int
    {
        return Hasher::hash(
            $this->state->stateNumber,
            $this->alt,
            $this->context,
            $this->semanticContext,
            $this->passedThroughNonGreedyDecision,
            $this->lexerActionExecutor,
        );
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        if (!parent::equals($other)) {
            return false;
        }

        if ($this->passedThroughNonGreedyDecision !== $other->passedThroughNonGreedyDecision) {
            return false;
        }

        return Equality::equals($this->lexerActionExecutor, $other->lexerActionExecutor);
    }

    private static function checkNonGreedyDecision(LexerATNConfig $source, ATNState $target): bool
    {
        return $source->passedThroughNonGreedyDecision || ($target instanceof DecisionState && $target->nonGreedy);
    }
}
