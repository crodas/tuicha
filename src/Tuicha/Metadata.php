<?php
/*
  +---------------------------------------------------------------------------------+
  | Copyright (c) 2017 César D. Rodas                                               |
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
use Remember\Remember;
use RuntimeException;
use Notoj\Annotation\Annotation;
use Notoj\Annotation\Annotations;
use InvalidArgumentException;
use Datetime;
use UnexpectedValueException;
use MongoDB\BSON\UTCDateTime;
use Doctrine\Common\Inflector\Inflector;
use Notoj\ReflectionClass;
use Notoj\ReflectionProperty;
use Notoj\ReflectionMethod;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON;

/**
 * Metadata
 *
 * This metadata object is a reflection-like meta class which exposes details about how Tuicha should
 * treat the documents and the collection.
 *
 * Its constructor is private, so it should be used through its public interface, `Metadata::of(<className>)`.
 *
 * Because it extracts information using the reflection API and parsing annotations the metadata
 * are cached to disk for efficiency. Although any modification to the original file where the class is defined
 * will invalidate the metadata cache.
 *
 * Beside caching this class also provide run time capabilites which helps Tuicha.
 */
class Metadata
{
    protected static $allEvents = [
        'retrieved' => ['retrieved'],
        'creating'  => ['creating', 'before_create', 'beforeCreate'],
        'created'   => ['created', 'after_create', 'afterCreate'],
        'updating'  => ['updating', 'before_update', 'beforeUpdate'],
        'updated'   => ['updated', 'after_update', 'afterUpdate'],
        'saving'    => ['saving', 'before_save', 'beforeSave'],
        'saved'     => ['saved', 'after_save', 'afterSave'],
        'deleting'  => ['deleting', 'before_delete', 'beforeDelete'],
        'deleted'   => ['deleted', 'after_delete', 'afterDelete' ],
    ];

    protected $className;
    protected $instance;
    protected $scopes = [];
    protected $collectionName;
    protected $singleCollection = false;
    protected $hasOwnCollection = true;
    protected $idProperty = null;
    protected $files;
    protected $hasTrait = false;
    protected $pProps  = [];
    protected $mProps  = [];
    protected $indexes = [];
    protected $events = [];
    protected $observers = [];
    protected static $instances = [];

    /**
     * Metadata extraction functions
     *
     * These methods are used during the metadata construction process.
     *
     * Because it may be expensive to do it over an over it is cached.
     */

    /**
     * Class constructor
     *
     * This method is private on porpuse, by doing so it is not possible to construct outside of this scope.
     *
     * The only way of creating a Metadata object is through the `of` static method. This method will
     * either create a new object (which expensive because it has to read all the class metadata) or it
     * will unserialize from cache (the best scenario).
     *
     * @return this
     */
    final private function __construct($className)
    {
        $this->className = $className;
        $this->readClassMetadata();
    }

    /**
     * Reads the every metadata associated with this class.
     *
     * Extract information about:
     *  - Properties
     *  - Methods
     *  - Events
     *
     * The end result is stored in cached for efficiency. The cache storage
     * and invalidation is handled by `crodas\Remember`.
     *
     * @return void
     */
    protected function readClassMetadata()
    {
        if (!class_exists($this->className)) {
            throw new RuntimeException("Cannot find the class {$this->className}");
        }

        $reflection  = new ReflectionClass($this->className);
        $this->files = [$reflection->getFileName()];
        $collection  = $this->getCollectionNameFromParentClasses($reflection);
        $annotations = $reflection->getAnnotations();
        $this->hasTrait = in_array(Document::class, $reflection->getTraitNames());

        $this->readScopes($reflection);

        if (!$collection && $annotations->has('persist,table,collection')) {
            $collection = $annotations->getOne('persist,table,collection')->getArg(0);
        } else if (!$collection) {
            $class = explode("\\", $this->className);
            $collection = strtolower(Inflector::pluralize(end($class)));
        }

        $this->collectionName   = $collection;
        $this->singleCollection = $annotations->has('singlecollection');

        foreach ($reflection->getProperties() as $property) {
            $this->processProperty($property);
        }

        foreach ($reflection->getMethods() as $method) {
            $this->processMethod($method);
        }

        if (!$this->idProperty) {
            $definition = [
                'annotations' => [],
                'validations' => [],
                'required' => false,
                'is_public' => true,
                'is_private' => false,
                'type' => 'id',
                'mongoProp' => '_id',
                'phpProp' => 'id',
            ];

            $this->pProps['id'] = $definition;
            $this->mProps['_id'] = $definition;
            $this->idProperty  = 'id';
        }
    }

    /**
     * Read all the local scopes defined in a class.
     *
     * This function will read all scope functions defined in the current class.
     *
     * @link https://laravel.com/docs/5.6/eloquent#local-scope
     * @link https://laravel.com/docs/5.6/eloquent#local-scopess
     *
     * @param ReflectionClass $reflection
     */
    protected function readScopes(ReflectionClass $reflection)
    {
        $this->instance = $reflection->isAbstract() ? null : $reflection->newInstanceWithoutConstructor();
        $this->scopes   = [];
        if (!$this->instance) {
            return;
        }

        foreach ($reflection->getMethods() as $method) {
            if (!preg_match('/^scope(.+)/i', $method->getName(), $matches)) {
                continue;
            }
            $this->scopes[strtolower($matches[1])] = [
                $method->getName(),
                count($method->getParameters())-1,
            ];
        }
    }

    /**
     * Checks in the parent classes if a collection name is defined already.
     *
     * This only has any effect if any ancestral class has a @SingleCollection annotation.
     *
     * @param ReflectionClass $reflection Current class reflection object.
     *
     * @return string
     */
    protected function getCollectionNameFromParentClasses($reflection)
    {
        while ($reflection = $reflection->getParentClass()) {
            $parent = Metadata::of($reflection->getName());
            $this->files[] = $reflection->getFileName();
            if ($parent->isSingleCollection()) {
                $this->hasOwnCollection = false;
                return $parent->getCollectionName();
            }
        }

        $this->files = array_unique($this->files);

        return null;
    }

    /**
     * Returns a metadata object associated with a class name.
     *
     * This method will return a Metadata object associated with a class name.
     *
     * This method ensures the object is constructed at most once per request. This method
     * also caches the object for efficiency. All the cache storing and invalidation
     * is handled by `crodas\Remember`.
     *
     * @param string $className The class name
     *
     * @return Metadata object.
     */
    public static function of($className)
    {
        static $loader;
        if (is_object($className)) {
            $className = get_class($className);
        }

        if (empty($loader)) {
            $loader = Remember::wrap('tuicha', function(&$args) {
                // The object is not in cache or it is not longer valid
                // Therefore these things are happening:
                //    - A new object is created.
                //    - That object is both returned and cached.
                //    - The file where the class is defined is added
                //      to the file list. Any modification to this file
                //      will invalidate the cached data.
                $className = $args[0];
                $metadata  = new self($args[0]);

                // The metadata has changed (or it is new), so it is fair
                // to define all the indexes. MongoDB is smart enough to create
                // or update all the needed indexes
                $metadata->createIndexes();

                // We must watch for any changes in the files
                $args = array_merge($args, $metadata->getFiles());

                return $metadata;
            });
        }

        if (empty(self::$instances[$className])) {
            $createIndex = false;
            self::$instances[$className] = $loader($className);
        }

        return self::$instances[$className];
    }

    /**
     * Returns the metadata object associated to a MongoDB collection
     *
     * Returns the metadata object of the PHP class associated to a MongoDB collection
     *
     * @param string $collectionName
     *
     * @return Metadata
     */
    public static function ofCollection($collectionName)
    {
        $class = Tuicha::getCollectionClass($collectionName);
        return $class ? self::of($class) : null;
    }

    /**
     * Saves an object in MongoDB *if* they use the Document trait.
     *
     * @param object $object    Object to save
     *
     * @return object
     */
    protected function save($object)
    {
        if (is_callable([$object, 'save'])) {
            $object->save();
        }
        return $object;
    }

    /**
     * Serializes a PHP value to store in MongoDB
     *
     *   1. Scalar values and MongoDB\BSON\Type objects are stored as is.
     *   2. Any property that begins with __ is ignored (not persisted).
     *   3. Any resource is ignored.
     *   4. PHP's Datetime objects are converted to MongoDB\BSON\UTCDateTime
     *   5. Any object is serialized with their own Metadata object (Metadata::serializeValue)
     *
     * @param string $propertyName  Property name
     * @param array  $definition    Proprety definition
     * @param mixed  &$value        Value to serialize. It is by reference, it is OK to edit it in place.
     * @param boolean $validate     Whether or not to validate
     *
     * @return boolean TRUE if the property was serialized, FALSE if it should be ignored.
     */
    protected function serializeValue($propertyName, $definition, &$value, $validate = true)
    {
        if (substr($propertyName, 0, 2) === '__' || is_resource($value)) {
            return false;
        }

        if ($value instanceof BSON\Serializable) {
            $value = $this->save($value)->bsonSerialize();
            return true;
        }

        if (!empty($definition['type']['type']) && $definition['type']['type'] !== 'class') {
            settype($value, $definition['type']['type']);
        }

        if ($value instanceof BSON\Type || is_scalar($value)) {
            return true;
        }

        if ($value instanceof Datetime) {
            $value = new UTCDateTime($value);
            return true;
        }

        if (is_array($value)) {
            // Change the data type for the element
            $childDefinition = [];
            if (!empty($definition['type']['element'])) {
                $childDefinition['type'] = $definition['type']['element'];
            } else {
                $childDefinition['type'] = [];
            }

            foreach ($value as $key => $val) {
                $this->serializeValue($propertyName, $childDefinition, $val, $validate);
                $value[$key] = $val;
            }
        }

        if (is_object($value)) {
            $class = strtolower(get_class($value));
            $meta  = Metadata::of($class);
            if (array_key_exists('is_reference', $definition) && $definition['is_reference'] !== false) {
                $with = [];
                if (!empty($definition['is_reference']['with'])) {
                    $with = (array) $definition['is_reference']['with'];
                }
                $value = $meta->makeReference($this->save($value), $with);
                return true;
            }

            $value = $meta->toDocument($value, $validate);
            if (!$definition || empty($definition['type']['class']) || strtolower($definition['type']['class']) !== $class) {
                // Tuicha must save the object class name to be able to populate it back.
                $value['__type'] = compact('class');
            }
        }

        return true;
    }

    /**
     * Returns all the arguments from an array of annotations
     *
     * @param array $annotations An array of Notoj\Annotation\Annotation objects
     *
     * @return array
     */
    protected function getAnnotationArguments(Array $annotations)
    {
        $arguments = [];
        foreach ($annotations as $annotation) {
            foreach ($annotation->getArgs() as $arg) {
                $arguments[] = $arg;
            }
        }

        foreach ($arguments as $id => $function) {
            $args = [];
            if ($function instanceof Annotation) {
                $args     = $function->getArgs();
                $function = $function->getName();
            }

            if (is_callable([Validation::class, $function])) {
                $function = [Validation::class, $function];
            } else if (is_string($function) && strpos($function, "::") > 0) {
                $function = explode("::", $function, 2);
            }
            $arguments[$id] = [$function, $args];
        }

        return $arguments;
    }

    /**
     * Adds an array definition to the Metadata object.
     *
     * @return void
     */
    protected function defineIndex(Array $index)
    {
        $name = [!empty($index['unique']) ? 'unique' : 'index'];
        foreach ($index['key'] as $field => $asc) {
            $name[] = $field . '_' . ($asc ? 'asc' : 'desc');
        }

        $index['name'] = implode('_', $name);
        $this->indexes[]= $index;
    }

    /**
     * Processes Indexes defined in properties
     *
     * Creates indexes and unique indexes defined in properties.
     *
     * @param array $propData           The property definition
     * @param Annotations $annotations  All the annotations defined in the property
     *
     * @return void
     */
    protected function processPropertyIndexes(Array $propData, Annotations $annotations)
    {
        $index = $annotations->getOne('index,unique');
        if (!$index) {
            return;
        }

        $args = $index->getArgs();
        if (empty($args['asc']) && empty($args['desc'])) {
            $order = 1;
        } else if (!empty($args['asc'])) {
            $order = !empty($args['asc']) ? 1 : -1;
        } else {
            $order = empty($args['desc']) ? 1 : -1;
        }
        $this->defineIndex([
            'key' => [$propData['mongoProp'] => $order],
            'unique' => $index->getName() === 'unique',
            'sparse' => !empty($args['sparse']),
            'background' => true,
        ]);
    }

    /**
     * Get the type definition from a single Annotation.
     *
     *
     * @return array
     */
    protected function getDataTypeFromAnnotation(Annotation $annotation)
    {
        $type = ['type' => $annotation->getName()];

        switch ($annotation->getName()) {
        case 'id':
            $type = 'id';
            break;
        case 'class':
            $type['class'] = $annotation->getArg();
            break;
        case 'array':
            try {
                $arg = $annotation->getArg();
                $type['element'] = $this->getDataTypeFromAnnotation($arg);
            } catch (RuntimeException $e) {
            }
            break;
        case 'type':
            $type = $annotation->getArgs();
            break;
        }

        return $type;
    }

    /**
     * Returns the data type definition for a property
     *
     * @param Annotations $annotations  Property's annotations object
     *
     * @return array
     */
    protected function getDataType(Annotations $annotations)
    {
        $types = 'int,integer,float,double,array,bool,boolean,string,object,class,type,id';
        if ($annotation = $annotations->getOne($types)) {
            return $this->getDataTypeFromAnnotation($annotation);
        }

        return [];
    }

    /**
     * Processes properties
     *
     * Processes properties from a class and extracts all the metadata for future
     * usage.
     *
     * The metadata collected includes:
     *    - Property name (PHP)
     *    - Key name (stored in MongoDB)
     *    - Datatype for conversion and/or validation
     *    - Any index that may be defined.
     *
     * @param Notoj\ReflectionProperty $property
     *
     * @return void
     */
    protected function processProperty(ReflectionProperty $property)
    {
        $annotations = $property->getAnnotations();
        $phpName     = $property->getName();
        $mongoName   = $annotations->has('field') ? $annotations->getOne('field')->getArg(0) : $phpName;

        if (!$property->isPublic() && substr($phpName, 0, 2) === '__') {
            return;
        }

        if ($annotations->has('id')) {
            $mongoName = '_id';
            $this->idProperty = $phpName;
        }

        $propData = [
            'annotations' => [],
            'validations' => $this->getAnnotationArguments($annotations->get('validate')),
            'is_reference' => $annotations->has('reference') ? $annotations->getOne('reference')->getArgs() : false,
            'required'    => $annotations->has('required'),
            'is_public'   => $property->isPublic(),
            'is_private'  => $property->isPrivate(),
            'type'        => $this->getDataType($annotations),
            'mongoProp'   => $mongoName,
            'phpProp'     => $phpName,
        ];

        $this->processPropertyIndexes($propData, $annotations);

        foreach ($annotations as $annotation) {
            $propData['annotations'][] = [$annotation->getName(), $annotation->getArgs()];
        }

        $this->pProps[$phpName]   = $propData;
        $this->mProps[$mongoName] = $propData;
    }

    /**
     * Returns an array of all the annotations and the events they represent.
     *
     * @return array.
     */
    protected function getAllEventAnnotations()
    {
        $allEvents = [];
        foreach (self::$allEvents as $eventName => $events) {
            foreach ($events as $alias) {
                $allEvents[$alias] = $eventName;
            }
        }

        return $allEvents;
    }

    /**
     * Processes methods from a class.
     *
     * Processes methods from a class, if they have any annotation which represent an
     * event it will be recorded in the class metadata object.
     *
     * @return void
     */
    protected function processMethod(ReflectionMethod $method)
    {
        $events = $this->getAllEventAnnotations();
        $annotations = implode(",", array_keys($events));

        if (!$method->getAnnotations()->has($annotations)) {
            return;
        }

        foreach ($method->getAnnotations()->get($annotations) as $annotation) {
            $event = $events[$annotation->getName()];
            $this->events[$event][] = [
                'method' => $method->getName(),
                'is_public' => $method->isPublic(),
                'args' => $annotation->getArgs(),
            ];
        }
    }


    /**
     * Runtime functions
     *
     * These functions exposes information about the metadata associated with a class
     * (and its collection).
     *
     * These functions may also perform some operations needed by Tuicha
     */

    /**
     * Returns the class name associated to this metadata object
     *
     * @return string
     */
    public function getClassName()
    {
        return $this->className;
    }

    /**
     * Returns TRUE if this class is defined a SingleCollection
     *
     * If Single Collections is true, all child-classes will be stored in the same
     * collection. Tupa will store the class name in the `__type` property.
     *
     * @return bool
     */
    public function isSingleCollection()
    {
        return $this->singleCollection;
    }

    /**
     * Returns TRUE if the current class has their own collection
     *
     * Some classes may not have their own collection, this happens when a parent
     * class, if any, has the `@SingleCollection` annotation.
     *
     * @return bool
     */
    public function hasOwnCollection()
    {
        return $this->hasOwnCollection;
    }

    /**
     * Returns all the indexes defined for this class (and its collection)
     *
     * @return array
     */
    public function getIndexes()
    {
        return $this->indexes;
    }

    /**
     * Returns the filename where the current class is defined.
     *
     * @retun string
     */
    public function getFile()
    {
        return $this->files[0];
    }

    /**
     * Returns all the files where the current (and their parent classes) are defined.
     *
     * @return array
     */
    public function getFiles()
    {
        return $this->files;
    }

    /**
     * Returns all the scopes defined in the current class
     *
     * @return array
     */
    public function getScopes()
    {
        return $this->scopes;
    }

    /**
     * Returns an instance of this class
     *
     * This instance was constructed without calling the constructor and it is quite useful
     * for calling non-static methods as static. It is currently used in the scopes
     *
     * @return object
     */
    public function getInstance()
    {
        return $this->instance;
    }

    /**
     * Returns information about the connection to this collection
     *
     * @return array
     */
    public function getCollection()
    {
        static $cache = [];
        if (empty($cache[$this->className])) {
            $connection = Tuicha::getConnection('default');
            $cache[$this->className] = new Collection($this->collectionName, $connection);
        }

        return $cache[$this->className];
    }

    /**
     * Returns the collection name.
     *
     * If $globalName is true, the database name is prepend to the collection name.
     *
     * @param bool $globalName Whether to include the database name or not.
     *
     * @return string
     */
    public function getCollectionName($globalName = false)
    {
        return $this->getCollection()->getName($globalName);
    }

    /**
     * Triggers an event.
     *
     * This function triggers an event in a given object.
     *
     * @param object $object    Object to execute the event.
     * @param string $eventName Event name
     *
     * @return $this
     */
    public function triggerEvent($object, $eventName)
    {
        if (!empty($this->events[$eventName])) {
            foreach ($this->events[$eventName] as $event) {
                if ($event['is_public']) {
                    $object->{$event['method']}($event['args']);
                } else {
                    throw new RuntimeException("Only public methods are supported for now");
                }
            }
        }

        if (!empty(self::$allEvents[$eventName])) {
            foreach ($this->observers as $observer) {
                foreach (self::$allEvents[$eventName] as $alias) {
                    if (is_callable([$observer, $alias])) {
                        $observer->$alias($object);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Register an observer class
     *
     * The external observer are called whenever an event is trigged.
     *
     * @param string $className
     */
    public function registerObserver($className)
    {
        if (!class_exists($className)) {
            throw new RuntimeException("$className is not a valid class");
        }

        $this->observers[] = new $className;
        return $this;
    }

    /**
     * Creates a new instance of a given $type
     *
     * @param array $type
     * @param array $document
     * @param bool  $isNested   If this object is nested it shouldn't take its own snapshot
     *
     * @return object
     */
    protected function newInstanceByType($type, $document, $isNested = false)
    {
        if (!empty($type['class']) && $document) {
            return Metadata::of($type['class'])->newInstance((array)$document, $isNested);
        }

        return $document;
    }

    protected function hydratate($prop, $value)
    {
        if (is_scalar($value)) {
            return $value;
        }

        if (is_object($value)) {
            return $value;
        }

        if (!empty($value['$ref']) && !empty($value['$id'])) {
            $value = new Reference($value);
        } else if (!empty($prop['type'])) {
            $value = $this->newInstanceByType($prop['type'], $value, true);
        } else if (!empty($value['__type'])) {
            $value = $this->newInstanceByType($value['__type'], $value, true);
        } else if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->hydratate($prop, $v);
            }
        }

        return $value;
    }

    /**
     * Creates a new instance object.
     *
     * This function will take a document from the database (an array) and will
     * return a PHP object. It uses the metadata if available.
     *
     * @param array $document
     * @param bool  $isNested   If this object is nested it shouldn't take its own snapshot
     *
     * @return object
     */
    public function newInstance(array $document, $isNested = false)
    {
        static $reflections = [];

        $class  = empty($document['__type']['class']) ? $this->className : $document['__type']['class'];
        $_class = strtolower($class);
        if (empty($reflections[$_class])) {
            $reflections[$_class] = new ReflectionClass($class);
        }
        $object = $reflections[$_class]->newInstanceWithoutConstructor();
        foreach ($document as $key => $value) {
            $prop = null;
            if (!empty($this->pProps[$key]) ||  !empty($this->mProps[$key])) {
                $prop = !empty($this->mProps[$key]) ? $this->mProps[$key] : $this->pProps[$key];
                $key  = $prop['phpProp'];
            }

            $value = is_scalar($value) ? $value : $this->hydratate($prop, $value);

            if (!$prop || $prop['is_public']) {
                $object->$key = $value;
            } else {
                $property = new ReflectionProperty($this->className, $key);
                $property->setAccessible(true);
                $property->setValue($object, $value);
            }
        }

        if (!$isNested) {
            $this->snapshot($object);
        }

        return $object;
    }

    /**
     * Creates all the indexes defined in the class for this collection
     *
     * @return Cursor|null
     */
    public function createIndexes()
    {
        $indexes = $this->getIndexes();
        if ($indexes) {
            return Tuicha::command([
                'createIndexes' => $this->getCollectionName(),
                'indexes' => $this->getIndexes(),
            ]);
        }
    }

    /**
     * Returns the property name where the Document Id is stored.
     *
     * @return string
     */
    public function getIdProperty()
    {
        return $this->idProperty;
    }

    public function getProperties()
    {
        return $this->pProps;
    }

    /**
     * Returns the Document ID of an object.
     *
     * @return mixed
     */
    public function getId($object)
    {
        $id = $this->pProps[$this->idProperty];
        if ($id['is_public']) {
            return $object->{$id['phpProp']};
        }

        $property = new ReflectionProperty($this->className, $id['phpProp']);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    /**
     * Creates an snapshot of the current object.
     *
     * This function is called right after persisting any changes in the database or
     * when a new object is created.
     *
     * It creates a copy of the current data in order to optimise future persisting operations by
     * persisting only changes and not the whole document.
     *
     * @return void
     */
    public function snapshot($object)
    {
        $data = $this->toDocument($object, false, false);

        if (!$this->hasTrait) {
            $object->__lastInstance = $data;
            if (!empty($state['_id'])) {
                $object->__id = $state['_id'];
            }
        } else {
            $object->__setState($data);
        }
    }

    public function makeReference($object, array $fields = [])
    {
        $reference = [
            '$ref' => $this->getCollectionName(),
            '$id'  => $this->getId($object),
        ];

        if (!empty($fields)) {
            $reference['__cache'] = [];
            foreach ($fields as $field) {
                $reference['__cache'][$field] = $this->getPropertyValue($object, $field);
            }
        }

        return new Reference($reference);
    }

    /**
     * Returns the last snapshoted state.
     *
     * @return array
     */
    protected function getLastState($object)
    {
        if ($this->hasTrait) {
            return $object->__getState();
        }

        return !empty($object->__lastInstance)
            ? $object->__lastInstance
            : [];
    }

    /**
     * Returns the command for persisting the current object.
     *
     * This method returns the command that needs to be send to MongoDB to persist
     * a document.
     *
     * This method is also responsible for triggering all the `saving` events (before_save, after_save, etc).
     *
     * @TODO: This method should probably be in another class.
     *
     * @return array
     */
    public function getSaveCommand($object)
    {
        $document = [
            'connection' => $this->getCollection()->getDatabase()->getConnection(),
            'namespace'  => $this->getCollectionName(true),
            'collection' => $this->getCollectionName(),
        ];

        $prevDocument = $this->getLastState($object);

        $this->triggerEvent($object, 'saving');
        if (!$prevDocument) {
            $this->triggerEvent($object, 'creating');
            $document['command']  = 'create';
            $document['document'] = $this->toDocument($object, true, true);
        } else {
            $this->triggerEvent($object, 'updating');
            $document['command'] = 'update';
            $diff = [];
            $new  = $this->toDocument($object);

            $diff = Update::diff($this->toDocument($object), $prevDocument);

            $document['selector'] = ['_id' => $prevDocument['_id']];
            $document['document'] = $diff;
        }

        return $document;
    }

    protected function validate($key, $value, $definition)
    {
        if (empty($value) && $definition['required']) {
            throw new UnexpectedValueException("Unexpected empty value for property $key");
        } else if ($value && !empty($definition['validations'])) {
            foreach ($definition['validations'] as $validation) {
                $response = true;
                if (is_array($validation[0])) {
                    list($class, $method) = $validation[0];
                    $response = $class::$method($value, $validation[1]);
                } else if (is_callable($validation[0])) {
                    $response = $validation[0]($value, $validation[1]);
                }

                if (!$response) {
                    throw new UnexpectedValueException("Invalid value for $key ($value)");
                }
            }
        }

        return $value;
    }

    /**
     * Returns a document which represents the current object state.
     *
     * @param object $object
     * @param bool $validate    TRUE if validations should be enforced
     * @param bool $generateId  TRUE if a new ID should generated if the object does not have one
     *
     * @retun array
     */
    public function toDocument($object, $validate = true, $generateId = false)
    {
        $keys = array_keys((array)$object);
        $keys = array_combine($keys, $keys);
        $array = [];

        if ($object instanceof BSON\Serializable) {
            return $object->bsonSerialize();
        }

        foreach ($this->pProps as $key => $definition) {
            $mongo = $definition['mongoProp'];

            if ($definition['is_public']) {
                if (empty($keys[$key])) {
                    continue;
                }
                $php   = $definition['phpProp'];
                $value = $object->$php;
            } else {
                $property = new ReflectionProperty($this->className, $key);
                $property->setAccessible(true);
                $value = $property->getValue($object);
            }

            if (!$this->serializeValue($key, $definition, $value, $validate)) {
                continue;
            }

            $array[$mongo] = $validate ? $this->validate($key, $value, $definition) : $value;
        }

        foreach (get_object_vars($object) as $key => $value) {
            if (empty($this->pProps[$key]) && empty($this->mProps[$key])) {
                if (!$this->serializeValue($key, [], $value, $validate)) {
                    continue;
                }
                $array[$key] = $value;
            }
        }

        if (!$this->hasOwnCollection) {
            $array['__type'] = ['class' => $this->className];
        }

        if (empty($array['_id']) && $generateId) {
            $array['_id'] = new ObjectID;
            $id = $this->pProps[$this->idProperty];
            if ($id['is_public']) {
                $object->{$this->idProperty} = $array['_id'];
            } else {
                $property = new ReflectionProperty($object, $this->idProperty);
                $property->setAccessible(true);
                $property->setValue($object, $array['_id']);
            }
        }

        return $array;
    }

    /**
     * Returns a property value (private or public) from a document/object.
     *
     * @param object $object
     * @param string $property
     *
     * @return mixed
     */
    public function getPropertyValue($object, $property)
    {
        $value = null;
        if (!empty($this->pProps[$property])) {
            $definition = $this->pProps[$property];
            if ($definition['is_public']) {
                $php   = $definition['phpProp'];
                $value = $object->$php;
            } else {
                $property = new ReflectionProperty($this->className, $property);
                $property->setAccessible(true);
                $value = $property->getValue($object);
            }
        } else if (!empty($object->$property)) {
            $value = $object->$property;
        }
        return $value;
    }

}

