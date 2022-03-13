<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Atn;

use Antlr\Antlr4\Runtime\Atn\SemanticContexts\SemanticContext;
use Antlr\Antlr4\Runtime\Comparison\Equality;
use Antlr\Antlr4\Runtime\Comparison\Equivalence;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\Comparison\Hasher;
use Antlr\Antlr4\Runtime\PredictionContexts\PredictionContext;
use Antlr\Antlr4\Runtime\Utils\BitSet;
use Antlr\Antlr4\Runtime\Utils\DoubleKeyMap;
use Antlr\Antlr4\Runtime\Utils\Set;

/**
 * Specialized {@see Set} of `{@see ATNConfig}`s that can track info
 * about the set, with support for combining similar configurations using
 * a graph-structured stack.
 */
class ATNConfigSet implements Hashable
{
    /**
     * Indicates that the set of configurations is read-only. Do not
     * allow any code to manipulate the set; DFA states will point at
     * the sets and they must not change. This does not protect the other
     * fields; in particular, conflictingAlts is set after
     * we've made this readonly.
     */
    protected bool $readOnly = false;

    /**
     * All configs but hashed by (s, i, _, pi) not including context. Wiped out
     * when we go readonly as this set becomes a DFA state.
     */
    public ?Set $configLookup = null;

    /**
     * Track the elements as they are added to the set; supports get(i).
     *
     * @var array<ATNConfig>
     */
    public array $configs = [];

    public int $uniqueAlt = 0;

    /**
     * Currently this is only used when we detect SLL conflict; this does
     * not necessarily represent the ambiguous alternatives. In fact, I should
     * also point out that this seems to include predicated alternatives that
     * have predicates that evaluate to false. Computed in computeTargetState().
     */
    protected ?BitSet $conflictingAlts = null;

    /**
     * Used in parser and lexer. In lexer, it indicates we hit a pred
     * while computing a closure operation. Don't make a DFA state from this.
     */
    public bool $hasSemanticContext = false;

    public bool $dipsIntoOuterContext = false;

    /**
     * Indicates that this configuration set is part of a full context LL
     * prediction. It will be used to determine how to merge $. With SLL it's
     * a wildcard whereas it is not for LL context merge.
     */
    public bool $fullCtx;

    private ?int $cachedHashCode = null;

    public function __construct(bool $fullCtx = true)
    {
        /*
         * The reason that we need this is because we don't want the hash map to
         * use the standard hash code and equals. We need all configurations with
         * the same `(s,i,_,semctx)` to be equal. Unfortunately, this key
         * effectively doubles the number of objects associated with ATNConfigs.
         * The other solution is to use a hash table that lets us specify the
         * equals/hashcode operation. All configs but hashed by (s, i, _, pi)
         * not including context. Wiped out when we go readonly as this se
         * becomes a DFA state.
         */
        $this->configLookup = new Set(new class implements Equivalence {
            public function equivalent(Hashable $left, Hashable $right): bool
            {
                if ($left === $right) {
                    return true;
                }

                if (!$left instanceof ATNConfig || !$right instanceof ATNConfig) {
                    return false;
                }

                return $left->alt === $right->alt
                    && $left->semanticContext->equals($right->semanticContext)
                    && Equality::equals($left->state, $right->state);
            }

            public function hash(Hashable $value): int
            {
                return $value->hashCode();
            }

            public function equals(object $other): bool
            {
                return $other instanceof self;
            }
        });

        $this->fullCtx = $fullCtx;
    }

    /**
     * Adding a new config means merging contexts with existing configs for
     * `(s, i, pi, _)`, where `s` is the {@see ATNConfig::$state}, `i` is the
     * {@see ATNConfig::$alt}, and `pi` is the {@see ATNConfig::$semanticContext}.
     * We use `(s,i,pi)` as key.
     *
     * This method updates {@see ATNConfigSet::$dipsIntoOuterContext} and
     * {@see ATNConfigSet::$hasSemanticContext} when necessary.
     *
     * @throws \InvalidArgumentException
     */
    public function add(ATNConfig $config, ?DoubleKeyMap $mergeCache = null): bool
    {
        if ($this->readOnly || $this->configLookup === null) {
            throw new \InvalidArgumentException('This set is readonly.');
        }

        if ($config->semanticContext !== SemanticContext::none()) {
            $this->hasSemanticContext = true;
        }

        if ($config->reachesIntoOuterContext > 0) {
            $this->dipsIntoOuterContext = true;
        }

        /** @var ATNConfig $existing */
        $existing = $this->configLookup->getOrAdd($config);

        if ($existing->equals($config)) {
            $this->cachedHashCode = null;

            $this->configs[] = $config; // track order here

            return true;
        }

        // A previous (s,i,pi,_), merge with it and save result
        $rootIsWildcard = !$this->fullCtx;

        if ($existing->context === null || $config->context === null) {
            throw new \LogicException('Unexpected null context.');
        }

        $merged = PredictionContext::merge($existing->context, $config->context, $rootIsWildcard, $mergeCache);

        // No need to check for existing->context, config->context in cache
        // since only way to create new graphs is "call rule" and here. We
        // cache at both places.
        $existing->reachesIntoOuterContext = \max(
            $existing->reachesIntoOuterContext,
            $config->reachesIntoOuterContext,
        );

        // Make sure to preserve the precedence filter suppression during the merge
        if ($config->isPrecedenceFilterSuppressed()) {
            $existing->setPrecedenceFilterSuppressed(true);
        }

        // Replace context; no need to alt mapping
        $existing->context = $merged;

        return true;
    }

    /**
     * Return a List holding list of configs.
     *
     * @return array<ATNConfig>
     */
    public function elements(): array
    {
        return $this->configs;
    }

    public function getStates(): Set
    {
        $states = new Set();
        foreach ($this->configs as $config) {
            $states->add($config->state);
        }

        return $states;
    }

    /**
     * Gets the complete set of represented alternatives for the configurationc set.
     *
     * @return BitSet The set of represented alternatives in this configuration set.
     */
    public function getAlts(): BitSet
    {
        $alts = new BitSet();
        foreach ($this->configs as $config) {
            $alts->add($config->alt);
        }

        return $alts;
    }

    /**
     * @return array<SemanticContext>
     */
    public function getPredicates(): array
    {
        $predicates = [];
        foreach ($this->configs as $config) {
            if ($config->semanticContext !== SemanticContext::none()) {
                $predicates[] = $config->semanticContext;
            }
        }

        return $predicates;
    }

    public function get(int $index): ATNConfig
    {
        return $this->configs[$index];
    }

    public function optimizeConfigs(ATNSimulator $interpreter): void
    {
        if ($this->readOnly || $this->configLookup === null) {
            throw new \InvalidArgumentException('This set is readonly');
        }

        if ($this->configLookup->isEmpty()) {
            return;
        }

        foreach ($this->configs as $config) {
            if ($config->context !== null) {
                $config->context = $interpreter->getCachedContext($config->context);
            }
        }
    }

    /**
     * @param array<ATNConfig> $configs
     */
    public function addAll(array $configs): void
    {
        foreach ($configs as $config) {
            $this->add($config);
        }
    }

    public function equals(object $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if (!$other instanceof self) {
            return false;
        }

        return $this->fullCtx === $other->fullCtx
            && $this->uniqueAlt === $other->uniqueAlt
            && $this->hasSemanticContext === $other->hasSemanticContext
            && $this->dipsIntoOuterContext === $other->dipsIntoOuterContext
            && Equality::equals($this->configs, $other->configs)
            && Equality::equals($this->conflictingAlts, $other->conflictingAlts);
    }

    public function hashCode(): int
    {
        if (!$this->isReadOnly()) {
            return Hasher::hash($this->configs);
        }

        if ($this->cachedHashCode === null) {
            $this->cachedHashCode = Hasher::hash($this->configs);
        }

        return $this->cachedHashCode;
    }

    public function getLength(): int
    {
        return \count($this->configs);
    }

    public function isEmpty(): bool
    {
        return $this->getLength() === 0;
    }

    public function contains(object $item): bool
    {
        if ($this->configLookup === null) {
            throw new \InvalidArgumentException('This method is not implemented for readonly sets.');
        }

        return $this->configLookup->contains($item);
    }

    public function containsFast(ATNConfig $item): bool
    {
        return $this->contains($item);
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->configs);
    }

    public function clear(): void
    {
        if ($this->readOnly) {
            throw new \InvalidArgumentException('This set is readonly');
        }

        $this->configs = [];
        $this->cachedHashCode = -1;
        $this->configLookup = new Set();
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function setReadonly(bool $readOnly): void
    {
        $this->readOnly = $readOnly;

        if ($readOnly) {
            $this->configLookup = null; // can't mod, no need for lookup cache
        }
    }

    public function getConflictingAlts(): ?BitSet
    {
        return $this->conflictingAlts;
    }

    public function setConflictingAlts(BitSet $conflictingAlts): void
    {
        $this->conflictingAlts = $conflictingAlts;
    }

    public function __toString(): string
    {
        return \sprintf(
            '[%s]%s%s%s%s',
            \implode(', ', $this->configs),
            $this->hasSemanticContext ? ',hasSemanticContext=' . $this->hasSemanticContext : '',
            $this->uniqueAlt !== ATN::INVALID_ALT_NUMBER ? ',uniqueAlt=' . $this->uniqueAlt : '',
            $this->conflictingAlts !== null ? ',conflictingAlts=' . $this->conflictingAlts : '',
            $this->dipsIntoOuterContext ? ',dipsIntoOuterContext' : '',
        );
    }
}
