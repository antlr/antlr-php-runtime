<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

/**
 * An {@see IntStream} whose symbols are {@see Token} instances.
 */
interface TokenStream extends IntStream
{
    /**
     * Get the {@see Token} instance associated with the value returned
     * by {@see TokenStream::LA()}. This method has the same pre- and post-conditions
     * as {@see IntStream::LA()}. In addition, when the preconditions
     * of this method are met, the return value is non-null and the value
     * of `LT($k)->getType() === LA($k)`.
     *
     * @see IntStream::LA()
     */
    public function LT(int $k): ?Token;

    /**
     * Gets the {@see Token} at the specified `index` in the stream.
     * When the preconditions of this method are met, the return value is non-null.
     *
     * The preconditions for this method are the same as the preconditions
     * of {@see IntStream::seek()}. If the behavior of {@see TokenStream::seek()}
     * is unspecified for the current state and given `index`, then the behavior
     * of this method is also unspecified.
     *
     * The symbol referred to by `index` differs from {@see TokenStream::seek()} only
     * in the case of filtering streams where `index` lies before the end
     * of the stream. Unlike {@see TokenStream::seek()}, this method does not adjust
     * `index` to point to a non-ignored symbol.
     */
    public function get(int $index): Token;

    /**
     * Gets the underlying {@see TokenSource} which provides tokens for this stream.
     */
    public function getTokenSource(): TokenSource;

    /**
     * Return the text of all tokens within the specified `interval`.
     * This method behaves like the following code (including potential exceptions
     * for violating preconditions of {@see TokenStream::get()}, but may be optimized
     * by the specific implementation.
     *
     *     $stream = ...;
     *     $text = '';
     *     for ($i = $interval->a; $i <= $interval->b; $i++) {
     *         $text += $stream->get($i)->getText();
     *     }
     *
     * @param Interval $interval The interval of tokens within this stream
     *                           to get text for.
     *
     * @return string The text of all tokens within the specified interval
     *                in this stream.
     */
    public function getTextByInterval(Interval $interval): string;

    /**
     * Return the text of all tokens in the stream. This method behaves like
     * the following code, including potential exceptions from the calls
     * to {@see IntStream::size()} and {@see TokenStream::getText()}, but may
     * be optimized by the specific implementation.
     *
     *     $stream = ...;
     *     $text = $stream->getText(new Interval(0, $stream->size()));
     *
     * @return string The text of all tokens in the stream.
     */
    public function getText(): string;

    /**
     * Return the text of all tokens in the source interval of the specified context.
     * This method behaves like the following code, including potential exceptions
     * from the call to {@see TokenStream::getText()}, but may be optimized
     * by the specific implementation.
     *
     * If `ctx.getSourceInterval()` does not return a valid interval of tokens
     * provided by this stream, the behavior is unspecified.
     *
     *     $stream = ...;
     *     $text = $stream->getText($ctx->getSourceInterval());
     *
     * @param RuleContext $context The context providing the source interval
     *                             of tokens to get text for.
     *
     * @return string The text of all tokens within the source interval of `context`.
     */
    public function getTextByContext(RuleContext $context): string;

    /**
     * Return the text of all tokens in this stream between `start` and `stop`
     * (inclusive).
     *
     * If the specified `start` or `stop` token was not provided by this stream,
     * or if the `stop` occurred before the `start` token, the behavior
     * is unspecified.
     *
     * For streams which ensure that the {@see Token::getTokenIndex()} method
     * is accurate for all of its provided tokens, this method behaves like
     * the following code. Other streams may implement this method in other ways
     * provided the behavior is consistent with this at a high level.
     *
     *     $stream = ...;
     *     $text = '';
     *     for ($i = $start->getTokenIndex(); $i <= $stop->getTokenIndex(); $i++) {
     *         $text += $stream->get($i)->getText();
     *     }
     *
     * @param Token $start The first token in the interval to get text for.
     * @param Token $stop  The last token in the interval to get text for (inclusive).
     *
     * @return string The text of all tokens lying between the specified
     *                `start` and `stop` tokens.
     */
    public function getTextByTokens(?Token $start, ?Token $stop): string;
}
