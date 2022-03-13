<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * Implements the `type` lexer action by calling {@see Lexer::setType()}
 * with the assigned type.
 *
 * @author Sam Harwell
 */
final class LexerTypeAction implements LexerAction
{
    private int $type;

    /**
     * Constructs a new `type` action with the specified token type value.
     *
     * @param int $type The type to assign to the token using {@see Lexer::setType()}.
     */
    public function __construct(int $type)
    {
        $this->type = $type;
    }

    /**
     * Gets the type to assign to a token created by the lexer.
     *
     * @return int The type to assign to a token created by the lexer.
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     *
     * @return int This method returns {@see LexerActionType::TYPE}.
     */
    public function getActionType(): int
    {
        return LexerActionType::TYPE;
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
     * This action is implemented by calling {@see Lexer::setType()} with the
     * value provided by {@see LexerTypeAction::getType()}.
     */
    public function execute(Lexer $lexer): void
    {
        $lexer->setType($this->type);
    }

    public function hashCode(): int
    {
        return Hasher::hash($this->getActionType(), $this->type);
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->type === $other->type;
    }

    public function __toString(): string
    {
        return \sprintf('type(%d)', $this->type);
    }
}
