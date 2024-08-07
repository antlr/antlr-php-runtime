<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\Utils\Map;

/**
 * The reason that we need this is because we don't want the hash map to use
 * the standard hash code and equals. We need all configurations with the same
 * {@code (s,i,_,semctx)} to be equal. Unfortunately, this key effectively doubles
 * the number of objects associated with ATNConfigs. The other solution is to
 * use a hash table that lets us specify the equals/hashcode operation.
 *
 * @extends Map<ATNConfig, ATNConfig>
 */
final class ConfigHashSet extends Map
{
    public function __construct(?Equivalence $comparer = null)
    {
        if ($comparer === null) {
            parent::__construct(new class implements Equivalence {
                public function equivalent(Hashable $left, Hashable $right): bool
                {
                    if (! $left instanceof ATNConfig) {
                        return false;
                    }

                    if (! $right instanceof ATNConfig) {
                        return false;
                    }

                    if ($left === $right) {
                        return true;
                    }

                    return $left->state->stateNumber === $right->state->stateNumber
                            && $left->alt === $right->alt
                            && $left->semanticContext->equals($right->semanticContext);
                }

                public function hash(Hashable $value): int
                {
                    if (! $value instanceof ATNConfig) {
                        return 0;
                    }

                    return Hasher::hash(
                        $value->state->stateNumber,
                        $value->alt,
                        $value->semanticContext,
                    );
                }

                public function equals(object $other): bool
                {
                    return $other instanceof self;
                }
            });
        } else {
            parent::__construct($comparer);
        }
    }

    public function getOrAdd(ATNConfig $config): ATNConfig
    {
        /** @var ?ATNConfig $existing */
        $existing = null;
        if ($this->tryGetValue($config, $existing)) {
            return $existing;
        } else {
            $this->put($config, $config);

            return $config;
        }
    }
}
