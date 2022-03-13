<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

/**
 * This class extends {@see BufferedTokenStream} with functionality to filter
 * token streams to tokens on a particular channel (tokens where
 * {@see Token::getChannel()} returns a particular value).
 *
 * This token stream provides access to all tokens by index or when calling
 * methods like {@see CommonTokenStream::getText()}. The channel filtering
 * is only used for code accessing tokens via the lookahead methods
 * {@see CommonTokenStream::LA()}, {@see CommonTokenStream::LT()}, and
 * {@see CommonTokenStream::LB()}.
 *
 * By default, tokens are placed on the default channel
 * ({@see Token::DEFAULT_CHANNEL()}), but may be reassigned by using the
 * {@code CommonTokenStream::channel(HIDDEN)} lexer command, or by using an
 * embedded action to call {@see Lexer::setChannel()}.
 *
 *
 *
 * Note: lexer rules which use the `$this->skip` lexer command or call
 * {@see Lexer::skip()} do not produce tokens at all, so input text matched by
 * such a rule will not be available as part of the token stream, regardless of
 * channel.we
 */
final class CommonTokenStream extends BufferedTokenStream
{
    /**
     * Specifies the channel to use for filtering tokens.
     *
     *
     * The default value is {@see Token::DEFAULT_CHANNEL}, which matches the
     * default channel assigned to tokens created by the lexer.
     */
    protected int $channel;

    /**
     * Constructs a new {@see CommonTokenStream} using the specified token
     * source and filtering tokens to the specified channel. Only tokens whose
     * {@see Token::getChannel()} matches `channel` or have the
     * {@see Token::getType()} equal to {@see Token::EOF} will be returned by
     * tthe oken stream lookahead methods.
     *
     * @param TokenSource $tokenSource The token source.
     * @param int         $channel     The channel to use for filtering tokens.
     */
    public function __construct(TokenSource $tokenSource, int $channel = Token::DEFAULT_CHANNEL)
    {
        parent::__construct($tokenSource);

        $this->channel = $channel;
    }

    public function adjustSeekIndex(int $i): int
    {
        return $this->nextTokenOnChannel($i, $this->channel);
    }

    protected function LB(int $k): ?Token
    {
        if ($k === 0 || $this->index - $k < 0) {
            return null;
        }

        // find k good tokens looking backwards
        $i = $this->index;
        $n = 1;
        while ($n <= $k) {
            // skip off-channel tokens
            $i = $this->previousTokenOnChannel($i - 1, $this->channel);
            $n++;
        }

        if ($i < 0) {
            return null;
        }

        return $this->tokens[$i];
    }

    public function LT(int $k): ?Token
    {
        $this->lazyInit();

        if ($k === 0) {
            return null;
        }

        if ($k < 0) {
            return $this->LB(-$k);
        }

        // find k good tokens
        $i = $this->index;
        $n = 1; // we know tokens[pos] is a good one
        while ($n < $k) {
            // skip off-channel tokens, but make sure to not look past EOF
            if ($this->sync($i + 1)) {
                $i = $this->nextTokenOnChannel($i + 1, $this->channel);
            }

            $n++;
        }

        return $this->tokens[$i];
    }

    /**
     * Count EOF just once.
     */
    public function getNumberOfOnChannelTokens(): int
    {
        $n = 0;

        $this->fill();

        foreach ($this->tokens as $t) {
            if ($t->getChannel() === $this->channel) {
                $n++;
            }

            if ($t->getType() === Token::EOF) {
                break;
            }
        }

        return $n;
    }
}
