<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Lexer;

/**
 * Executes a custom lexer action by calling {@see Recognizer::action()} with the
 * rule and action indexes assigned to the custom action. The implementation of
 * a custom action is added to the generated code for the lexer in an override
 * of {@see Recognizer::action()} when the grammar is compiled.
 *
 * This class may represent embedded actions created with the `{...}`
 * syntax in ANTLR 4, as well as actions created for lexer commands where the
 * command argument could not be evaluated when the grammar was compiled.
 *
 * @author Sam Harwell
 */
final class LexerCustomAction implements LexerAction
{
    private int $ruleIndex;

    private int $actionIndex;

    /**
     * Constructs a custom lexer action with the specified rule and action
     * indexes.
     *
     * @param int $ruleIndex   The rule index to use for calls to
     *                         {@see Recognizer::action()}.
     * @param int $actionIndex The action index to use for calls to
     *                         {@see Recognizer::action()}.
     */
    public function __construct(int $ruleIndex, int $actionIndex)
    {
        $this->ruleIndex = $ruleIndex;
        $this->actionIndex = $actionIndex;
    }

    /**
     * Gets the rule index to use for calls to {@see Recognizer::action()}.
     *
     * @return int The rule index for the custom action.
     */
    public function getRuleIndex(): int
    {
        return $this->ruleIndex;
    }

    /**
     * Gets the action index to use for calls to {@see Recognizer::action()}.
     *
     * @return int The action index for the custom action.
     */
    public function getActionIndex(): int
    {
        return $this->actionIndex;
    }

    /**
     * {@inheritdoc}
     *
     * @return int This method returns {@see LexerActionType::CUSTOM()}.
     */
    public function getActionType(): int
    {
        return LexerActionType::CUSTOM;
    }

    /**
     * Gets whether the lexer action is position-dependent. Position-dependent
     * actions may have different semantics depending on the {@see CharStream}
     * index at the time the action is executed.
     *
     * Custom actions are position-dependent since they may represent a
     * user-defined embedded action which makes calls to methods like
     * {@see Lexer::getText()}.
     *
     * @return bool This method returns `true`.
     */
    public function isPositionDependent(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Custom actions are implemented by calling {@see Lexer::action()} with the
     * appropriate rule and action indexes.
     */
    public function execute(Lexer $lexer): void
    {
        $lexer->action(null, $this->ruleIndex, $this->actionIndex);
    }

    public function hashCode(): int
    {
        return Hasher::hash($this->getActionType(), $this->ruleIndex, $this->actionIndex);
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->ruleIndex === $other->ruleIndex
            && $this->actionIndex === $other->actionIndex;
    }
}
