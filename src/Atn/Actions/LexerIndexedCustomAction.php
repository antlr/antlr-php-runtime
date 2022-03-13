<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * This implementation of {@see LexerAction} is used for tracking input offsets
 * for position-dependent actions within a {@see LexerActionExecutor}.
 *
 * This action is not serialized as part of the ATN, and is only required for
 * position-dependent lexer actions which appear at a location other than the
 * end of a rule. For more information about DFA optimizations employed for
 * lexer actions, see {@see LexerActionExecutor::append()} and
 * {@see LexerActionExecutor::fixOffsetBeforeMatch()}.
 *
 * @author Sam Harwell
 */
final class LexerIndexedCustomAction implements LexerAction
{
    private int $offset;

    private LexerAction $action;

    /**
     * Constructs a new indexed custom action by associating a character offset
     * with a {@see LexerAction}.
     *
     * Note: This class is only required for lexer actions for which
     * {@see LexerAction::isPositionDependent()} returns `true`.
     *
     * @param int         $offset The offset into the input {@see CharStream},
     *                            relative to the token start index, at which
     *                            the specified lexer action should be executed.
     * @param LexerAction $action The lexer action to execute at a particular
     *                            offset in the input {@see CharStream}.
     */
    public function __construct(int $offset, LexerAction $action)
    {
        $this->offset = $offset;
        $this->action = $action;
    }

    /**
     * Gets the location in the input {@see CharStream} at which the lexer
     * action should be executed. The value is interpreted as an offset relative
     * to the token start index.
     *
     * @return int The location in the input {@see CharStream} at which the lexer
     *             action should be executed.
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Gets the lexer action to execute.
     *
     * @return LexerAction A {@see LexerAction} object which executes the lexer action.
     */
    public function getAction(): LexerAction
    {
        return $this->action;
    }

    /**
     * {@inheritdoc}
     *
     * @return int This method returns the result of calling
     *             {@see LexerIndexedCustomAction::getActionType()} on the
     *             {@see LexerAction} returned by
     *             {@see LexerIndexedCustomAction::getAction()}.
     */
    public function getActionType(): int
    {
        return $this->action->getActionType();
    }

    /**
     * {@inheritdoc}
     *
     * @return bool This method returns `true`.
     */
    public function isPositionDependent(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * This method calls {@see LexerIndexedCustomAction::execute()} on the result
     * of {@see LexerIndexedCustomAction::getAction()} using the provided `lexer`.
     */
    public function execute(Lexer $lexer): void
    {
        // assume the input stream position was properly set by the calling code
        $this->action->execute($lexer);
    }

    public function hashCode(): int
    {
        return Hasher::hash($this->getActionType(), $this->offset, $this->action);
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->offset === $other->offset
            && $this->action->equals($other->action);
    }
}
