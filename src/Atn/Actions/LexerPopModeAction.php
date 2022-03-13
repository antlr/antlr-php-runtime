<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * Implements the `popMode` lexer action by calling {@see Lexer::popMode()}.
 *
 * The `popMode` command does not have any parameters, so this action is
 * implemented as a singleton instance exposed by {@see self::instance())}.
 *
 * @author Sam Harwell
 */
final class LexerPopModeAction implements LexerAction
{
    /**
     * Provides a singleton instance of this parameterless lexer action.
     */
    public static function instance(): self
    {
        static $instance;

        return $instance ??= new self();
    }

    /**
     * {@inheritdoc}
     *
     * @return int This method returns {@see LexerActionType::POP_MODE}.
     */
    public function getActionType(): int
    {
        return LexerActionType::POP_MODE;
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
     * This action is implemented by calling {@see Lexer::popMode()}.
     */
    public function execute(Lexer $lexer): void
    {
        $lexer->popMode();
    }

    public function hashCode(): int
    {
        return Hasher::hash($this->getActionType());
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self;
    }

    public function __toString(): string
    {
        return 'popMode';
    }
}
