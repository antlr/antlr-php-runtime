<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Dfa\DFAState;

/**
 * When we hit an accept state in either the DFA or the ATN, we
 * have to notify the character stream to start buffering characters
 * via {@see IntStream::mark()} and record the current state. The current sim state
 * includes the current index into the input, the current line,
 * and current character position in that line. Note that the Lexer is
 * tracking the starting line and characterization of the token. These
 * variables track the "state" of the simulator when it hits an accept state.
 *
 * We track these variables separately for the DFA and ATN simulation
 * because the DFA simulation often has to fail over to the ATN
 * simulation. If the ATN simulation fails, we need the DFA to fall
 * back to its previously accepted state, if any. If the ATN succeeds,
 * then the ATN does the accept and the DFA simulator that invoked it
 * can simply return the predicted token type.
 */
final class SimState
{
    private int $index = -1;

    private int $line = 0;

    private int $charPos = -1;

    private ?DFAState $dfaState = null;

    public function reset(): void
    {
        $this->index = -1;
        $this->line = 0;
        $this->charPos = -1;
        $this->dfaState = null;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function setIndex(int $index): void
    {
        $this->index = $index;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function setLine(int $line): void
    {
        $this->line = $line;
    }

    public function getCharPos(): int
    {
        return $this->charPos;
    }

    public function setCharPos(int $charPos): void
    {
        $this->charPos = $charPos;
    }

    public function getDfaState(): ?DFAState
    {
        return $this->dfaState;
    }

    public function setDfaState(?DFAState $dfaState): void
    {
        $this->dfaState = $dfaState;
    }
}
