<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\States;

final class StarLoopEntryState extends DecisionState
{
    /** @var StarLoopbackState|null */
    public $loopBackState;

    /**
     * Indicates whether this state can benefit from a precedence DFA during SLL
     * decision making.
     *
     * This is a computed property that is calculated during ATN deserialization
     * and stored for use in {@see ParserATNSimulator} and {@see ParserInterpreter}.
     *
     * @see DFA::isPrecedenceDfa()
     *
     * @var bool
     */
    public $isPrecedenceDecision = false;

    public function getStateType() : int
    {
        return self::STAR_LOOP_ENTRY;
    }
}
