<?php

declare(strict_types=1);

namespace Antlr\Antlr4\Runtime\Tree;

/**
 * Associate a property with a parse tree node.
 *
 * Useful with parse tree listeners that need to associate a value with a
 * particular tree node. For instance when specifying a return value for the
 * listener event method that visited a particular node.
 * You can associate any type of variable with a node.
 *
 * Example use:
 *
 *     use Antlr\Antlr4\Runtime\Tree\ParseTreeProperty;
 *
 *     // Declare a ParseTreeProperty-container for values in your listener
 *     private $values;
 *
 *     // Make a new container in the constructor of the listener
 *     $this->values = new ParseTreeProperty();
 *
 *     // Use it from any method in your listener
 *     // Use it to store or retrieve any value, associated to a specific node
 *     $this->values->put($node, $someValue);
 *     $aValue = $this->values->get($node);
 *     $this->values->removeFrom($node);
 *
 *
 * This class is implemented in PHP as a wrapper around \SplObjectStorage
 */
Class ParseTreeProperty
{
    protected $storage;

    public function  __construct()
    {
        $this->storage = new \SplObjectStorage();
    }

    /**
     * Get the value associated with $node from the storage
     *
     * @param  ParseTree $node  The {@see ParseTree} with which the value is associated.
     * @return mixed     $value The stored value | null when $node is not in the storage.
     */
    public function get(ParseTree $node)
    {
        $value = null;
        if ($this->storage->contains($node))
        {
            $value = $this->storage->offsetGet($node);
        }
        return $value;
    }

    /**
     * Put a value associated with $node in the storage
     *
     * @param  ParseTree $node  The {@see ParseTree} with which the value is associated.
     * @param  mixed     $value Any value
     * @return void
     */
    public function put(ParseTree $node, $value): void
    {
        $this->storage->attach($node, $value);
    }

    /**
     * Remove the value associated with $node from the storage
     *
     * @param  ParseTree $node  The {@see ParseTree} with which the value is associated.
     * @return mixed     $value The removed value  | null when $node was not in the storage.
     */
    public function removeFrom(ParseTree $node)
    {
        $value = $this->get($node);
        $this->storage->detach($node);
        return $value;
    }

}
