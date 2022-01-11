<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

/**
 * @template TPKey of \Antlr\Antlr4\Runtime\Comparison\Hashable
 * @template TSKey of \Antlr\Antlr4\Runtime\Comparison\Hashable
 * @template TValue
 */
final class DoubleKeyMap
{
    /**
     * Map<primaryKey, Map<secondaryKey, value>>
     *
     * @var Map<TPKey, Map<TSKey, TValue>>
     */
    private $data;

    public function __construct()
    {
        $this->data = new Map();
    }

    /**
     * @param TPKey  $primaryKey
     * @param TSKey  $secondaryKey
     * @param TValue $value
     */
    public function set($primaryKey, $secondaryKey, $value) : void
    {
        $secondaryData = $this->data->get($primaryKey);

        if ($secondaryData === null) {
            /** @var Map<TSKey, TValue> $secondaryData */
            $secondaryData = new Map();

            $this->data->put($primaryKey, $secondaryData);
        }

        $secondaryData->put($secondaryKey, $value);
    }

    /**
     * @param TPKey $primaryKey
     * @param TSKey $secondaryKey
     *
     * @return TValue|null
     */
    public function getByTwoKeys($primaryKey, $secondaryKey)
    {
        $data2 = $this->data->get($primaryKey);

        if ($data2 === null) {
            return null;
        }

        return $data2->get($secondaryKey);
    }

    /**
     * @param TPKey $primaryKey
     *
     * @return Map<TSKey, TValue>|null
     */
    public function getByOneKey($primaryKey) : ?Map
    {
        return $this->data->get($primaryKey);
    }

    /**
     * @param TPKey $primaryKey
     *
     * @return list<TValue>|null
     */
    public function values($primaryKey) : ?array
    {
        $secondaryData = $this->data->get($primaryKey);

        if ($secondaryData === null) {
            return null;
        }

        return $secondaryData->getValues();
    }

    /**
     * @return list<TPKey>
     */
    public function primaryKeys() : array
    {
        return $this->data->getKeys();
    }

    /**
     * @param TPKey $primaryKey
     *
     * @return list<TSKey>|null
     */
    public function secondaryKeys($primaryKey) : ?array
    {
        $secondaryData = $this->data->get($primaryKey);

        if ($secondaryData === null) {
            return null;
        }

        return $secondaryData->getKeys();
    }
}
