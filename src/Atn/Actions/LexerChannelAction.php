<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * Implements the `channel` lexer action by calling {@see Lexer::setChannel()}
 * with the assigned channel.
 *
 * @author Sam Harwell
 */
final class LexerChannelAction implements LexerAction
{
    private int $channel;

    /**
     * Constructs a new `channel` action with the specified channel value.
     *
     * @param int $channel The channel value to pass to {@see Lexer::setChannel()}.
     */
    public function __construct(int $channel)
    {
        $this->channel = $channel;
    }

    /**
     * Gets the channel to use for the {@see Token} created by the lexer.
     *
     * @return int The channel to use for the {@see Token} created by the lexer.
     */
    public function getChannel(): int
    {
        return $this->channel;
    }

    /**
     * {@inheritdoc}
     *
     * @return int This method returns {@see LexerActionType::CHANNEL}.
     */
    public function getActionType(): int
    {
        return LexerActionType::CHANNEL;
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
     * This action is implemented by calling {@see Lexer::setChannel()} with the
     * value provided by {@see LexerChannelAction::getChannel()}.
     */
    public function execute(Lexer $lexer): void
    {
        $lexer->channel = $this->channel;
    }

    public function hashCode(): int
    {
        return Hasher::hash($this->getActionType(), $this->channel);
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->channel === $other->channel;
    }

    public function __toString(): string
    {
        return \sprintf('channel(%d)', $this->channel);
    }
}
