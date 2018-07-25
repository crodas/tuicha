<?php
/*
  +---------------------------------------------------------------------------------+
  | Copyright (c) 2018 César D. Rodas                                               |
  +---------------------------------------------------------------------------------+
  | Redistribution and use in source and binary forms, with or without              |
  | modification, are permitted provided that the following conditions are met:     |
  | 1. Redistributions of source code must retain the above copyright               |
  |    notice, this list of conditions and the following disclaimer.                |
  |                                                                                 |
  | 2. Redistributions in binary form must reproduce the above copyright            |
  |    notice, this list of conditions and the following disclaimer in the          |
  |    documentation and/or other materials provided with the distribution.         |
  |                                                                                 |
  | 3. All advertising materials mentioning features or use of this software        |
  |    must display the following acknowledgement:                                  |
  |    This product includes software developed by César D. Rodas.                  |
  |                                                                                 |
  | 4. Neither the name of the César D. Rodas nor the                               |
  |    names of its contributors may be used to endorse or promote products         |
  |    derived from this software without specific prior written permission.        |
  |                                                                                 |
  | THIS SOFTWARE IS PROVIDED BY CÉSAR D. RODAS ''AS IS'' AND ANY                   |
  | EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED       |
  | WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE          |
  | DISCLAIMED. IN NO EVENT SHALL CÉSAR D. RODAS BE LIABLE FOR ANY                  |
  | DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES      |
  | (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;    |
  | LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND     |
  | ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT      |
  | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS   |
  | SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE                     |
  +---------------------------------------------------------------------------------+
  | Authors: César Rodas <crodas@php.net>                                           |
  +---------------------------------------------------------------------------------+
*/

namespace Tuicha;

use Tuicha;
use RuntimeException;
use Tuicha\Query\Query;
use MongoDB\BSON\ObjectID;

/**
 * Base document
 *
 * Base document that all classes must inherits. It provides static methods, which are collection
 * operations and classes method which are document operations.
 *
 */
trait Document
{
    private $__lastInstance;
    private $__id;
    private $__version;

    /**
     * Sets the "last Instance" value
     *
     * This value is used to compare the current state of the object
     * against the database value, to push only properties that has
     * any changes.
     *
     * @param array $document
     */
    final public function __setLastInstance(array $document)
    {
        $this->__lastInstance = $document;
        if (!empty($document['_id'])) {
            $this->__id = $document['_id'];
        }

        $this->__version = sha1(serialize($this));
    }

    /**
     * Returns the last instance value.
     *
     * @return array
     */
    final public function __getLastInstance()
    {
        return $this->__lastInstance;
    }

    /**
     * Returns the Tuicha\Metadata instance for the current class/object
     *
     * @return Tuicha\Metadata
     */
    final static function getTuichaMetadata()
    {
        return Metadata::of(static::class);
    }

    /**
     * Finds documents in a collection.
     *
     * @return Tuicha\Query
     */
    final static function find()
    {
        $metadata = Metadata::of(static::class);
        return new Query($metadata, $metadata->getCollection(), func_get_args());
    }

    /**
     * Find a document by a query or throw an exception.
     *
     * @return object
     */
    final static function findOrFail()
    {
        $metadata = Metadata::of(static::class);
        $query = new Query($metadata, $metadata->getCollection(), func_get_args());
        return $query->firstOrFail();
    }

    /**
     * Finds documents in a collection.
     *
     * @return Tuicha\Query
     */
    final static function where()
    {
        $metadata = Metadata::of(static::class);
        return new Query($metadata, $metadata->getCollection(), func_get_args());
    }

    /**
     * Returns an empty query object.
     *
     * @return Tuicha\Query
     */
    final static function newQuery()
    {
        $metadata = Metadata::of(static::class);
        return new Query($metadata, $metadata->getCollection(), []);
    }

    /**
     * Finds one document in a collection or returns a new object
     *
     * @param array $query
     * @param array $fields
     *
     * @return object
     */
    final static function firstOrNew(array $query)
    {
        $doc = self::find($query)->first();
        if ($doc) {
            return $doc;
        }
        $doc = new static;
        foreach ($query as $key => $val) {
            $doc->$key = $val;
        }
        return $doc;
    }

    /**
     * Finds one document in a collection or creates a new document and returns
     * it as an object.
     *
     * @param array $query
     * @param array $fields
     *
     * @return object
     */
    final static function firstOrCreate(array $query)
    {
        $doc = self::firstOrNew($query);
        $doc->save();
        return $doc;
    }

    /**
     * Counts how many records matches a query.
     *
     * @param array|callback $query
     *
     * @return int
     */
    final static function count()
    {
        $metadata = Metadata::of(static::class);
        $q = new Query($metadata, $metadata->getCollection(), func_get_args());
        return $q->count();
    }

    /**
     * Catches static non-defined functions calls
     *
     * These functions are forwarded to the Query object.
     *
     * @param string $function
     * @param array  $args
     *
     * @return mixed
     */
    final static function __callStatic($function, array $args)
    {
        $metadata = Metadata::of(static::class);
        $q = new Query($metadata, $metadata->getCollection());
        return call_user_func_array([$q, $function], $args);
    }

    /**
     * Updates documents matching a selector.
     *
     * @param array|callable $where
     * @param array|callable $set
     *
     * @return Tuicha\Query\Update
     */
    final static function update($where = null, $set = null)
    {
        $metadata = Metadata::of(static::class);
        $query    = Tuicha::update($metadata->getCollectionName());

        if ($where !== null) {
            $query->where($where);
        }

        if ($set !== null) {
            $query->set($set);
        }

        return $query;
    }

    /**
     * Deletes document from the collection
     *
     * @param array|callable $where
     *
     * @return Tuicha\Query\Update
     */
    final static function delete($where = null)
    {
        $query = Tuicha::delete(static::class);

        if ($where !== null) {
            $query->where($where);
        }

        return $query;
    }

    /**
     * Creates a new document
     *
     * Creates a new document from an array. The document is stored in the database before returning.
     *
     * @return object
     */
    final static function create(array $data)
    {
        $document = new static;
        foreach ($data as $key => $value) {
            $document->$key = $value;
        }

        $document->save();

        return $document;
    }

    /**
     * Deletes all records from a collection
     */
    final static function truncate()
    {
        Tuicha::delete(static::class)
            ->execute();
    }

    /**
     * Creates indexes
     *
     * Creates indexes in this collection. All the information is provided by the Metadata object.
     *
     * @return MongoDB\Driver\Cursor
     */
    final public static function createIndexes()
    {
        return Metadata::of(static::class)->createIndexes();
    }

    /**
     * Register a class which is listening for events
     *
     * @param string $className
     */
    final public static function observe($className)
    {
        return Metadata::of(static::class)->registerObserver($className);
    }

    /**
     * Returns the property name where the Document ID is mapped.
     *
     * @return string
     */
    public static function getKeyName()
    {
        return Metadata::of(static::class)->getIdProperty();
    }

    /**
     * Laravel compatibility function
     *
     * Retrieve model for route model binding.
     *
     * @param mixed $routeKey
     *
     * @return mixed
     */
    public static function resolveRouteBinding($routeKey)
    {
        return static::find(['_id' => $routeKey])->first();
    }

    /**
     * Saves the changes in the current document/object.
     *
     * @param boolean $wait
     *
     * @return bool
     */
    final public function save($wait = true)
    {
        return Tuicha::save($this, $wait);
    }

    /**
     * Convert the document instance to an array.
     *
     * @return array
     */
    public function toArray()
    {
        return Metadata::of(static::class)->toDocument($this, false, false);
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Checks whether an object has changes to persists to the database
     *
     * @return bool
     */
    public function isDirty()
    {
        return $this->__version !== sha1(serialize($this));
    }

    /**
     * Convert the model instance to JSON string.
     *
     * @param  int  $options
     * @return string
     *
     * @throws \RuntimeException
     */
    public function toJson($options = 0)
    {
        $json = json_encode($this->jsonSerialize(), $options);
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new RuntimeException(json_last_error_msg());
        }
        return $json;
    }

    /**
     * Convert the model to its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->toJson();
    }
}
