<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Utils\Pair;

/**
 * This default implementation of {@see TokenFactory} creates
 * {@see CommonToken} objects.
 */
final class CommonTokenFactory implements TokenFactory
{
    /**
     * Indicates whether {@see CommonToken::setText()} should be called after
     * constructing tokens to explicitly set the text. This is useful for cases
     * where the input stream might not be able to provide arbitrary substrings
     * of text from the input after the lexer creates a token (e.g. the
     * implementation of {@see CharStream::getText()} in
     * {@see UnbufferedCharStream} throws an
     * {@see UnsupportedOperationException}). Explicitly setting the token text
     * allows {@see Token::getText()} to be called at any time regardless of the
     * input stream implementation.
     *
     * The default value is `false` to avoid the performance and memory
     * overhead of copying text for every token unless explicitly requested.
     */
    protected bool $copyText;

    /**
     * Constructs a {@see CommonTokenFactory} with the specified value for
     * {@see CommonTokenFactory::copyText()}.
     *
     * When `copyText` is `false`, the {@see CommonTokenFactory::DEFAULT}
     * instance should be used instead of constructing a new instance.
     *
     * @param bool $copyText The value for {@see CommonTokenFactory::copyText()}.
     */
    public function __construct(bool $copyText = false)
    {
        $this->copyText = $copyText;
    }

    /**
     * The default {@see CommonTokenFactory} instance.
     *
     * This token factory does not explicitly copy token text when constructing
     * tokens.
     */
    public static function default(): self
    {
        static $default;

        return $default ??= new CommonTokenFactory();
    }

    public function createEx(
        Pair $source,
        int $type,
        ?string $text,
        int $channel,
        int $start,
        int $stop,
        int $line,
        int $column,
    ): Token {
        $token = new CommonToken($type, $source, $channel, $start, $stop);

        $token->setLine($line);
        $token->setCharPositionInLine($column);

        if ($text !== null) {
            $token->setText($text);
        } elseif ($this->copyText && $source->b !== null) {
            if (!$source->b instanceof CharStream) {
                throw new \LogicException('Unexpected stream type.');
            }

            $token->setText($source->b->getText($start, $stop));
        }

        return $token;
    }

    public function create(int $type, string $text): Token
    {
        $token = new CommonToken($type);

        $token->setText($text);

        return $token;
    }
}
