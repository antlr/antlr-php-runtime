<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

final class LexerModeAction implements LexerAction
{
    private int $mode;

    /**
     * Constructs a new `mode` action with the specified mode value.
     *
     * @param int $mode The mode value to pass to {@see Lexer::mode()}.
     */
    public function __construct(int $mode)
    {
        $this->mode = $mode;
    }

    /**
     * Get the lexer mode this action should transition the lexer to.
     *
     * @return int The lexer mode for this `mode` command.
     */
    public function getMode(): int
    {
        return $this->mode;
    }

    /**
     * {@inheritdoc}
     *
     * @return int This method returns {@see LexerActionType::MODE}.
     */
    public function getActionType(): int
    {
        return LexerActionType::MODE;
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
     * This action is implemented by calling {@see Lexer::mode()} with the
     * value provided by {@see LexerModeAction::getMode()}.
     */
    public function execute(Lexer $lexer): void
    {
        $lexer->mode($this->mode);
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
        return \sprintf('mode(%d)', $this->mode);
    }
}
