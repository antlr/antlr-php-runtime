<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Dfa\DFAState;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContext;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContextCache;

/**
 * The context cache maps all PredictionContext objects that are ==
 * to a single cached copy. This cache is shared across all contexts
 * in all ATNConfigs in all DFA states. We rebuild each ATNConfigSet
 * to use only cached nodes/graphs in addDFAState(). We don't want to
 * fill this during closure() since there are lots of contexts that
 * pop up but are not used ever again. It also greatly slows down closure().
 *
 * This cache makes a huge difference in memory and a little bit in speed.
 * For the Java grammar on java.*, it dropped the memory requirements
 * at the end from 25M to 16M. We don't store any of the full context
 * graphs in the DFA because they are limited to local context only,
 * but apparently there's a lot of repetition there as well. We optimize
 * the config contexts before storing the config set in the DFA states
 * by literally rebuilding them with cached subgraphs only.
 *
 * I tried a cache for use during closure operations, that was
 * whacked after each adaptivePredict(). It cost a little bit
 * more time I think and doesn't save on the overall footprint
 * so it's not worth the complexity.
 */
abstract class ATNSimulator
{
    public ATN $atn;

    /**
     * The context cache maps all PredictionContext objects that are equals()
     * to a single cached copy. This cache is shared across all contexts
     * in all ATNConfigs in all DFA states. We rebuild each ATNConfigSet
     * to use only cached nodes/graphs in addDFAState(). We don't want to
     * fill this during closure() since there are lots of contexts that
     * pop up but are not used ever again. It also greatly slows down closure().
     *
     * This cache makes a huge difference in memory and a little bit in speed.
     * For the Java grammar on java.*, it dropped the memory requirements
     * at the end from 25M to 16M. We don't store any of the full context
     * graphs in the DFA because they are limited to local context only,
     * but apparently there's a lot of repetition there as well. We optimize
     * the config contexts before storing the config set in the DFA states
     * by literally rebuilding them with cached subgraphs only.
     *
     * I tried a cache for use during closure operations, that was
     * whacked after each adaptivePredict(). It cost a little bit
     * more time I think and doesn't save on the overall footprint
     * so it's not worth the complexity.
     */
    protected PredictionContextCache $sharedContextCache;

    public function __construct(ATN $atn, PredictionContextCache $sharedContextCache)
    {
        $this->atn = $atn;
        $this->sharedContextCache = $sharedContextCache;
    }

    public static function error(): DFAState
    {
        static $error;

        return $error ?? ($error = new DFAState(new ATNConfigSet(), 0x7FFFFFFF));
    }

    abstract public function reset(): void;

    /**
     * Clear the DFA cache used by the current instance. Since the DFA cache
     * may be shared by multiple ATN simulators, this method may affect the
     * performance (but not accuracy) of other parsers which are being used
     * concurrently.
     *
     * @throws \InvalidArgumentException If the current instance does not
     *                                   support clearing the DFA.
     */
    public function clearDFA(): void
    {
        throw new \InvalidArgumentException('This ATN simulator does not support clearing the DFA.');
    }

    public function getSharedContextCache(): PredictionContextCache
    {
        return $this->sharedContextCache;
    }

    public function getCachedContext(PredictionContext $context): PredictionContext
    {
        $visited = [];

        return PredictionContext::getCachedPredictionContext(
            $context,
            $this->sharedContextCache,
            $visited,
        );
    }
}
