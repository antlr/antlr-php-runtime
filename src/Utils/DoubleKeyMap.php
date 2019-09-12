<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Utils;

final class DoubleKeyMap
{
    /**
     * Map<primaryKey, Map<secondaryKey, value>>
     *
     * @var Map
     */
    private $data;

    public function __construct()
    {
        $this->data = new Map();
    }

    /**
     * @param mixed $primaryKey
     * @param mixed $secondaryKey
     * @param mixed $value
     */
    public function set($primaryKey, $secondaryKey, $value) : void
    {
        $secondaryData = $this->data->get($primaryKey);

        if ($secondaryData === null) {
            $secondaryData = new Map();

            $this->data->put($primaryKey, $secondaryData);
        }

        $secondaryData->put($secondaryKey, $value);
    }

    /**
     * @param mixed $primaryKey
     * @param mixed $secondaryKey
     *
     * @return mixed
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
     * @param mixed $primaryKey
     */
    public function getByOneKey($primaryKey) : Map
    {
        return $this->data->get($primaryKey);
    }

    /**
     * @param mixed $primaryKey
     *
     * @return array<mixed>|null
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
     * @return array<mixed>
     */
    public function primaryKeys() : array
    {
        return $this->data->getKeys();
    }

    /**
     * @param mixed $primaryKey
     *
     * @return array<mixed>|null
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
