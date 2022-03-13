<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Atn\Actions\LexerAction;
use Antlr\Antlr4\Runtime\Atn\Actions\LexerIndexedCustomAction;
use Antlr\Antlr4\Runtime\CharStream;
use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Equatable;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * Represents an executor for a sequence of lexer actions which traversed during
 * the matching operation of a lexer rule (token).
 *
 * The executor tracks position information for position-dependent lexer actions
 * efficiently, ensuring that actions appearing only at the end of the rule do
 * not cause bloating of the {@see DFA} created for the lexer.
 *
 * @author Sam Harwell
 */
final class LexerActionExecutor implements Equatable
{
    /** @var array<LexerAction> */
    private array $lexerActions;

    /**
     * Caches the result of {@see LexerActionExecutor::hashCode()} since
     * the hash code is an element of the performance-critical
     * {@see LexerATNConfig::hashCode()} operation.
     */
    private ?int $cachedHashCode = null;

    /**
     * @param array<LexerAction> $lexerActions
     */
    public function __construct(array $lexerActions)
    {
        $this->lexerActions = $lexerActions;
    }

    /**
     * Creates a {@see LexerActionExecutor} which executes the actions for
     * the input `lexerActionExecutor` followed by a specified `lexerAction`.
     *
     * @param LexerActionExecutor|null $lexerActionExecutor The executor for actions
     *                                                      already traversed by
     *                                                      the lexer while matching
     *                                                      a token within a particular
     *                                                      {@see LexerATNConfig}.
     *                                                      If this is `null`,
     *                                                      the method behaves as
     *                                                      though it were an
     *                                                      empty executor.
     * @param LexerAction              $lexerAction         The lexer action to
     *                                                      execute after the
     *                                                      actions specified in
     *                                                      `lexerActionExecutor`.
     *
     * @return self A {@see LexerActionExecutor} for executing the combine actions
     *              of `lexerActionExecutor` and `lexerAction`.
     */
    public static function append(
        ?LexerActionExecutor $lexerActionExecutor,
        LexerAction $lexerAction,
    ): self {
        if ($lexerActionExecutor === null) {
            return new LexerActionExecutor([$lexerAction]);
        }

        $lexerActions = \array_merge($lexerActionExecutor->lexerActions, [$lexerAction]);

        return new LexerActionExecutor($lexerActions);
    }

    /**
     * Creates a {@see LexerActionExecutor} which encodes the current offset
     * for position-dependent lexer actions.
     *
     * Normally, when the executor encounters lexer actions where
     * {@see LexerAction::isPositionDependent()} returns `true`, it calls
     * {@see IntStream::seek()} on the input {@see CharStream} to set the input
     * position to the <em>end</em> of the current token. This behavior provides
     * for efficient DFA representation of lexer actions which appear at the end
     * of a lexer rule, even when the lexer rule matches a variable number of
     * characters.
     *
     * Prior to traversing a match transition in the ATN, the current offset
     * from the token start index is assigned to all position-dependent lexer
     * actions which have not already been assigned a fixed offset. By storing
     * the offsets relative to the token start index, the DFA representation of
     * lexer actions which appear in the middle of tokens remains efficient due
     * to sharing among tokens of the same length, regardless of their absolute
     * position in the input stream.
     *
     * If the current executor already has offsets assigned to all
     * position-dependent lexer actions, the method returns `this`.
     *
     * @param int $offset The current offset to assign to all position-dependent
     *                    lexer actions which do not already have offsets assigned.
     *
     * @return self A {@see LexerActionExecutor} which stores input stream offsets
     *              for all position-dependent lexer actions.
     */
    public function fixOffsetBeforeMatch(int $offset): self
    {
        $updatedLexerActions = null;

        for ($i = 0, $count = \count($this->lexerActions); $i < $count; $i++) {
            if ($this->lexerActions[$i]->isPositionDependent()
                && !$this->lexerActions[$i] instanceof LexerIndexedCustomAction) {
                if ($updatedLexerActions === null) {
                    $updatedLexerActions = \array_merge($this->lexerActions, []);
                }

                $updatedLexerActions[$i] = new LexerIndexedCustomAction($offset, $this->lexerActions[$i]);
            }
        }

        if ($updatedLexerActions === null) {
            return $this;
        }

        return new LexerActionExecutor($updatedLexerActions);
    }

    /**
     * Gets the lexer actions to be executed by this executor.
     *
     * @return array<LexerAction> The lexer actions to be executed by this executor.
     */
    public function getLexerActions(): array
    {
        return $this->lexerActions;
    }

    /**
     * Execute the actions encapsulated by this executor within the context of a
     * particular {@see Lexer}.
     *
     * This method calls {@see IntStream::seek()} to set the position of the
     * `input` {@see CharStream} prior to calling {@see LexerAction::execute()}
     * on a position-dependent action. Before the method returns, the input
     * position will be restored to the same position it was in when the method
     * was invoked.
     *
     * @param Lexer      $lexer      The lexer instance.
     * @param CharStream $input      The input stream which is the source for
     *                               the current token. When this method is called,
     *                               the current {@see IntStream::getIndex()} for
     *                               `input` should be the start of the following
     *                               token, i.e. 1 character past the end of the
     *                               current token.
     * @param int        $startIndex The token start index. This value may be
     *                               passed to {@see IntStream::seek()} to set
     *                               the `input` position to the beginning
     *                               of the token.
     */
    public function execute(Lexer $lexer, CharStream $input, int $startIndex): void
    {
        $requiresSeek = false;
        $stopIndex = $input->getIndex();

        try {
            foreach ($this->lexerActions as $lexerAction) {
                if ($lexerAction instanceof LexerIndexedCustomAction) {
                    $offset = $lexerAction->getOffset();
                    $input->seek($startIndex + $offset);
                    $lexerAction = $lexerAction->getAction();
                    $requiresSeek = $startIndex + $offset !== $stopIndex;
                } elseif ($lexerAction->isPositionDependent()) {
                    $input->seek($stopIndex);
                    $requiresSeek = false;
                }

                $lexerAction->execute($lexer);
            }
        } finally {
            if ($requiresSeek) {
                $input->seek($stopIndex);
            }
        }
    }

    public function hashCode(): int
    {
        if ($this->cachedHashCode === null) {
            $this->cachedHashCode = Hasher::hash($this->lexerActions);
        }

        return $this->cachedHashCode;
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return $other instanceof self
            && $this->hashCode() === $other->hashCode()
            && Equality::equals($this->lexerActions, $other->lexerActions);
    }

    public function __toString(): string
    {
        return \sprintf(
            'LexerActionExecutor[%s]',
            \implode(', ', \array_map('\strval', $this->lexerActions)),
        );
    }
}
