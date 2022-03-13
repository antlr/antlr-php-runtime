<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Utils\Pair;
use Antlr\Antlr4\Runtime\Utils\StringUtils;

final class CommonToken implements WritableToken
{
    /**
     * This is the backing field for {@see CommonToken::getType()} and
     * {@see CommonToken::setType()}.
     */
    protected int $type;

    /**
     * This is the backing field for {@see CommonToken::getLine()} and
     * {@see CommonToken::setLine()}.
     */
    protected int $line = 0;

    /**
     * This is the backing field for {@see CommonToken::getCharPositionInLine()}
     * and {@see CommonToken::setCharPositionInLine()}.
     */
    protected int $charPositionInLine = -1;

    /**
     * This is the backing field for {@see CommonToken::getChannel()} and
     * {@see CommonToken::setChannel()}.
     */
    protected int $channel = Token::DEFAULT_CHANNEL;

    /**
     * This is the backing field for {@see CommonToken::getTokenSource()} and
     * {@see CommonToken::getInputStream()}.
     *
     *
     * These properties share a field to reduce the memory footprint of
     * {@see CommonToken}. Tokens created by a {@see CommonTokenFactory} from
     * the same source and input stream share a reference to the same
     * {@see Pair} containing these values.
     */
    protected Pair $source;

    /**
     * This is the backing field for {@see CommonToken::getText()} when the token
     * text is explicitly set in the constructor or via {@see CommonToken::setText()}.
     *
     * @see CommonToken::getText()
     */
    protected ?string $text = null;

    /**
     * This is the backing field for {@see CommonToken::getTokenIndex()} and
     * {@see CommonToken::setTokenIndex()}.
     */
    protected int $index = -1;

    /**
     * This is the backing field for {@see CommonToken::getStartIndex()} and
     * {@see CommonToken::setStartIndex()}.
     */
    protected int $start;

    /**
     * This is the backing field for {@see CommonToken::getStopIndex()} and
     * {@see CommonToken::setStopIndex()}.
     */
    protected int $stop;

    public function __construct(
        int $type,
        ?Pair $source = null,
        ?int $channel = null,
        int $start = -1,
        int $stop = -1,
    ) {
        if ($source !== null && !$source->a instanceof TokenSource) {
            throw new \InvalidArgumentException('Unexpected token source type.');
        }

        if ($source !== null && !$source->b instanceof CharStream) {
            throw new \InvalidArgumentException('Unexpected stream type.');
        }

        $this->source = $source ?? self::emptySource();
        $this->type = $type;
        $this->channel = $channel ?? Token::DEFAULT_CHANNEL;
        $this->start = $start;
        $this->stop = $stop;

        $tokenSource = $this->source->a;

        if ($tokenSource instanceof TokenSource) {
            $this->line = $tokenSource->getLine();
            $this->charPositionInLine = $tokenSource->getCharPositionInLine();
        }
    }

    /**
     * An empty {@see Pair}, which is used as the default value of
     * {@see CommonToken::source()} for tokens that do not have a source.
     */
    public static function emptySource(): Pair
    {
        static $source;

        return $source ??= new Pair(null, null);
    }

    /**
     * Constructs a new {@see CommonToken} as a copy of another {@see Token}.
     *
     * If `oldToken` is also a {@see CommonToken} instance, the newly constructed
     * token will share a reference to the {@see CommonToken::text()} field and
     * the {@see Pair} stored in {@see CommonToken::source()}. Otherwise,
     * {@see CommonToken::text()} will be assigned the result of calling
     * {@see CommonToken::getText()}, and {@see CommonToken::source()} will be
     * constructed from the result of {@see Token::getTokenSource()} and
     * {@see Token::getInputStream()}.
     */
    public function clone(): CommonToken
    {
        $token = new self($this->type, $this->source, $this->channel, $this->start, $this->stop);

        $token->index = $this->index;
        $token->line = $this->line;
        $token->charPositionInLine = $this->charPositionInLine;

        $token->setText($this->text);

        return $token;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function setType(int $type): void
    {
        $this->type = $type;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function setLine(int $line): void
    {
        $this->line = $line;
    }

    public function getText(): ?string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        $input = $this->getInputStream();

        if ($input === null) {
            return null;
        }

        $n = $input->getLength();

        if ($this->start < $n && $this->stop < $n) {
            return $input->getText($this->start, $this->stop);
        }

        return '<EOF>';
    }

    /**
     * Explicitly set the text for this token. If `text` is not `null`, then
     * {@see CommonToken::getText()} will return this value rather than
     * extracting the text from the input.
     *
     * @param string $text The explicit text of the token, or `null`
     *                     if the text should be obtained from the input
     *                     along with the start and stop indexes of the token.
     */
    public function setText(?string $text): void
    {
        $this->text = $text;
    }

    public function getCharPositionInLine(): int
    {
        return $this->charPositionInLine;
    }

    public function setCharPositionInLine(int $charPositionInLine): void
    {
        $this->charPositionInLine = $charPositionInLine;
    }

    public function getChannel(): int
    {
        return $this->channel;
    }

    public function setChannel(int $channel): void
    {
        $this->channel = $channel;
    }

    public function getStartIndex(): int
    {
        return $this->start;
    }

    public function setStartIndex(int $index): void
    {
        $this->start = $index;
    }

    public function getStopIndex(): int
    {
        return $this->stop;
    }

    public function setStopIndex(int $index): void
    {
        $this->stop = $index;
    }

    public function getTokenIndex(): int
    {
        return $this->index;
    }

    public function setTokenIndex(int $tokenIndex): void
    {
        $this->index = $tokenIndex;
    }

    public function getTokenSource(): ?TokenSource
    {
        $source = $this->source->a;

        if ($source !== null && !$source instanceof TokenSource) {
            throw new \LogicException('Unexpected token source type.');
        }

        return $source;
    }

    public function getInputStream(): ?CharStream
    {
        $stream = $this->source->b;

        if ($stream !== null && !$stream instanceof CharStream) {
            throw new \LogicException('Unexpected token source type.');
        }

        return $stream;
    }

    public function getSource(): Pair
    {
        return $this->source;
    }

    public function __toString(): string
    {
        return \sprintf(
            '[@%d,%d:%d=\'%s\',<%d>%s,%d:%d]',
            $this->getTokenIndex(),
            $this->start,
            $this->stop,
            StringUtils::escapeWhitespace($this->getText() ?? ''),
            $this->type,
            $this->channel > 0 ? ',channel=' . $this->channel : '',
            $this->line,
            $this->charPositionInLine,
        );
    }
}
