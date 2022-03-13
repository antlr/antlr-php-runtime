<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

use Antlr\Antlr4\Runtime\Comparison\Hashable;

/**
 * @template K1 of Hashable
 * @template K2 of Hashable
 * @template V
 */
final class DoubleKeyMap
{
    /** @var Map<K1, Map<K2, V>> */
    private Map $data;

    public function __construct()
    {
        $this->data = new Map();
    }

    /**
     * @param K1 $primaryKey
     * @param K2 $secondaryKey
     * @param V  $value
     */
    public function set(Hashable $primaryKey, Hashable $secondaryKey, mixed $value): void
    {
        $secondaryData = $this->data->get($primaryKey);

        if ($secondaryData === null) {
            /** @var Map<K2, V> $secondaryData */
            $secondaryData = new Map();

            $this->data->put($primaryKey, $secondaryData);
        }

        $secondaryData->put($secondaryKey, $value);
    }

    /**
     * @param K1 $primaryKey
     * @param K2 $secondaryKey
     *
     * @return V|null
     */
    public function getByTwoKeys(Hashable $primaryKey, Hashable $secondaryKey): mixed
    {
        $data2 = $this->data->get($primaryKey);

        if ($data2 === null) {
            return null;
        }

        return $data2->get($secondaryKey);
    }

    /**
     * @param K1 $primaryKey
     *
     * @return Map<K2, V>
     */
    public function getByOneKey(Hashable $primaryKey): ?Map
    {
        return $this->data->get($primaryKey);
    }

    /**
     * @param K1 $primaryKey
     *
     * @return array<V>|null
     */
    public function values(Hashable $primaryKey): ?array
    {
        $secondaryData = $this->data->get($primaryKey);

        if ($secondaryData === null) {
            return null;
        }

        return $secondaryData->getValues();
    }

    /**
     * @return array<K1>
     */
    public function primaryKeys(): array
    {
        return $this->data->getKeys();
    }

    /**
     * @param K1 $primaryKey
     *
     * @return array<K2>|null
     */
    public function secondaryKeys(Hashable $primaryKey): ?array
    {
        $secondaryData = $this->data->get($primaryKey);

        if ($secondaryData === null) {
            return null;
        }

        return $secondaryData->getKeys();
    }
}
