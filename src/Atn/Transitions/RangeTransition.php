<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Transitions;

use Antlr\Antlr4\Runtime\Atn\States\ATNState;
use Antlr\Antlr4\Runtime\IntervalSet;
use Antlr\Antlr4\Runtime\Utils\StringUtils;

final class RangeTransition extends Transition
{
    public int $from;

    public int $to;

    public function __construct(ATNState $target, int $from, int $to)
    {
        parent::__construct($target);

        $this->from = $from;
        $this->to = $to;
    }

    public function label(): ?IntervalSet
    {
        return IntervalSet::fromRange($this->from, $this->to);
    }

    public function matches(int $symbol, int $minVocabSymbol, int $maxVocabSymbol): bool
    {
        return $symbol >= $this->from && $symbol <= $this->to;
    }

    public function getSerializationType(): int
    {
        return self::RANGE;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->from === $other->from
            && $this->to === $other->to
            && $this->target->equals($other->target);
    }

    public function __toString(): string
    {
        return \sprintf(
            '\'%s\'..\'%s\'',
            StringUtils::char($this->from),
            StringUtils::char($this->to),
        );
    }
}
