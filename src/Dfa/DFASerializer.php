<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Dfa;

use Antlr\Antlr4\Runtime\Atn\ATNSimulator;
use Antlr\Antlr4\Runtime\Vocabulary;

/**
 * A DFA walker that knows how to dump them to serialized strings.
 */
class DFASerializer
{
    /** @var DFA */
    private $dfa;

    /** @var Vocabulary */
    private $vocabulary;

    public function __construct(DFA $dfa, Vocabulary $vocabulary)
    {
        $this->dfa = $dfa;
        $this->vocabulary = $vocabulary;
    }

    public function toString() : string
    {
        if ($this->dfa->s0 === null) {
            return '';
        }

        $string = '';

        /** @var DFAState $state */
        foreach ($this->dfa->getStates() as $state) {
            $count = $state->edges === null ? 0 : $state->edges->count();

            for ($i = 0; $i < $count; $i++) {
                /** @var DFAState $t */
                $t = $state->edges[$i];

                if ($t !== null && $t->stateNumber !== 0x7FFFFFFF) {
                    $string .= $this->getStateString($state);

                    $label = $this->getEdgeLabel($i);

                    $string .= \sprintf("-%s->%s\n", $label, $this->getStateString($t));
                }
            }
        }

        return $string;
    }

    protected function getEdgeLabel(int $i) : string
    {
        return $this->vocabulary->getDisplayName($i - 1);
    }

    protected function getStateString(DFAState $state) : string
    {
        if ($state->equals(ATNSimulator::error())) {
            return 'ERROR';
        }

        $baseStateStr = \sprintf(
            '%ss%d%s',
            $state->isAcceptState ? ':' : '',
            $state->stateNumber,
            $state->requiresFullContext ? '^' : ''
        );

        if ($state->isAcceptState) {
            if ($state->predicates !== null) {
                return $baseStateStr . '=>[' . \implode(', ', $state->predicates) . ']';
            }

            return $baseStateStr . '=>' . $state->prediction;
        }

        return $baseStateStr;
    }

    public function __toString() : string
    {
        return $this->toString();
    }
}
