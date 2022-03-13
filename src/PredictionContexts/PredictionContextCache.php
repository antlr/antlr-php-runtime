<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\PredictionContexts;

use Antlr\Antlr4\Runtime\Utils\Map;

/**
 * Used to cache {@see PredictionContext} objects. Its used for the shared
 * context cash associated with contexts in DFA states. This cache
 * can be used for both lexers and parsers.
 */
class PredictionContextCache
{
    /** @var Map<PredictionContext, PredictionContext> */
    protected Map $cache;

    public function __construct()
    {
        $this->cache = new Map();
    }

    /**
     * Add a context to the cache and return it. If the context already exists,
     * return that one instead and do not add a new context to the cache.
     * Protect shared cache from unsafe thread access.
     */
    public function add(PredictionContext $ctx): PredictionContext
    {
        if ($ctx === PredictionContext::empty()) {
            return $ctx;
        }

        $existing = $this->cache->get($ctx);

        if ($existing !== null) {
            return $existing;
        }

        $this->cache->put($ctx, $ctx);

        return $ctx;
    }

    public function get(PredictionContext $ctx): ?PredictionContext
    {
        return $this->cache->get($ctx);
    }

    public function length(): int
    {
        return $this->cache->count();
    }
}
