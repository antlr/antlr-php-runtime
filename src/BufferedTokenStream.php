<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Utils\Set;

/**
 * This implementation of {@see TokenStream} loads tokens from a
 * {@see TokenSource} on-demand, and places the tokens in a buffer to provide
 * access to any previous token by index.
 *
 * This token stream ignores the value of {@see Token::getChannel()}. If your
 * parser requires the token stream filter tokens to only those on a particular
 * channel, such as {@see Token::DEFAULT_CHANNEL} or
 * {@see Token::HIDDEN_CHANNEL}, use a filtering token stream such a
 * {@see CommonTokenStream}.
 */
class BufferedTokenStream implements TokenStream
{
    /**
     * The {@see TokenSource} from which tokens for this stream are fetched.
     */
    protected TokenSource $tokenSource;

    /**
     * A collection of all tokens fetched from the token source. The list is
     * considered a complete view of the input once
     * {@see BufferedTokenStream::fetchedEOF()} is set to `true`.
     *
     * @var array<Token>
     */
    protected array $tokens = [];

    /**
     * The index into {@see BufferedTokenStream::tokens()} of the current token
     * (next token to {@see BufferedTokenStream::consume()}).
     * {@see BufferedTokenStream::tokens()}`[{@see BufferedTokenStream::p()}]`
     * should be {@see BufferedTokenStream::LT(1)}.
     *
     * This field is set to -1 when the stream is first constructed or when
     * {@see BufferedTokenStream::setTokenSource()} is called, indicating that
     * the first token has not yet been fetched from the token source. For
     * additional information, see the documentation of {@see IntStream} for
     * a description of Initializing Methods.
     */
    protected int $index = -1;

    /**
     * Indicates whether the {@see Token::EOF} token has been fetched from
     * {@see BufferedTokenStream::tokenSource()} and added to
     * {@see BufferedTokenStream::tokens()}. This field improves  performance
     * for the following cases:
     *
     * - {@see BufferedTokenStream::consume()}: The lookahead check in
     *    {@see BufferedTokenStream::consume()} to prevent consuming the
     *    EOF symbol is optimized by checking the values of
     *    {@see BufferedTokenStream::fetchedEOF()} and
     *    {@see BufferedTokenStream::p()} instead of calling
     *    {@see BufferedTokenStream::LA()}.
     * - {@see BufferedTokenStream::fetch()}: The check to prevent adding multiple
     *    EOF symbols into {@see BufferedTokenStream::tokens()} is trivial with
     *    this field.
     */
    protected bool $fetchedEOF = false;

    public function __construct(TokenSource $tokenSource)
    {
        $this->tokenSource = $tokenSource;
    }

    public function getTokenSource(): TokenSource
    {
        return $this->tokenSource;
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function mark(): int
    {
        return 0;
    }

    public function release(int $marker): void
    {
        // no resources to release
    }

    public function seek(int $index): void
    {
        $this->lazyInit();

        $this->index = $this->adjustSeekIndex($index);
    }

    public function getLength(): int
    {
        return \count($this->tokens);
    }

    public function consume(): void
    {
        $skipEofCheck = false;

        if ($this->index >= 0) {
            if ($this->fetchedEOF) {
                // the last token in tokens is EOF. skip check if p indexes any
                // fetched token except the last.
                $skipEofCheck = $this->index < \count($this->tokens) - 1;
            } else {
                // no EOF token in tokens. skip check if p indexes a fetched token.
                $skipEofCheck = $this->index < \count($this->tokens);
            }
        }

        if (!$skipEofCheck && $this->LA(1) === Token::EOF) {
            throw new \InvalidArgumentException('Cannot consume EOF.');
        }

        if ($this->sync($this->index + 1)) {
            $this->index = $this->adjustSeekIndex($this->index + 1);
        }
    }

    /**
     * Make sure index `i` in tokens has a token.
     *
     * @return bool `true` if a token is located at index `i`,
     *              otherwise `false`.
     *
     * @see BufferedTokenStream::get()
     */
    public function sync(int $i): bool
    {
        $n = $i - \count($this->tokens) + 1; // how many more elements we need?

        if ($n > 0) {
            $fetched = $this->fetch($n);

            return $fetched >= $n;
        }

        return true;
    }

    public function fetch(int $n): int
    {
        if ($this->fetchedEOF) {
            return 0;
        }

        for ($i = 0; $i < $n; $i++) {
            /** @var WritableToken $token */
            $token = $this->tokenSource->nextToken();
            $token->setTokenIndex(\count($this->tokens));

            $this->tokens[] = $token;

            if ($token->getType() === Token::EOF) {
                $this->fetchedEOF = true;

                return $i + 1;
            }
        }

        return $n;
    }

    /**
     * @throws \OutOfBoundsException If the index is out of range.
     */
    public function get(int $index): Token
    {
        $count = \count($this->tokens);

        if ($index < 0 || $index >= $count) {
            throw new \OutOfBoundsException(\sprintf(
                'Token index %d out of range 0..%d.',
                $index,
                $count,
            ));
        }

        $this->lazyInit();

        return $this->tokens[$index];
    }

    public function LA(int $i): int
    {
        $token = $this->LT($i);

        return $token === null ? Token::INVALID_TYPE : $token->getType();
    }

    protected function LB(int $k): ?Token
    {
        if ($this->index - $k < 0) {
            return null;
        }

        return $this->tokens[$this->index - $k];
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

        $i = $this->index + $k - 1;

        $this->sync($i);

        if ($i >= \count($this->tokens)) {
            // return EOF token
            // EOF must be last token
            return $this->tokens[\count($this->tokens) - 1];
        }

        return $this->tokens[$i];
    }

    /**
     * Allowed derived classes to modify the behavior of operations which change
     * the current stream position by adjusting the target token index of a seek
     * operation. The default implementation simply returns `i`. If an
     * exception is thrown in this method, the current stream index should not
     * be changed.
     *
     * For example, {@see CommonTokenStream} overrides this method to ensure
     * that the seek target is always an on-channel token.
     *
     * @param int $i The target token index.
     *
     * @return int The adjusted target token index.
     */
    public function adjustSeekIndex(int $i): int
    {
        return $i;
    }

    protected function lazyInit(): void
    {
        if ($this->index === -1) {
            $this->setup();
        }
    }

    protected function setup(): void
    {
        $this->sync(0);

        $this->index = $this->adjustSeekIndex(0);
    }

    /**
     * Reset this token stream by setting its token source.
     */
    public function setTokenSource(TokenSource $tokenSource): void
    {
        $this->tokenSource = $tokenSource;
        $this->tokens = [];
        $this->index = -1;
        $this->fetchedEOF = false;
    }

    /**
     * @return array<Token>
     */
    public function getAllTokens(): array
    {
        return $this->tokens;
    }

    /**
     * Get all tokens from start..stop inclusively
     *
     * @return array<Token>|null
     */
    public function getTokens(int $start, int $stop, ?Set $types = null): ?array
    {
        if ($start < 0 || $stop < 0) {
            return null;
        }

        $this->lazyInit();

        $subset = [];
        if ($stop >= \count($this->tokens)) {
            $stop = \count($this->tokens) - 1;
        }

        for ($i = $start; $i < $stop; $i++) {
            $t = $this->tokens[$i];

            if ($t->getType() === Token::EOF) {
                break;
            }

            if ($types === null || $types->contains($t->getType())) {
                $subset[] = $t;
            }
        }

        return $subset;
    }

    /**
     * Given a starting index, return the index of the next token on channel.
     * Return `i` if `tokens[i]` is on channel. Return the index of the EOF
     * token if there are no tokens on channel between `i` and EOF.
     */
    protected function nextTokenOnChannel(int $i, int $channel): int
    {
        $this->sync($i);

        if ($i >= \count($this->tokens)) {
            return $this->getLength() - 1;
        }

        $token = $this->tokens[$i];
        while ($token->getChannel() !== $channel) {
            if ($token->getType() === Token::EOF) {
                return $i;
            }

            $i++;

            $this->sync($i);

            $token = $this->tokens[$i];
        }

        return $i;
    }

    /**
     * Given a starting index, return the index of the previous token on channel.
     * Return `i` if `tokens[i]` is on channel. Return -1 if there are no tokens
     * on channel between `i` and 0.
     *
     * If `i` specifies an index at or after the EOF token, the EOF token
     * index is returned. This is due to the fact that the EOF token is treated
     * as though it were on every channel.
     */
    protected function previousTokenOnChannel(int $i, int $channel): int
    {
        while ($i >= 0 && $this->tokens[$i]->getChannel() !== $channel) {
            $i--;
        }

        return $i;
    }

    /**
     * Collect all tokens on specified channel to the right of  the current token
     * up until we see a token on DEFAULT_TOKEN_CHANNEL or EOF. If channel is -1,
     * find any non default channel token.
     *
     * @return array<Token>
     */
    public function getHiddenTokensToRight(int $tokenIndex, int $channel): ?array
    {
        $this->lazyInit();

        if ($tokenIndex < 0 || $tokenIndex >= \count($this->tokens)) {
            throw new \InvalidArgumentException(
                \sprintf('%d not in 0..%d', $tokenIndex, \count($this->tokens) - 1),
            );
        }

        $nextOnChannel = $this->nextTokenOnChannel($tokenIndex + 1, Lexer::DEFAULT_TOKEN_CHANNEL);
        $from_ = $tokenIndex + 1;
        // if none onchannel to right, nextOnChannel=-1 so set to = last token
        $to = $nextOnChannel === -1 ? \count($this->tokens) - 1 : $nextOnChannel;

        return $this->filterForChannel($from_, $to, $channel);
    }

    /**
     * Collect all tokens on specified channel to the left of the current token
     * up until we see a token on DEFAULT_TOKEN_CHANNEL. If channel is -1, find
     * any non default channel token.
     *
     * @return array<Token>
     */
    public function getHiddenTokensToLeft(int $tokenIndex, int $channel): ?array
    {
        $this->lazyInit();

        if ($tokenIndex < 0 || $tokenIndex >= \count($this->tokens)) {
            throw new \InvalidArgumentException(
                \sprintf('%d not in 0..%d', $tokenIndex, \count($this->tokens) - 1),
            );
        }

        $prevOnChannel = $this->previousTokenOnChannel($tokenIndex - 1, Lexer::DEFAULT_TOKEN_CHANNEL);

        if ($prevOnChannel === $tokenIndex - 1) {
            return null;
        }

        // if none on channel to left, prevOnChannel=-1 then from=0
        $from = $prevOnChannel + 1;
        $to = $tokenIndex - 1;

        return $this->filterForChannel($from, $to, $channel);
    }

    /**
     * @return array<Token>|null
     */
    protected function filterForChannel(int $left, int $right, int $channel): ?array
    {
        $hidden = [];
        for ($i = $left; $i < $right + 1; $i++) {
            $t = $this->tokens[$i];

            if ($channel === -1) {
                if ($t->getChannel() !== Lexer::DEFAULT_TOKEN_CHANNEL) {
                    $hidden[] = $t;
                }
            } elseif ($t->getChannel() === $channel) {
                $hidden[] = $t;
            }
        }

        if (\count($hidden) === 0) {
            return null;
        }

        return $hidden;
    }

    public function getSourceName(): string
    {
        return $this->tokenSource->getSourceName();
    }

    /**
     * Get the text of all tokens in this buffer.
     */
    public function getTextByInterval(Interval $interval): string
    {
        $this->lazyInit();
        $this->fill();

        if ($interval->start < 0 || $interval->stop < 0) {
            return '';
        }

        $stop = $interval->stop;

        if ($stop >= \count($this->tokens)) {
            $stop = \count($this->tokens) - 1;
        }

        $s = '';
        for ($i = $interval->start; $i <= $stop; $i++) {
            $t = $this->tokens[$i];

            if ($t->getType() === Token::EOF) {
                break;
            }

            $s .= $t->getText();
        }

        return $s;
    }

    public function getText(): string
    {
        return $this->getTextByInterval(new Interval(0, \count($this->tokens) - 1));
    }

    public function getTextByTokens(?Token $start = null, ?Token $stop = null): string
    {
        $startIndex = $start === null ? 0 : $start->getTokenIndex();
        $stopIndex = $stop === null ? \count($this->tokens) - 1 : $stop->getTokenIndex();

        return $this->getTextByInterval(new Interval($startIndex, $stopIndex));
    }

    public function getTextByContext(RuleContext $context): string
    {
        return $this->getTextByInterval($context->getSourceInterval());
    }

    /**
     * Get all tokens from lexer until EOF.
     */
    public function fill(): void
    {
        $this->lazyInit();

        while ($this->fetch(1000) === 1000) {
            continue;
        }
    }
}
