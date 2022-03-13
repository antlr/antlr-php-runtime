<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * Represents a single action which can be executed following the successful
 * match of a lexer rule. Lexer actions are used for both embedded action syntax
 * and ANTLR 4's new lexer command syntax.
 *
 * @author Sam Harwell
 */
interface LexerAction extends Hashable
{
    /**
     * Gets the serialization type of the lexer action.
     *
     * @return int The serialization type of the lexer action.
     */
    public function getActionType(): int;

    /**
     * Gets whether the lexer action is position-dependent. Position-dependent
     * actions may have different semantics depending on the {@see CharStream}
     * index at the time the action is executed.
     *
     * Many lexer commands, including `type`, `skip`, and `more`, do not check
     * the input index during their execution. Actions like this are
     * position-independent, and may be stored more efficiently as part of the
     * {@see LexerATNConfig::lexerActionExecutor()}.
     *
     * @return bool `true` if the lexer action semantics can be affected by the
     *              position of the input {@see CharStream} at the time it is
     *              executed; otherwise, `false`.
     */
    public function isPositionDependent(): bool;

    /**
     * Execute the lexer action in the context of the specified {@see Lexer}.
     *
     * For position-dependent actions, the input stream must already be
     * positioned correctly prior to calling this method.
     *
     * @param Lexer $lexer The lexer instance.
     */
    public function execute(Lexer $lexer): void;
}
