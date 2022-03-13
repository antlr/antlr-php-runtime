<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Atn\LexerATNSimulator;
use Antlr\Antlr4\Runtime\Error\Exceptions\LexerNoViableAltException;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Utils\Pair;

/**
 * A lexer is recognizer that draws input symbols from a character stream.
 * lexer grammars result in a subclass of this object. A Lexer object
 * uses simplified match() and error recovery mechanisms in the interest
 * of speed.
 */
abstract class Lexer extends Recognizer implements TokenSource
{
    public const DEFAULT_MODE = 0;
    public const MORE = -2;
    public const SKIP = -3;

    public const DEFAULT_TOKEN_CHANNEL = Token::DEFAULT_CHANNEL;
    public const HIDDEN = Token::HIDDEN_CHANNEL;
    public const MIN_CHAR_VALUE = 0x0000;
    public const MAX_CHAR_VALUE = 0x10FFFF;

    public ?CharStream $input = null;

    /** @var Pair Pair<TokenSource, CharStream> */
    protected Pair $tokenFactorySourcePair;

    protected TokenFactory $factory;

    /**
     * The goal of all lexer rules/methods is to create a token object.
     * This is an instance variable as multiple rules may collaborate to
     * create a single token. `nextToken` will return this object after
     * matching lexer rule(s).
     *
     * If you subclass to allow multiple token emissions, then set this
     * to the last token to be matched or something nonnull so that
     * the auto token emit mechanism will not emit another token.
     */
    public ?Token $token = null;

    /**
     * What character index in the stream did the current token start at?
     * Needed, for example, to get the text for current token. Set at
     * the start of nextToken.
     */
    public int $tokenStartCharIndex = -1;

    /**
     * The line on which the first character of the token resides.
     */
    public int $tokenStartLine = -1;

    /**
     * The character position of first character within the line
     */
    public int $tokenStartCharPositionInLine = -1;

    /**
     * Once we see EOF on char stream, next token will be EOF.
     * If you have DONE : EOF ; then you see DONE EOF.
     */
    public bool $hitEOF = false;

    /**
     * The channel number for the current token.
     */
    public int $channel = Token::DEFAULT_CHANNEL;

    /**
     * The token type for the current token.
     */
    public int $type = Token::INVALID_TYPE;

    /** @var array<int> */
    public array $modeStack = [];

    public int $mode = self::DEFAULT_MODE;

    /**
     * You can set the text for the current token to override what is in the
     * input char buffer. Use {@see Lexer::setText()} or can set this instance var.
     */
    public ?string $text = null;

    public function __construct(?CharStream $input = null)
    {
        parent::__construct();

        $this->input = $input;
        $this->factory = CommonTokenFactory::default();
        $this->tokenFactorySourcePair = new Pair($this, $input);
    }

    public function reset(): void
    {
        // wack Lexer state variables
        if ($this->input !== null) {
            $this->input->seek(0);// rewind the input
        }

        $this->token = null;
        $this->type = Token::INVALID_TYPE;
        $this->channel = Token::DEFAULT_CHANNEL;
        $this->tokenStartCharIndex = -1;
        $this->tokenStartCharPositionInLine = -1;
        $this->tokenStartLine = -1;
        $this->text = null;

        $this->hitEOF = false;
        $this->mode = self::DEFAULT_MODE;
        $this->modeStack = [];

        if ($this->interp !== null) {
            $this->interp->reset();
        }
    }

    /**
     * Return a token from this source; i.e., match a token on the char stream.
     */
    public function nextToken(): ?Token
    {
        $input = $this->input;

        if ($input === null) {
            throw new \LogicException('NextToken requires a non-null input stream.');
        }

        $interpreter = $this->interp;

        if (!$interpreter instanceof LexerATNSimulator) {
            throw new \LogicException('Unexpected interpreter type.');
        }

        // Mark start location in char stream so unbuffered streams are
        // guaranteed at least have text of current token
        $tokenStartMarker = $input->mark();

        try {
            while (true) {
                if ($this->hitEOF) {
                    $this->emitEOF();

                    return $this->token;
                }

                $this->token = null;
                $this->channel = Token::DEFAULT_CHANNEL;
                $this->tokenStartCharIndex = $input->getIndex();
                $this->tokenStartCharPositionInLine = $interpreter->getCharPositionInLine();
                $this->tokenStartLine = $interpreter->getLine();
                $this->text = null;
                $continueOuter = false;

                while (true) {
                    $this->type = Token::INVALID_TYPE;
                    $ttype = self::SKIP;
                    try {
                        $ttype = $interpreter->match($input, $this->mode);
                    } catch (LexerNoViableAltException $e) {
                        $this->notifyListeners($e); // report error
                        $this->recover($e);
                    }

                    if ($input->LA(1) === Token::EOF) {
                        $this->hitEOF = true;
                    }

                    if ($this->type === Token::INVALID_TYPE) {
                        $this->type = $ttype;
                    }

                    if ($this->type === self::SKIP) {
                        $continueOuter = true;

                        break;
                    }

                    if ($this->type !== self::MORE) {
                        break;
                    }
                }

                if ($continueOuter) {
                    continue;
                }

                if ($this->token === null) {
                    $this->emit();
                }

                return $this->token;
            }
        } finally {
            // make sure we release marker after match or
            // unbuffered char stream will keep buffering
            $input->release($tokenStartMarker);
        }
    }

    /**
     * Instruct the lexer to skip creating a token for current lexer rule
     * and look for another token. `nextToken` knows to keep looking when
     * a lexer rule finishes with token set to SKIP_TOKEN. Recall that
     * if `token === null` at end of any token rule, it creates one for you
     * and emits it.
     */
    public function skip(): void
    {
        $this->type = self::SKIP;
    }

    public function more(): void
    {
        $this->type = self::MORE;
    }

    public function mode(int $m): void
    {
        $this->mode = $m;
    }

    public function pushMode(int $m): void
    {
        $this->modeStack[] = $this->mode;

        $this->mode($m);
    }

    public function popMode(): int
    {
        if (\count($this->modeStack) === 0) {
            throw new \LogicException('Empty Stack');
        }

        $this->mode(\array_pop($this->modeStack));

        return $this->mode;
    }

    public function getSourceName(): string
    {
        return $this->input === null ? '' : $this->input->getSourceName();
    }

    public function getInputStream(): ?IntStream
    {
        return $this->input;
    }

    public function getTokenFactory(): TokenFactory
    {
        return $this->factory;
    }

    public function setTokenFactory(TokenFactory $factory): void
    {
        $this->factory = $factory;
    }

    public function setInputStream(IntStream $input): void
    {
        $this->input = null;
        $this->tokenFactorySourcePair = new Pair($this, $this->input);

        $this->reset();

        if (!$input instanceof CharStream) {
            throw new \LogicException('Input must be CharStream.');
        }

        $this->input = $input;
        $this->tokenFactorySourcePair = new Pair($this, $this->input);
    }

    /**
     * By default does not support multiple emits per nextToken invocation
     * for efficiency reasons. Subclass and override this method, nextToken,
     * and getToken (to push tokens into a list and pull from that list
     * rather than a single variable as this implementation does).
     */
    public function emitToken(Token $token): void
    {
        $this->token = $token;
    }

    /**
     * The standard method called to automatically emit a token at the
     * outermost lexical rule. The token object should point into the
     * char buffer start..stop. If there is a text override in 'text',
     * use that to set the token's text. Override this method to emit
     * custom Token objects or provide a new factory.
     */
    public function emit(): Token
    {
        $token = $this->factory->createEx(
            $this->tokenFactorySourcePair,
            $this->type,
            $this->text,
            $this->channel,
            $this->tokenStartCharIndex,
            $this->getCharIndex() - 1,
            $this->tokenStartLine,
            $this->tokenStartCharPositionInLine,
        );

        $this->emitToken($token);

        return $token;
    }

    public function emitEOF(): Token
    {
        if ($this->input === null) {
            throw new \LogicException('Cannot emit EOF for null stream.');
        }

        $cpos = $this->getCharPositionInLine();
        $lpos = $this->getLine();
        $eof = $this->factory->createEx(
            $this->tokenFactorySourcePair,
            Token::EOF,
            null,
            Token::DEFAULT_CHANNEL,
            $this->input->getIndex(),
            $this->input->getIndex() - 1,
            $lpos,
            $cpos,
        );

        $this->emitToken($eof);

        return $eof;
    }

    public function getLine(): int
    {
        if (!$this->interp instanceof LexerATNSimulator) {
            throw new \LogicException('Unexpected interpreter type.');
        }

        return $this->interp->getLine();
    }

    public function setLine(int $line): void
    {
        if (!$this->interp instanceof LexerATNSimulator) {
            throw new \LogicException('Unexpected interpreter type.');
        }

        $this->interp->setLine($line);
    }

    public function getCharPositionInLine(): int
    {
        if (!$this->interp instanceof LexerATNSimulator) {
            throw new \LogicException('Unexpected interpreter type.');
        }

        return $this->interp->getCharPositionInLine();
    }

    public function setCharPositionInLine(int $charPositionInLine): void
    {
        if (!$this->interp instanceof LexerATNSimulator) {
            throw new \LogicException('Unexpected interpreter type.');
        }

        $this->interp->setCharPositionInLine($charPositionInLine);
    }

    /**
     * What is the index of the current character of lookahead?
     */
    public function getCharIndex(): int
    {
        if ($this->input === null) {
            throw new \LogicException('Cannot know char index for null stream.');
        }

        return $this->input->getIndex();
    }

    /**
     * Return the text matched so far for the current token or any text override.
     */
    public function getText(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        if (!$this->interp instanceof LexerATNSimulator) {
            throw new \LogicException('Unexpected interpreter type.');
        }

        return $this->input === null ? '' : $this->interp->getText($this->input);
    }

    /**
     * Set the complete text of this token; it wipes any previous changes to the text.
     */
    public function setText(string $text): void
    {
        $this->text = $text;
    }

    public function getToken(): ?Token
    {
        return $this->token;
    }

    /**
     * Override if emitting multiple tokens.
     */
    public function setToken(Token $token): void
    {
        $this->token = $token;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function setType(int $type): void
    {
        $this->type = $type;
    }

    public function getChannel(): int
    {
        return $this->channel;
    }

    public function setChannel(int $channel): void
    {
        $this->channel = $channel;
    }

    /**
     * @return array<string>|null
     */
    public function getChannelNames(): ?array
    {
        return null;
    }

    /**
     * @return array<string>|null
     */
    public function getModeNames(): ?array
    {
        return null;
    }

    /**
     * Return a list of all Token objects in input char stream.
     * Forces load of all tokens. Does not include EOF token.
     *
     * @return array<Token>
     */
    public function getAllTokens(): array
    {
        $tokens = [];
        $token = $this->nextToken();

        while ($token !== null && $token->getType() !== Token::EOF) {
            $tokens[] = $token;
            $token = $this->nextToken();
        }

        return $tokens;
    }

    /**
     * Lexers can normally match any char in it's vocabulary after matching
     * a token, so do the easy thing and just kill a character and hope
     * it all works out. You can instead use the rule invocation stack
     * to do sophisticated error recovery if you are in a fragment rule.
     */
    public function recover(RecognitionException $re): void
    {
        if ($this->input !== null && $this->input->LA(1) !== Token::EOF) {
            if ($re instanceof LexerNoViableAltException
                && $this->interp instanceof LexerATNSimulator) {
                // skip a char and try again
                $this->interp->consume($this->input);
            } else {
                // TODO: Do we lose character or line position information?
                $this->input->consume();
            }
        }
    }

    public function notifyListeners(LexerNoViableAltException $e): void
    {
        $start = $this->tokenStartCharIndex;

        if ($this->input === null) {
            $text = '';
        } else {
            $stop = $this->input->getIndex();
            $text = $this->input->getText($start, $stop);
        }

        $listener = $this->getErrorListenerDispatch();

        $listener->syntaxError(
            $this,
            null,
            $this->tokenStartLine,
            $this->tokenStartCharPositionInLine,
            \sprintf('token recognition error at: \'%s\'', $text),
            $e,
        );
    }
}
