<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * Implements the `more` lexer action by calling {@see Lexer::more()}.
 *
 * The `more` command does not have any parameters, so this action is
 * implemented as a singleton instance exposed by {@see LexerMoreAction::INSTANCE()}.
 *
 * @author Sam Harwell
 */
final class LexerMoreAction implements LexerAction
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
     * @return int This method returns {@see LexerActionType::MORE}.
     */
    public function getActionType(): int
    {
        return LexerActionType::MORE;
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
     * This action is implemented by calling {@see Lexer::more()}.
     */
    public function execute(Lexer $lexer): void
    {
        $lexer->more();
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
        return 'more';
    }
}
