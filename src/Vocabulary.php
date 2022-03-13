<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

/**
 * This interface provides information about the vocabulary used by a
 * recognizer.
 *
 * @see Recognizer::getVocabulary()
 */
interface Vocabulary
{
    /**
     * Returns the highest token type value. It can be used to iterate from zero
     * to that number, inclusively, thus querying all stored entries.
     *
     * @return int The highest token type value.
     */
    public function getMaxTokenType(): int;

    /**
     * Gets the string literal associated with a token type. The string returned
     * by this method, when not `null`, can be used unaltered in a parser grammar
     * to represent this token type.
     *
     * The following table shows examples of lexer rules and the literal names
     * assigned to the corresponding token types.
     *
     * Rule             | Literal Name | Java String Literal
     * -----------------|--------------|---------------------
     * `THIS : 'this';` | `'this'`     | `"'this'"`
     * `SQUOTE : '\'';` | `'\''`       | `"'\\''"`
     * `ID : [A-Z]+;`   | n/a          | `null`
     *
     * @param int $tokenType The token type.
     *
     * @return string The string literal associated with the specified token type,
     *                or `null` if no string literal is associated with the type.
     */
    public function getLiteralName(int $tokenType): ?string;

    /**
     * Gets the symbolic name associated with a token type. The string returned
     * by this method, when not `null`, can be used unaltered in a parser grammar
     * to represent this token type.
     *
     * This method supports token types defined by any of the following methods:
     *
     * - Tokens created by lexer rules.
     * - Tokens defined in a `tokens{}` block in a lexer or parser grammar.
     * - The implicitly defined `EOF` token, which has the token type {@see Token::EOF()}.
     *
     * The following table shows examples of lexer rules and the literal names
     * assigned to the corresponding token types.
     *
     * Rule             | Symbolic Name
     * -----------------|--------------
     * `THIS : 'this';` | `THIS`
     * `SQUOTE : '\'';` | `SQUOTE`
     * `ID : [A-Z]+;`   | `ID`
     *
     * @param int $tokenType The token type.
     *
     * @return string The symbolic name associated with the specified token type,
     *                or `null` if no symbolic name is associated with the type.
     */
    public function getSymbolicName(int $tokenType): ?string;

    /**
     * Gets the display name of a token type.
     *
     * ANTLR provides a default implementation of this method, but applications
     * are free to override the behavior in any manner which makes sense
     * for the application. The default implementation returns the first result
     * from the following list which produces a non-null result.
     *
     * @param int $tokenType The token type.
     *
     * @return string The display name of the token type, for use in
     *                error reporting or other user-visible messages
     *                which reference specific token types.
     */
    public function getDisplayName(int $tokenType): string;
}
