<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\PredictionContexts;

use Antlr\Antlr4\Runtime\Atn\ATN;
use Antlr\Antlr4\Runtime\Atn\ParserATNSimulator;
use Antlr\Antlr4\Runtime\Atn\Transitions\RuleTransition;
use Antlr\Antlr4\Runtime\Comparison\Hashable;
use Antlr\Antlr4\Runtime\LoggerProvider;
use Antlr\Antlr4\Runtime\RuleContext;
use Antlr\Antlr4\Runtime\Utils\DoubleKeyMap;
use Antlr\Antlr4\Runtime\Utils\Map;

abstract class PredictionContext implements Hashable
{
    private static int $globalNodeCount = 1;

    public int $id;

    /**
     * Represents `$` in an array in full context mode, when `$` doesn't mean
     * wildcard: `$ + x = [$,x]`.
     *
     * Here, `$` = {@see PredictionContext::EMPTY_RETURN_STATE}.
     */
    public const EMPTY_RETURN_STATE = 0x7FFFFFFF;

    /**
     * The seed every prediction-context hash starts from, matching
     * `PredictionContext.INITIAL_HASH` in the reference runtime.
     */
    public const INITIAL_HASH = 1;

    /**
     * Stores the computed hash code of this {@see PredictionContext}. The hash
     * code is computed in parts to match the following reference algorithm.
     *
     *     private int referenceHashCode() {
     *         int hash = {@see MurmurHash::initialize}({@see PredictionContext::INITIAL_HASH});
     *
     *         for (int i = 0; i < {@see PredictionContext::size()}; i++) {
     *             hash = {@see MurmurHash::update}(hash, {@see PredictionContext::getParent()});
     *         }
     *
     *         for (int i = 0; i < {@see PredictionContext::size()}; i++) {
     *             hash = {@see MurmurHash::update}(hash, {@see PredictionContext::getReturnState()});
     *         }
     *
     *         hash = {@see MurmurHash::finish}(hash, 2 * {@see PredictionContext::size()});
     *
     *         return hash;
     *     }
     */
    public ?int $cachedHashCode = null;

    public function __construct()
    {
        $this->id = self::$globalNodeCount++;
    }

    private static ?EmptyPredictionContext $empty = null;

    public static function empty(): EmptyPredictionContext
    {
        if (self::$empty === null) {
            self::$globalNodeCount--;
            self::$empty = new EmptyPredictionContext();
            self::$empty->id = 0;
        }

        return self::$empty;
    }

    /**
     * Convert a {@see RuleContext} tree to a {@see PredictionContext} graph.
     * Return {@see PredictionContext::empty()} if `outerContext` is empty or null.
     */
    public static function fromRuleContext(ATN $atn, ?RuleContext $outerContext): PredictionContext
    {
        if ($outerContext === null) {
            $outerContext = RuleContext::emptyContext();
        }

        // If we are in RuleContext of start rule, s, then PredictionContext
        // is EMPTY. Nobody called us. (if we are empty, return empty)
        if ($outerContext->getParent() === null || $outerContext === RuleContext::emptyContext()) {
            return self::empty();
        }

        // If we have a parent, convert it to a PredictionContext graph
        $parent = self::fromRuleContext($atn, $outerContext->getParent());
        $state = $atn->states[$outerContext->invokingState];
        $transition = $state->getTransition(0);

        if (!$transition instanceof RuleTransition) {
            throw new \LogicException('Unexpected transition type.');
        }

        return SingletonPredictionContext::create($parent, $transition->followState->stateNumber);
    }

    /**
     * This means only the {@see PredictionContext::empty()} (wildcard? not sure)
     * context is in set.
     */
    public function isEmpty(): bool
    {
        return $this === self::empty();
    }

    public function hasEmptyPath(): bool
    {
        return $this->getReturnState($this->getLength() - 1) === self::EMPTY_RETURN_STATE;
    }

    public function hashCode(): int
    {
        if ($this->cachedHashCode === null) {
            $this->cachedHashCode = $this->computeHashCode();
        }

        return $this->cachedHashCode;
    }

    abstract protected function computeHashCode(): int;

    abstract public function getLength(): int;

    abstract public function getParent(int $index): ?self;

    abstract public function getReturnState(int $index): int;

    abstract public function __toString(): string;

    /**
     * @param DoubleKeyMap<PredictionContext,PredictionContext,PredictionContext>|null $mergeCache
     */
    public static function merge(
        PredictionContext $a,
        PredictionContext $b,
        bool $rootIsWildcard,
        ?DoubleKeyMap $mergeCache,
    ): PredictionContext {
        // share same graph if both same
        if ($a->equals($b)) {
            return $a;
        }

        if ($a instanceof SingletonPredictionContext && $b instanceof SingletonPredictionContext) {
            return self::mergeSingletons($a, $b, $rootIsWildcard, $mergeCache);
        }

        // At least one of a or b is array
        // If one is $ and rootIsWildcard, return $ as * wildcard
        if ($rootIsWildcard) {
            if ($a instanceof EmptyPredictionContext) {
                return $a;
            }

            if ($b instanceof EmptyPredictionContext) {
                return $b;
            }
        }

        // convert singleton so both are arrays to normalize
        if ($a instanceof SingletonPredictionContext) {
            $a = ArrayPredictionContext::fromOne($a);
        }

        if ($b instanceof SingletonPredictionContext) {
            $b = ArrayPredictionContext::fromOne($b);
        }

        if (!$a instanceof ArrayPredictionContext || !$b instanceof ArrayPredictionContext) {
            throw new \LogicException('Unexpected transition type.');
        }

        return self::mergeArrays($a, $b, $rootIsWildcard, $mergeCache);
    }

    /**
     * Merge two {@see SingletonPredictionContext} instances.
     *
     * Stack tops equal, parents merge is same; return left graph.
     *
     * Same stack top, parents differ; merge parents giving array node, then
     * remainders of those graphs. A new root node is created to point to the
     * merged parents.
     *
     * Different stack tops pointing to same parent. Make array node for the
     * root where both element in the root point to the same (original)
     * parent.
     *
     * Different stack tops pointing to different parents. Make array node for
     * the root where each element points to the corresponding original
     * parent.
     *
     * @param DoubleKeyMap<PredictionContext,PredictionContext,PredictionContext>|null $mergeCache
     */
    public static function mergeSingletons(
        SingletonPredictionContext $a,
        SingletonPredictionContext $b,
        bool $rootIsWildcard,
        ?DoubleKeyMap $mergeCache,
    ): PredictionContext {
        if ($mergeCache !== null) {
            $previous = $mergeCache->getByTwoKeys($a, $b);

            if ($previous !== null) {
                return $previous;
            }

            $previous = $mergeCache->getByTwoKeys($b, $a);

            if ($previous !== null) {
                return $previous;
            }
        }

        $rootMerge = self::mergeRoot($a, $b, $rootIsWildcard);

        if ($rootMerge !== null) {
            if ($mergeCache !== null) {
                $mergeCache->set($a, $b, $rootMerge);
            }

            return $rootMerge;
        }

        if ($a->returnState === $b->returnState) {
            if ($a->parent === null || $b->parent === null) {
                throw new \LogicException('Unexpected null parents.');
            }

            $parent = self::merge($a->parent, $b->parent, $rootIsWildcard, $mergeCache);

            // If parent is same as existing a or b parent or reduced to a parent, return it
            if ($parent === $a->parent) {
                return $a; // ax + bx = ax, if a=b
            }

            if ($parent === $b->parent) {
                return $b; // ax + bx = bx, if a=b
            }

            // Else: ax + ay = a'[x,y]
            //
            // Merge parents x and y, giving array node with x,y then remainders
            // of those graphs. dup a, a' points at merged array new joined parent
            // so create new singleton pointing to it, a'
            $spc = SingletonPredictionContext::create($parent, $a->returnState);

            if ($mergeCache !== null) {
                $mergeCache->set($a, $b, $spc);
            }

            return $spc;
        } else {
            // a != b payloads differ
            // see if we can collapse parents due to $+x parents if local ctx
            $singleParent = null;

            // Java collapses on `a.parent.equals(b.parent)`; comparing by identity
            // missed every equal-but-distinct parent, so `ax + bx = [a,b]x` never
            // fired and the context graph grew where Java's stayed flat.
            if ($a === $b || ($a->parent !== null && $b->parent !== null && $a->parent->equals($b->parent))) {
                // ax +
                // bx =
                // [a,b]x
                $singleParent = $a->parent;
            }

            if ($singleParent !== null) {
                // parents are same
                // sort payloads and use same parent
                $payloads = [$a->returnState, $b->returnState];

                if ($a->returnState > $b->returnState) {
                    $payloads[0] = $b->returnState;
                    $payloads[1] = $a->returnState;
                }

                $parents = [$singleParent, $singleParent];
                $apc = new ArrayPredictionContext($parents, $payloads);

                if ($mergeCache !== null) {
                    $mergeCache->set($a, $b, $apc);
                }

                return $apc;
            }

            // parents differ and can't merge them. Just pack together
            // into array; can't merge.
            // ax + by = [ax,by]
            $payloads = [$a->returnState, $b->returnState];
            $parents = [$a->parent, $b->parent];

            if ($a->returnState > $b->returnState) {
                // sort by payload
                $payloads[0] = $b->returnState;
                $payloads[1] = $a->returnState;
                $parents = [$b->parent, $a->parent];
            }

            $a_ = new ArrayPredictionContext($parents, $payloads);

            if ($mergeCache !== null) {
                $mergeCache->set($a, $b, $a_);
            }

            return $a_;
        }
    }

    /**
     * Handle case where at least one of `a` or `b` is
     * {@see PredictionContext::empty()}. In the following diagrams, the symbol
     * `$` is used to represent {@see PredictionContext::empty()}.
     *
     * Local-Context Merges
     *
     * These local-context merge operations are used when `rootIsWildcard`
     * is true.
     *
     * {@see PredictionContext::empty()} is superset of any graph; return
     * {@see PredictionContext::empty()}.
     *
     * [[img src="images/LocalMerge_EmptyRoot.svg" type="image/svg+xml"]]
     *
     * {@see PredictionContext::empty()} and anything is `#EMPTY`, so merged parent is
     * `#EMPTY`; return left graph
     *
     * [[img src="images/LocalMerge_EmptyParent.svg" type="image/svg+xml"]]
     *
     * Special case of last merge if local context.
     *
     * [[img src="images/LocalMerge_DiffRoots.svg" type="image/svg+xml"]]
     *
     * Full-Context Merges
     *
     * These full-context merge operations are used when `rootIsWildcard`
     * is false.
     *
     * Must keep all contexts; {@see PredictionContext::empty()} in array is
     * a special value (and null parent).
     *
     * [[img src="images/FullMerge_EmptyRoot.svg" type="image/svg+xml"]]
     *
     * [[img src="images/FullMerge_SameRoot.svg" type="image/svg+xml"]]
     */
    public static function mergeRoot(
        SingletonPredictionContext $a,
        SingletonPredictionContext $b,
        bool $rootIsWildcard,
    ): ?PredictionContext {
        if ($rootIsWildcard) {
            if ($a === self::empty()) {
                return self::empty();// // + b =//
            }

            if ($b === self::empty()) {
                return self::empty();// a +// =//
            }
        } else {
            if ($a === self::empty() && $b === self::empty()) {
                return self::empty();// $ + $ = $
            }

            if ($a === self::empty()) {
                // $ + x = [$,x]
                $payloads = [$b->returnState, self::EMPTY_RETURN_STATE];
                $parents = [$b->parent, null];

                return new ArrayPredictionContext($parents, $payloads);
            }

            if ($b === self::empty()) {
                // x + $ = [$,x] ($ is always first if present)
                $payloads = [$a->returnState, self::EMPTY_RETURN_STATE];
                $parents = [$a->parent, null];

                return new ArrayPredictionContext($parents, $payloads);
            }
        }

        return null;
    }

    /**
     * Merge two {@see ArrayPredictionContext} instances.
     *
     * @param DoubleKeyMap<PredictionContext,PredictionContext,PredictionContext>|null $mergeCache
     */
    public static function mergeArrays(
        ArrayPredictionContext $a,
        ArrayPredictionContext $b,
        bool $rootIsWildcard,
        ?DoubleKeyMap $mergeCache,
    ): PredictionContext {
        if ($mergeCache !== null) {
            $previous = $mergeCache->getByTwoKeys($a, $b);

            if ($previous !== null) {
                if (ParserATNSimulator::$traceAtnSimulation) {
                    LoggerProvider::getLogger()
                        ->debug('mergeArrays a={a},b={b} -> previous', [
                            'a' => $a->__toString(),
                            'b' => $b->__toString(),
                        ]);
                }

                return $previous;
            }

            $previous = $mergeCache->getByTwoKeys($b, $a);

            if ($previous !== null) {
                if (ParserATNSimulator::$traceAtnSimulation) {
                    LoggerProvider::getLogger()
                        ->debug('mergeArrays a={a},b={b} -> previous', [
                            'a' => $a->__toString(),
                            'b' => $b->__toString(),
                        ]);
                }

                return $previous;
            }
        }

        // merge sorted payloads a + b => M
        $i = 0;// walks a
        $j = 0;// walks b
        $k = 0;// walks target M array

        $mergedReturnStates = [];
        $mergedParents = [];

        // walk and merge to yield mergedParents, mergedReturnStates
        while ($i < \count($a->returnStates) && $j < \count($b->returnStates)) {
            $a_parent = $a->parents[$i];
            $b_parent = $b->parents[$j];

            if ($a->returnStates[$i] === $b->returnStates[$j]) {
                // same payload (stack tops are equal), must yield merged singleton
                $payload = $a->returnStates[$i];

                // $+$ = $
                $bothDollars = $payload === self::EMPTY_RETURN_STATE && $a_parent === null && $b_parent === null;
                $ax_ax = ($a_parent !== null && $b_parent !== null && $a_parent->equals($b_parent));// ax+ax
                // ->
                // ax

                if ($bothDollars || $ax_ax) {
                    $mergedParents[$k] = $a_parent;// choose left
                    $mergedReturnStates[$k] = $payload;
                } else {
                    if ($a_parent === null || $b_parent === null) {
                        throw new \LogicException('Unexpected null parents.');
                    }

                    // ax+ay -> a'[x,y]
                    $mergedParent = self::merge($a_parent, $b_parent, $rootIsWildcard, $mergeCache);
                    $mergedParents[$k] = $mergedParent;
                    $mergedReturnStates[$k] = $payload;
                }

                $i++;// hop over left one as usual
                $j++;// but also skip one in right side since we merge
            } elseif ($a->returnStates[$i] < $b->returnStates[$j]) {
                // copy a[i] to M
                $mergedParents[$k] = $a_parent;
                $mergedReturnStates[$k] = $a->returnStates[$i];
                $i++;
            } else {
                // b > a, copy b[j] to M
                $mergedParents[$k] = $b_parent;
                $mergedReturnStates[$k] = $b->returnStates[$j];
                $j++;
            }

            $k++;
        }

        // copy over any payloads remaining in either array
        if ($i < \count($a->returnStates)) {
            for ($p = $i, $count = \count($a->returnStates); $p < $count; $p++) {
                $mergedParents[$k] = $a->parents[$p];
                $mergedReturnStates[$k] = $a->returnStates[$p];
                $k++;
            }
        } else {
            for ($p = $j, $count = \count($b->returnStates); $p < $count; $p++) {
                $mergedParents[$k] = $b->parents[$p];
                $mergedReturnStates[$k] = $b->returnStates[$p];
                $k++;
            }
        }

        // trim merged if we combined a few that had same stack tops
        if ($k < \count($mergedParents)) {
            // write index < last position; trim
            if ($k === 1) {
                // for just one merged element, return singleton top
                $a_ = SingletonPredictionContext::create($mergedParents[0], $mergedReturnStates[0]);

                if ($mergeCache !== null) {
                    $mergeCache->set($a, $b, $a_);
                }

                return $a_;
            }

            $mergedParents = \array_slice($mergedParents, 0, $k);
            $mergedReturnStates = \array_slice($mergedReturnStates, 0, $k);
        }

        self::combineCommonParents($mergedParents);

        $M = new ArrayPredictionContext($mergedParents, $mergedReturnStates);

        // If we created the same array as a or b, return that instead.
        // Java compares with `equals()`; identity can never hold here because `M`
        // was just constructed, so the fast path never fired and every merge
        // allocated a fresh context.
        // TODO: track whether this is possible above during merge sort for speed
        if ($M->equals($a)) {
            if ($mergeCache !== null) {
                $mergeCache->set($a, $b, $a);
            }

            if (ParserATNSimulator::$traceAtnSimulation) {
                LoggerProvider::getLogger()
                    ->debug('mergeArrays a={a},b={b} -> a', [
                        'a' => $a->__toString(),
                        'b' => $b->__toString(),
                    ]);
            }

            return $a;
        }

        if ($M->equals($b)) {
            if ($mergeCache !== null) {
                $mergeCache->set($a, $b, $b);
            }

            if (ParserATNSimulator::$traceAtnSimulation) {
                LoggerProvider::getLogger()
                    ->debug('mergeArrays a={a},b={b} -> b', [
                        'a' => $a->__toString(),
                        'b' => $b->__toString(),
                    ]);
            }

            return $b;
        }

        if ($mergeCache !== null) {
            $mergeCache->set($a, $b, $M);
        }

        if (ParserATNSimulator::$traceAtnSimulation) {
            LoggerProvider::getLogger()
                // `{M}` — the placeholder was missing its braces, so the trace
                // printed a literal "M" instead of the merged context.
                ->debug('mergeArrays a={a},b={b} -> {M}', [
                    'a' => $a->__toString(),
                    'b' => $b->__toString(),
                    'M' => $M->__toString(),
                ]);
        }

        return $M;
    }

    /**
     * Makes a pass over all `M` parents and merges any that are `equals()`.
     *
     * @param array<PredictionContext|null> $parents
     */
    protected static function combineCommonParents(array &$parents): void
    {
        // `SplObjectStorage` keys on object *identity*, so the previous version
        // could only ever collapse a parent onto itself — the canonicalisation
        // never happened and equal-but-distinct parents accumulated. Java uses a
        // `HashMap`, whose whole purpose here is `equals()`-based lookup.
        /** @var Map<PredictionContext, PredictionContext> $uniqueParents */
        $uniqueParents = new Map();

        foreach ($parents as $parent) {
            // Java's `HashMap` tolerates a null key; a null parent simply has
            // nothing to canonicalise against.
            if ($parent !== null && !$uniqueParents->contains($parent)) {
                $uniqueParents->put($parent, $parent); // don't replace
            }
        }

        foreach ($parents as $i => $parent) {
            if ($parent !== null) {
                $parents[$i] = $uniqueParents->get($parent);
            }
        }
    }

    /**
     * @param \SplObjectStorage<PredictionContext, PredictionContext> $visited
     *        an identity map, mirroring Java's `IdentityHashMap`
     */
    public static function getCachedPredictionContext(
        PredictionContext $context,
        PredictionContextCache $contextCache,
        \SplObjectStorage $visited,
    ): self {
        if ($context->isEmpty()) {
            return $context;
        }

        // Identity, deliberately: this map memoises "which object did I already
        // rewrite?" during one traversal. Keying it on `spl_object_id()` in a
        // plain array — as this used to — is unsafe, because ids are reused once
        // an object is collected, so an id could alias two different contexts.
        $existing = $visited[$context] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        $existing = $contextCache->get($context);

        if ($existing !== null) {
            $visited[$context] = $existing;

            return $existing;
        }

        $changed = false;
        $parents = [];
        for ($i = 0; $i < $context->getLength(); $i++) {
            $parentContext = $context->getParent($i);

            if ($parentContext === null) {
                continue;
            }

            $parent = self::getCachedPredictionContext($parentContext, $contextCache, $visited);

            // Identity, as Java has it: the cache exists to canonicalise
            // instances, so "did this parent get replaced?" is an identity
            // question. `equals()` would report no change for an equal-but-
            // distinct instance and leave the un-canonicalised one in place.
            if ($changed || $parent !== $parentContext) {
                if (!$changed) {
                    $parents = [];

                    for ($j = 0; $j < $context->getLength(); $j++) {
                        $parents[$j] = $context->getParent($j);
                    }

                    $changed = true;
                }

                $parents[$i] = $parent;
            }
        }

        if (!$changed) {
            $contextCache->add($context);

            $visited[$context] = $context;

            return $context;
        }

        $updated = null;

        if (\count($parents) === 0) {
            $updated = self::empty();
        } elseif (\count($parents) === 1) {
            $updated = SingletonPredictionContext::create($parents[0], $context->getReturnState(0));
        } else {
            if (!$context instanceof ArrayPredictionContext) {
                throw new \LogicException('Unexpected context type.');
            }

            $updated = new ArrayPredictionContext($parents, $context->returnStates);
        }

        $contextCache->add($updated);
        $visited[$updated] = $updated;
        $visited[$context] = $updated;

        return $updated;
    }

    public function __clone()
    {
        $this->cachedHashCode = null;
    }
}
