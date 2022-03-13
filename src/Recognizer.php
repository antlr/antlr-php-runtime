<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime;

use Antlr\Antlr4\Runtime\Atn\ATN;
use Antlr\Antlr4\Runtime\Atn\ATNSimulator;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Error\Listeners\ANTLRErrorListener;
use Antlr\Antlr4\Runtime\Error\Listeners\ProxyErrorListener;

abstract class Recognizer
{
    public const EOF = -1;

    /** @var array<string> */
    public array $log = [];

    /** @var array<string, array<string, int>> */
    private static array $tokenTypeMapCache = [];

    /** @var array<ANTLRErrorListener> */
    private array $listeners;

    protected ?ATNSimulator $interp = null;

    private int $stateNumber = -1;

    public function __construct()
    {
        $this->listeners = [];
    }

    /**
     * Get the vocabulary used by the recognizer.
     *
     * @return Vocabulary A {@see Vocabulary} instance providing information
     *                    about the vocabulary used by the grammar.
     */
    abstract public function getVocabulary(): Vocabulary;

    /**
     * Get a map from token names to token types.
     *
     * Used for XPath and tree pattern compilation.
     *
     * @return array<string, int>
     */
    public function getTokenTypeMap(): array
    {
        $vocabulary = $this->getVocabulary();

        $key = \spl_object_hash($vocabulary);
        $result = self::$tokenTypeMapCache[$key] ?? null;

        if ($result === null) {
            $result = [];

            for ($i = 0; $i <= $this->getATN()->maxTokenType; $i++) {
                $literalName = $vocabulary->getLiteralName($i);

                if ($literalName !== null) {
                    $result[$literalName] = $i;
                }

                $symbolicName = $vocabulary->getSymbolicName($i);

                if ($symbolicName !== null) {
                    $result[$symbolicName] = $i;
                }
            }

            $result['EOF'] = Token::EOF;

            self::$tokenTypeMapCache[$key] = $result;
        }

        return $result;
    }

    /**
     * Get a map from rule names to rule indexes.
     *
     * Used for XPath and tree pattern compilation.
     *
     * @return array<string, int>
     */
    public function getRuleIndexMap(): array
    {
        return \array_flip($this->getRuleNames());
    }

    public function getTokenType(string $tokenName): int
    {
        $map = $this->getTokenTypeMap();

        return $map[$tokenName] ?? Token::INVALID_TYPE;
    }

    /**
     * If this recognizer was generated, it will have a serialized ATN
     * representation of the grammar. For interpreters, we don't know
     * their serialized ATN despite having created the interpreter from it.
     *
     * @return array<int>
     */
    public function getSerializedATN(): array
    {
        throw new \InvalidArgumentException('there is no serialized ATN');
    }

    /**
     * Get the ATN interpreter used by the recognizer for prediction.
     *
     * @return ATNSimulator|null The ATN interpreter used by the recognizer
     *                           for prediction.
     */
    public function getInterpreter(): ?ATNSimulator
    {
        return $this->interp;
    }

    protected function interpreter(): ATNSimulator
    {
        if ($this->interp === null) {
            throw new \LogicException('Unexpected null interpreter.');
        }

        return $this->interp;
    }

    /**
     * Set the ATN interpreter used by the recognizer for prediction.
     *
     * @param ATNSimulator|null $interpreter The ATN interpreter used
     *                                       by the recognizer for prediction.
     */
    public function setInterpreter(?ATNSimulator $interpreter): void
    {
        $this->interp = $interpreter;
    }

    /**
     * What is the error header, normally line/character position information?
     */
    public function getErrorHeader(RecognitionException $e): string
    {
        $token = $e->getOffendingToken();

        if ($token === null) {
            return '';
        }

        return \sprintf('line %d:%d', $token->getLine(), $token->getCharPositionInLine());
    }

    public function addErrorListener(ANTLRErrorListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function removeErrorListeners(): void
    {
        $this->listeners = [];
    }

    public function getErrorListenerDispatch(): ANTLRErrorListener
    {
        return new ProxyErrorListener($this->listeners);
    }

    /**
     * Subclass needs to override these if there are sempreds or actions
     * that the ATN interp needs to execute
     */
    public function sempred(?RuleContext $localctx, int $ruleIndex, int $actionIndex): bool
    {
        return true;
    }

    public function precpred(RuleContext $localctx, int $precedence): bool
    {
        return true;
    }

    public function action(?RuleContext $localctx, int $ruleIndex, int $actionIndex): void
    {
        // Overridden by subclasses
    }

    public function getState(): int
    {
        return $this->stateNumber;
    }

    /**
     * Indicate that the recognizer has changed internal state that is
     * consistent with the ATN state passed in. This way we always know
     * where we are in the ATN as the parser goes along. The rule
     * context objects form a stack that lets us see the stack of
     * invoking rules. Combine this and we have complete ATN
     * configuration information.
     */
    public function setState(int $atnState): void
    {
        $this->stateNumber = $atnState;
    }

    abstract public function getInputStream(): ?IntStream;

    abstract public function setInputStream(IntStream $input): void;

    abstract public function getTokenFactory(): TokenFactory;

    abstract public function setTokenFactory(TokenFactory $input): void;

    /**
     * @return array<string>
     */
    abstract public function getRuleNames(): array;

    /**
     * Get the {@see ATN} used by the recognizer for prediction.
     *
     * @return ATN The {@see ATN} used by the recognizer for prediction.
     */
    abstract public function getATN(): ATN;
}
