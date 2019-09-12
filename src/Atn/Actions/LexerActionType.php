<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn\Actions;

final class LexerActionType
{
    /**
     * The type of a {@see LexerChannelAction} action.
     */
    public const CHANNEL = 0;

    /**
     * The type of a {@see LexerCustomAction} action.
     */
    public const CUSTOM = 1;

    /**
     * The type of a {@see LexerModeAction} action.
     */
    public const MODE = 2;

    /**
     * The type of a {@see LexerMoreAction} action.
     */
    public const MORE = 3;

    /**
     * The type of a {@see LexerPopModeAction} action.
     */
    public const POP_MODE = 4;

    /**
     * The type of a {@see LexerPushModeAction} action.
     */
    public const PUSH_MODE = 5;

    /**
     * The type of a {@see LexerSkipAction} action.
     */
    public const SKIP = 6;

    /**
     * The type of a {@see LexerTypeAction} action.
     */
    public const TYPE = 7;
}
