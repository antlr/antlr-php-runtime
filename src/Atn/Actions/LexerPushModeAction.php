<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * Implements the `pushMode` lexer action by calling {@see Lexer::pushMode()}
 * with the assigned mode.
 *
 * @author Sam Harwell
 */
final class LexerPushModeAction implements LexerAction
{
    private int $mode;

    public function __construct(int $mode)
    {
        $this->mode = $mode;
    }

    /**
     * Get the lexer mode this action should transition the lexer to.
     *
     * @return int The lexer mode for this `pushMode` command.
     */
    public function getMode(): int
    {
        return $this->mode;
    }

    /**
     * {@inheritdoc}
     *
     * @return int This method returns {@see LexerActionType::PUSH_MODE}.
     */
    public function getActionType(): int
    {
        return LexerActionType::PUSH_MODE;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool This method returns `false`.
     */
    public function isPositionDependent(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * This action is implemented by calling {@see Lexer::pushMode()} with the
     * value provided by {@see LexerPushModeAction::getMode()}.
     */
    public function execute(Lexer $lexer): void
    {
        $lexer->pushMode($this->mode);
    }

    public function hashCode(): int
    {
        return Hasher::hash($this->getActionType(), $this->mode);
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->mode === $other->mode;
    }

    public function __toString(): string
    {
        return \sprintf('pushMode(%d)', $this->mode);
    }
}
