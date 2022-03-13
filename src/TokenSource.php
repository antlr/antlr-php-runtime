<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

/**
 * A source of tokens must provide a sequence of tokens via {@see TokenSource::nextToken()}
 * and also must reveal it's source of characters; {@see CommonToken}'s text
 * is computed from a {@see CharStream}; it only store indices into
 * the char stream.
 *
 * Errors from the lexer are never passed to the parser. Either you want to keep
 * going or you do not upon token recognition error. If you do not want
 * to continue lexing then you do not want to continue parsing. Just throw
 * an exception not under {@see RecognitionException} and PHP will naturally
 * toss you all the way out of the recognizers. If you want to continue lexing
 * then you should not throw an exception to the parser--it has already requested
 * a token. Keep lexing until you get a valid one. Just report errors and
 * keep going, looking for a valid token.
 */
interface TokenSource
{
    /**
     * Return a {@see Token} object from your input stream (usually a
     * {@see CharStream}). Do not fail/return upon lexing error; keep chewing
     * on the characters until you get a good one; errors are not passed through
     * to the parser.
     */
    public function nextToken(): ?Token;

    /**
     * Get the line number for the current position in the input stream.
     * The first line in the input is line 1.
     *
     * @return int The line number for the current position in the input stream,
     *             or 0 if the current token source does not track line numbers.
     */
    public function getLine(): int;

    /**
     * Get the index into the current line for the current position in
     * the input stream. The first character on a line has position 0.
     *
     * @return int The line number for the current position in the input stream,
     *             or -1 if the current token source does not track
     *             character positions.
     */
    public function getCharPositionInLine(): int;

    /**
     * Get the {@see CharStream} from which this token source is currently
     * providing tokens.
     *
     * @return CharStream The {@see CharStream} associated with the current
     *                    position in the input, or `null` if no input stream
     *                    is available for the token source.
     */
    public function getInputStream(): ?IntStream;

    /**
     * Gets the name of the underlying input source. This method returns
     * a non-null, non-empty string. If such a name is not known, this method
     * returns {@see IntStream::UNKNOWN_SOURCE_NAME}.
     */
    public function getSourceName(): string;

    /**
     * Set the {@see TokenFactory} this token source should use for creating
     * {@see Token} objects from the input.
     *
     * @param TokenFactory $factory The {@see TokenFactory} to use
     *                              for creating tokens.
     */
    public function setTokenFactory(TokenFactory $factory): void;

    /**
     * Gets the {@see TokenFactory} this token source is currently using
     * f objects from the input.
     *
     * @return TokenFactory The {@see TokenFactory} currently used
     *                      by this token source.
     */
    public function getTokenFactory(): TokenFactory;
}
