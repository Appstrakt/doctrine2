<?php
/*
 *  $Id: Record.php 4342 2008-05-08 14:17:35Z romanb $
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.phpdoctrine.org>.
 */

#namespace Doctrine::ORM;

/**
 * Base class for all Entities (objects with persistent state in a RDBMS that are
 * managed by Doctrine).
 * 
 * NOTE: Methods that are intended for internal use only but must be public
 * are marked INTERNAL: and begin with an underscore "_" to indicate that they
 * ideally would not be public and to minimize naming collisions.
 * 
 * The "final" modifiers on most methods prevent accidental overrides.
 * It is not desirable that subclasses can override these methods.
 * The persistence layer should stay in the background as much as possible.
 *
 * @author      Konsta Vesterinen <kvesteri@cc.hut.fi>
 * @author      Roman Borschel <roman@code-factory.org>
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.phpdoctrine.org
 * @since       2.0
 * @version     $Revision: 4342 $
 */
abstract class Doctrine_Entity extends Doctrine_Access implements Serializable
{
    /**
     * MANAGED
     * An Entity is in managed state when it has a primary key/identifier and is
     * managed by an EntityManager (registered in the identity map).
     */
    const STATE_MANAGED = 1;

    /**
     * NEW
     * An Entity is new if it does not yet have an identifier/primary key
     * and is not (yet) managed by an EntityManager.
     */
    const STATE_NEW = 2;

    /**
     * LOCKED STATE
     * An Entity is temporarily locked during deletes and saves.
     *
     * This state is used internally to ensure that circular deletes
     * and saves will not cause infinite loops.
     * @todo Not sure this is a good idea. It is a problematic solution because
     * it hides the original state while the locked state is active.
     */
    const STATE_LOCKED = 6;
    
    /**
     * A detached Entity is an instance with a persistent identity that is not
     * (or no longer) associated with an EntityManager (and a UnitOfWork).
     * This means its no longer in the identity map.
     */
    const STATE_DETACHED = 3;
    
    /**
     * A removed Entity instance is an instance with a persistent identity,
     * associated with an EntityManager, whose persistent state has been
     * deleted (or is scheduled for deletion).
     */
    const STATE_DELETED = 4;
    
    /**
     * Index used for creating object identifiers (oid's).
     *
     * @var integer
     */
    private static $_index = 1;
    
    /**
     * Boolean flag that indicates whether automatic accessor overriding is enabled.
     *
     * @var boolean
     */
    private static $_useAutoAccessorOverride;
    
    /**
     * The accessor cache is used as a memory for the existance of custom accessors.
     *
     * @var array
     */
    private static $_accessorCache = array();
    
    /**
     * The mutator cache is used as a memory for the existance of custom mutators.
     *
     * @var array
     */
    private static $_mutatorCache = array();
    
    /**
     * The class descriptor.
     *
     * @var Doctrine::ORM::ClassMetadata
     */
    private $_class;
    
    /**
     * The name of the Entity.
     * 
     * @var string
     */
    private $_entityName;

    /**
     * The values that make up the ID/primary key of the entity.
     *
     * @var array                   
     */
    private $_id = array();

    /**
     * The entity data.
     *
     * @var array                  
     */
    private $_data = array();

    /**
     * The state of the object.
     *
     * @var integer
     */
    private $_state;

    /**
     * The names of fields that have been modified but not yet persisted.
     * Keys are field names, values oldValue => newValue tuples.
     *
     * @var array
     * @todo Rename to $_changeSet
     */
    private $_modified = array();

    /**
     * The references for all associations of the entity to other entities.
     *
     * @var array
     */
    private $_references = array();
    
    /**
     * The EntityManager that is responsible for the persistence of the entity.
     *
     * @var Doctrine::ORM::EntityManager
     */
    private $_em;

    /**
     * The object identifier of the object. Each object has a unique identifier
     * during script execution.
     * 
     * @var integer                  
     */
    private $_oid;

    /**
     * Constructor.
     * Creates a new Entity instance.
     */
    public function __construct()
    {
        $this->_entityName = get_class($this);
        $this->_em = Doctrine_EntityManagerFactory::getManager($this->_entityName);
        $this->_class = $this->_em->getClassMetadata($this->_entityName);
        $this->_oid = self::$_index++;
        $this->_data = $this->_em->_getTmpEntityData();
        if ($this->_data) {
            $this->_extractIdentifier();
            $this->_state = self::STATE_MANAGED;
        } else {
            $this->_state = self::STATE_NEW;
        }
        
        // @todo read from attribute the first time and move this initialization elsewhere.
        self::$_useAutoAccessorOverride = true; 
    }
    
    /**
     * Returns the object identifier.
     *
     * @return integer
     */
    final public function getOid()
    {
        return $this->_oid;
    }

    /**
     * Copies the identifier names and values from _data into _id.
     */
    private function _extractIdentifier()
    {
        if ( ! $this->_class->isIdentifierComposite()) {
            // Single field identifier
            $name = $this->_class->getIdentifier();
            $name = $name[0];
            if (isset($this->_data[$name]) && $this->_data[$name] !== Doctrine_Null::$INSTANCE) {
                $this->_id[$name] = $this->_data[$name];
            }
        } else {
            // Composite identifier
            $names = $this->_class->getIdentifier();
            foreach ($names as $name) {
                if ($this->_data[$name] === Doctrine_Null::$INSTANCE) {
                    $this->_id[$name] = null;
                } else {
                    $this->_id[$name] = $this->_data[$name];
                }
            }
        }
    }

    /**
     * Serializes the entity.
     * This method is automatically called when the entity is serialized.
     *
     * Part of the implementation of the Serializable interface.
     *
     * @return array
     */
    public function serialize()
    {
        //$this->_em->getEventManager()->dispatchEvent(Event::preSerialize);
        //$this->_class->dispatchLifecycleEvent(Event::preSerialize, $this);

        $vars = get_object_vars($this);

        unset($vars['_references']);
        unset($vars['_em']);

        //$name = (array)$this->_table->getIdentifier();
        $this->_data = array_merge($this->_data, $this->_id);

        foreach ($this->_data as $k => $v) {
            if ($v instanceof Doctrine_Entity && $this->_class->getTypeOfField($k) != 'object') {
                unset($vars['_data'][$k]);
            } else if ($v === Doctrine_Null::$INSTANCE) {
                unset($vars['_data'][$k]);
            } else {
                switch ($this->_class->getTypeOfField($k)) {
                    case 'array':
                    case 'object':
                        $vars['_data'][$k] = serialize($vars['_data'][$k]);
                        break;
                    case 'gzip':
                        $vars['_data'][$k] = gzcompress($vars['_data'][$k]);
                        break;
                    case 'enum':
                        $vars['_data'][$k] = $this->_class->enumIndex($k, $vars['_data'][$k]);
                        break;
                }
            }
        }
        
        $str = serialize($vars);

        //$this->postSerialize($event);

        return $str;
    }

    /**
     * Reconstructs the entity from it's serialized form.
     * This method is automatically called everytime the entity is unserialized.
     *
     * @param string $serialized                Doctrine_Entity as serialized string
     * @throws Doctrine_Record_Exception        if the cleanData operation fails somehow
     * @return void
     */
    public function unserialize($serialized)
    {
        //$event = new Doctrine_Event($this, Doctrine_Event::RECORD_UNSERIALIZE);
        //$this->preUnserialize($event);

        $this->_entityName = get_class($this);
        $manager = Doctrine_EntityManagerFactory::getManager($this->_entityName);
        $connection = $manager->getConnection();

        $this->_oid = self::$_index;
        self::$_index++;

        $this->_em = $manager;  

        $array = unserialize($serialized);

        foreach($array as $k => $v) {
            $this->$k = $v;
        }
        
        $this->_class = $this->_em->getClassMetadata($this->_entityName);

        foreach ($this->_data as $k => $v) {
            switch ($this->_class->getTypeOfField($k)) {
                case 'array':
                case 'object':
                    $this->_data[$k] = unserialize($this->_data[$k]);
                    break;
                case 'gzip':
                   $this->_data[$k] = gzuncompress($this->_data[$k]);
                    break;
                case 'enum':
                    $this->_data[$k] = $this->_class->enumValue($k, $this->_data[$k]);
                    break;

            }
        }

        $this->_extractIdentifier(!$this->isNew());
        
        //$this->postUnserialize($event);
    }

    /**
     * INTERNAL:
     * Gets or sets the state of this Entity.
     *
     * @param integer|string $state                 if set, this method tries to set the record state to $state
     * @see Doctrine_Entity::STATE_* constants
     *
     * @throws Doctrine_Record_State_Exception      if trying to set an unknown state
     * @return null|integer
     */
    final public function _state($state = null)
    {
        if ($state == null) {
            return $this->_state;
        }
        
        /* TODO: Do we really need this check? This is only for internal use after all. */
        switch ($state) {
            case self::STATE_MANAGED:
            case self::STATE_DELETED:
            case self::STATE_DETACHED:
            case self::STATE_NEW:
            case self::STATE_LOCKED:
                $this->_state = $state;
                break;
            default:
                throw Doctrine_Entity_Exception::invalidState($state);
        }
    }

    /**
     * Gets the current field values.
     *
     * @return array  The fields and their values.                     
     */
    final public function getData()
    {
        return $this->_data;
    }

    /**
     * Gets the value of a field (regular field or reference).
     * If the field is not yet loaded this method does NOT load it.
     *
     * @param $name                         name of the property
     * @throws Doctrine_Entity_Exception    if trying to get an unknown field
     * @return mixed
     */
    final protected function _get($fieldName)
    {
        if (isset($this->_data[$fieldName])) {
            return $this->_internalGetField($fieldName);
        } else if (isset($this->_references[$fieldName])) {
            return $this->_internalGetReference($fieldName);
        } else {
            throw Doctrine_Entity_Exception::unknownField($fieldName);
        }
    }
    
    /**
     * Sets the value of a field (regular field or reference).
     * If the field is not yet loaded this method does NOT load it.
     *
     * @param $name                         name of the field
     * @throws Doctrine_Entity_Exception    if trying to get an unknown field
     * @return mixed
     */
    final protected function _set($fieldName, $value)
    {
        if ($this->_class->hasField($fieldName)) {
            return $this->_internalSetField($fieldName, $value);
        } else if ($this->_class->hasRelation($fieldName)) {
            return $this->_internalSetReference($fieldName, $value);
        } else {
            throw Doctrine_Entity_Exception::unknownField($fieldName);
        }
    }
    
    /**
     * INTERNAL:
     * Gets the value of a field.
     * 
     * NOTE: Use of this method in userland code is strongly discouraged.
     * This method does NOT check whether the field exists.
     * _get() in extending classes should be preferred.
     *
     * @param string $fieldName
     * @return mixed
     * @todo Rename to _unsafeGetField()
     */
    final public function _internalGetField($fieldName)
    {
        if ($this->_data[$fieldName] === Doctrine_Null::$INSTANCE) {
            return null;
        }
        return $this->_data[$fieldName];
    }
    
    /**
     * INTERNAL:
     * Sets the value of a field.
     * 
     * NOTE: Use of this method in userland code is strongly discouraged.
     * This method does NOT check whether the field exists.
     * _set() in extending classes should be preferred.
     *
     * @param string $fieldName
     * @param mixed $value
     */
    final public function _internalSetField($fieldName, $value)
    {
        $this->_data[$fieldName] = $value;
    }
    
    /**
     * Gets a reference to another Entity.
     * 
     * NOTE: Use of this method in userland code is strongly discouraged.
     * This method does NOT check whether the reference exists.
     *
     * @param string $fieldName
     */
    final public function _internalGetReference($fieldName)
    {
        if ($this->_references[$fieldName] === Doctrine_Null::$INSTANCE) {
            return null;
        }
        return $this->_references[$fieldName];
    }
    
    /**
     * INTERNAL:
     * Sets a reference to another Entity.
     * 
     * NOTE: Use of this method in userland code is strongly discouraged.
     *
     * @param string $fieldName
     * @param mixed $value
     * @todo Refactor. What about composite keys?
     * @todo Rename to _unsafeSetReference()
     */
    final public function _internalSetReference($name, $value)
    {
        if ($value === Doctrine_Null::$INSTANCE) {
            $this->_references[$name] = $value;
            return;
        }
        
        $rel = $this->_class->getRelation($name);

        // one-to-many or one-to-one relation
        if ($rel instanceof Doctrine_Relation_ForeignKey ||
                $rel instanceof Doctrine_Relation_LocalKey) {
            if ( ! $rel->isOneToOne()) {
                // one-to-many relation found
                if ( ! $value instanceof Doctrine_Collection) {
                    throw Doctrine_Entity_Exception::invalidValueForOneToManyReference();
                }
                if (isset($this->_references[$name])) {
                    $this->_references[$name]->setData($value->getData());
                    return;
                }
            } else {
                $relatedTable = $value->getTable();
                $foreignFieldName = $rel->getForeignFieldName();
                $localFieldName = $rel->getLocalFieldName();

                // one-to-one relation found
                if ( ! ($value instanceof Doctrine_Entity)) {
                    throw Doctrine_Entity_Exception::invalidValueForOneToOneReference();
                }
                if ($rel instanceof Doctrine_Relation_LocalKey) {
                    $idFieldNames = $value->getTable()->getIdentifier();
                    if ( ! empty($foreignFieldName) && $foreignFieldName != $idFieldNames[0]) {
                        $this->set($localFieldName, $value->_get($foreignFieldName));
                    } else {
                        $this->set($localFieldName, $value);
                    }
                } else {
                    $value->set($foreignFieldName, $this);
                }
            }
        } else if ($rel instanceof Doctrine_Relation_Association) {
            if ( ! ($value instanceof Doctrine_Collection)) {
                throw Doctrine_Entity_Exception::invalidValueForManyToManyReference();
            }
        }

        $this->_references[$name] = $value;
    }

    /**
     * Generic getter for all persistent fields.
     *
     * @param string $fieldName  Name of the field.
     * @return mixed
     */
    final public function get($fieldName)
    {
        if ($getter = $this->_getCustomAccessor($fieldName)) {
            return $this->$getter();
        }
        
        // Use built-in accessor functionality        
        $nullObj = Doctrine_Null::$INSTANCE;
        if (isset($this->_data[$fieldName])) {
            return $this->_data[$fieldName] !== $nullObj ?
                    $this->_data[$fieldName] : null;
        } else if (isset($this->_references[$fieldName])) {
            return $this->_references[$fieldName] !== $nullObj ?
                    $this->_references[$fieldName] : null;
        } else {
            $class = $this->_class;
            if ($class->hasField($fieldName)) {
                return null;
            } else if ($class->hasRelation($fieldName)) {
                $rel = $class->getRelation($fieldName);
                if ($rel->isLazilyLoaded()) {
                    $this->_references[$fieldName] = $rel->lazyLoadFor($this);
                    return $this->_references[$fieldName] !== $nullObj ?
                            $this->_references[$fieldName] : null;
                } else {
                    return null;
                }
            } else {
                throw Doctrine_Entity_Exception::invalidField($fieldName);
            }
        }
    }
    
    /**
     * Gets the custom mutator method for a field, if it exists.
     *
     * @param string $fieldName  The field name.
     * @return mixed  The name of the custom mutator or FALSE, if the field does
     *                not have a custom mutator.
     */
    private function _getCustomMutator($fieldName)
    {
        if ( ! isset(self::$_mutatorCache[$this->_entityName][$fieldName])) {
            if (self::$_useAutoAccessorOverride) {
                $setterMethod = 'set' . Doctrine::classify($fieldName);
                if (method_exists($this, $setterMethod)) {
                    self::$_mutatorCache[$this->_entityName][$fieldName] = $setterMethod;
                } else {
                    self::$_mutatorCache[$this->_entityName][$fieldName] = false;
                }
            }
            
            if ($setter = $this->_class->getCustomMutator($fieldName)) {
                self::$_mutatorCache[$this->_entityName][$fieldName] = $setter;
            } else if ( ! isset(self::$_mutatorCache[$this->_entityName][$fieldName])) {
                self::$_mutatorCache[$this->_entityName][$fieldName] = false;
            }
        }
        
        return self::$_mutatorCache[$this->_entityName][$fieldName];
    }
    
    /**
     * Gets the custom accessor method of a field, if it exists.
     *
     * @param string $fieldName  The field name.
     * @return mixed  The name of the custom accessor method, or FALSE if the 
     *                field does not have a custom accessor.
     */
    private function _getCustomAccessor($fieldName)
    {
        if ( ! isset(self::$_accessorCache[$this->_entityName][$fieldName])) {
            if (self::$_useAutoAccessorOverride) {
                $getterMethod = 'get' . Doctrine::classify($fieldName);
                if (method_exists($this, $getterMethod)) {
                    self::$_accessorCache[$this->_entityName][$fieldName] = $getterMethod;
                } else {
                    self::$_accessorCache[$this->_entityName][$fieldName] = false;
                }
            }
            if ($getter = $this->_class->getCustomAccessor($fieldName)) {
                self::$_accessorCache[$this->_entityName][$fieldName] = $getter;
            } else if ( ! isset(self::$_accessorCache[$this->_entityName][$fieldName])) {
                self::$_accessorCache[$this->_entityName][$fieldName] = false;
            }
        }
        
        return self::$_accessorCache[$this->_entityName][$fieldName];
    }
    
    /**
     * Gets the entity class name.
     *
     * @return string
     */
    final public function getClassName()
    {
        return $this->_entityName;
    }

    /**
     * Generic setter for persistent fields.
     *
     * @param string $name  The name of the field to set.
     * @param mixed $value  The value of the field.
     */
    final public function set($fieldName, $value)
    {
        if ($setter = $this->_getCustomMutator($fieldName)) {
            return $this->$setter($value);
        }
        
        if ($this->_class->hasField($fieldName)) {
            /*if ($value instanceof Doctrine_Entity) {
                $type = $class->getTypeOf($fieldName);
                // FIXME: composite key support
                $ids = $value->identifier();
                $id = count($ids) > 0 ? array_pop($ids) : null;
                if ($id !== null && $type !== 'object') {
                    $value = $id;
                }
            }*/

            $old = isset($this->_data[$fieldName]) ? $this->_data[$fieldName] : null;
            //FIXME: null == 0 => true
            if ($old != $value) {
                $this->_data[$fieldName] = $value;
                $this->_modified[$fieldName] = array($old => $value);
                if ($this->isNew() && $this->_class->isIdentifier($fieldName)) {
                    $this->_id[$fieldName] = $value;
                }
            }
        } else if ($this->_class->hasRelation($fieldName)) {
            $this->_internalSetReference($fieldName, $value);
        } else {
            throw Doctrine_Entity_Exception::invalidField($fieldName);
        }
    }

    /**
     * Checks whether a field is set (not null).
     * 
     * NOTE: Invoked by Doctrine::ORM::Access#__isset().
     *
     * @param string $name
     * @return boolean
     */
    final public function contains($fieldName)
    {
        if (isset($this->_data[$fieldName])) {
            if ($this->_data[$fieldName] === Doctrine_Null::$INSTANCE) {
                return false;
            }
            return true;
        }
        if (isset($this->_id[$fieldName])) {
            return true;
        }
        if (isset($this->_references[$fieldName]) &&
                $this->_references[$fieldName] !== Doctrine_Null::$INSTANCE) {
            return true;
        }
        return false;
    }

    /**
     * Clears the value of a field.
     * 
     * NOTE: Invoked by Doctrine::ORM::Access#__unset().
     * 
     * @param string $name
     * @return void
     */
    final public function remove($fieldName)
    {
        if (isset($this->_data[$fieldName])) {
            $this->_data[$fieldName] = array();
        } else if (isset($this->_references[$fieldName])) {
            if ($this->_references[$fieldName] instanceof Doctrine_Entity) {
                // todo: delete related record when saving $this
                $this->_references[$fieldName] = Doctrine_Null::$INSTANCE;
            } else if ($this->_references[$fieldName] instanceof Doctrine_Collection) {
                $this->_references[$fieldName]->setData(array());
            }
        }
    }
    
    /**
     * INTERNAL:
     * Gets the changeset of the entities persistent state.
     *
     * @return array
     */
    final public function _getChangeSet()
    {
        //return $this->_changeSet;
    }

    /**
     * Returns an array of modified fields and values with data preparation
     * adds column aggregation inheritance and converts Records into primary key values
     *
     * @param array $array
     * @return array
     * @todo What about a little bit more expressive name? getPreparedData?
     * @todo Does not look like the best place here ...
     * @todo Prop: Move to EntityPersister. There call _getChangeSet() and apply this logic.
     */
    final public function getPrepared(array $array = array())
    {
        $dataSet = array();

        if (empty($array)) {
            $modifiedFields = $this->_modified;
        }

        foreach ($modifiedFields as $field) {
            $type = $this->_class->getTypeOfField($field);

            if ($this->_data[$field] === Doctrine_Null::$INSTANCE) {
                $dataSet[$field] = null;
                continue;
            }

            switch ($type) {
                case 'array':
                case 'object':
                    $dataSet[$field] = serialize($this->_data[$field]);
                    break;
                case 'gzip':
                    $dataSet[$field] = gzcompress($this->_data[$field],5);
                    break;
                case 'boolean':
                    $dataSet[$field] = $this->_em->getConnection()
                            ->convertBooleans($this->_data[$field]);
                break;
                case 'enum':
                    $dataSet[$field] = $this->_class->enumIndex($field, $this->_data[$field]);
                    break;
                default:
                    $dataSet[$field] = $this->_data[$field];
            }
        }
        
        // @todo cleanup
        // populates the discriminator field in Single & Class Table Inheritance
        if ($this->_class->getInheritanceType() == Doctrine::INHERITANCE_TYPE_JOINED ||
                $this->_class->getInheritanceType() == Doctrine::INHERITANCE_TYPE_SINGLE_TABLE) {
            $discCol = $this->_class->getInheritanceOption('discriminatorColumn');
            $discMap = $this->_class->getInheritanceOption('discriminatorMap');
            $old = $this->get($discCol, false);
            $discValue = array_search($this->_entityName, $discMap);
            if ((string) $old !== (string) $discValue || $old === null) {
                $dataSet[$discCol] = $discValue;
                $this->_data[$discCol] = $discValue;
            }
        }

        return $dataSet;
    }
    
    /**
     * Checks whether the entity already has a persistent state.
     *
     * @return boolean  TRUE if the object is new, FALSE otherwise.
     */
    final public function isNew()
    {
        return $this->_state == self::STATE_NEW;
    }

    /**
     * Checks whether the entity has been modified since it was last synchronized
     * with the database.
     *
     * @return boolean  TRUE if the object has been modified, FALSE otherwise.
     */
    final public function isModified()
    {
        return count($this->_modified) > 0;
    }

    /**
     * INTERNAL:
     * Assigns an identifier to the entity. This is only intended for use by
     * the EntityPersisters or the UnitOfWork.
     *
     * @param mixed $id
     */
    final public function _assignIdentifier($id)
    {
        if (is_array($id)) {
            foreach ($id as $fieldName => $value) {
                $this->_id[$fieldName] = $value;
                $this->_data[$fieldName] = $value;
            }
        } else {
            $name = $this->_class->getSingleIdentifierFieldName();
            $this->_id[$name] = $id;
            $this->_data[$name] = $id;
        }
        $this->_modified = array();
    }

    /**
     * INTERNAL:
     * Returns the primary keys of the entity (key => value pairs).
     *
     * @return array
     */
    final public function _identifier()
    {
        return $this->_id;
    }

    /**
     * hasRefence
     * @param string $name
     * @return boolean
     * @todo Better name? hasAssociation() ? Remove?
     */
    final public function hasReference($name)
    {
        return isset($this->_references[$name]);
    }

    /**
     * obtainReference
     *
     * @param string $name
     * @throws Doctrine_Record_Exception        if trying to get an unknown related component
     * @todo Better name? Remove?
     */
    final public function obtainReference($name)
    {
        if (isset($this->_references[$name])) {
            return $this->_references[$name];
        }
        throw new Doctrine_Record_Exception("Unknown reference $name.");
    }

    /**
     * INTERNAL:
     * 
     * getReferences
     * @return array    all references
     */
    final public function _getReferences()
    {
        return $this->_references;
    }

    /**
     * INTERNAL:
     * setRelated
     *
     * @param string $alias
     * @param Doctrine_Access $coll
     * @todo Name? Remove?
     */
    final public function _setRelated($alias, Doctrine_Access $coll)
    {
        $this->_references[$alias] = $coll;
    }
    
    /**
     * Gets the ClassMetadata object that describes the entity class.
     * 
     * @return Doctrine::ORM::Mapping::ClassMetadata
     */
    final public function getClass()
    {
        return $this->_class;
    }
    
    /**
     * Gets the EntityManager that is responsible for the persistence of 
     * the entity.
     *
     * @return Doctrine::ORM::EntityManager
     */
    final public function getEntityManager()
    {
        return $this->_em;
    }
    
    /**
     * Gets the EntityRepository of the Entity.
     *
     * @return Doctrine::ORM::EntityRepository
     */
    final public function getRepository()
    {
        return $this->_em->getRepository($this->_entityName);
    }
    
    /**
     * @todo Why toString() and __toString() ?
     */
    public function toString()
    {
        return Doctrine::dump(get_object_vars($this));
    }

    /**
     * returns a string representation of this object
     * @todo Why toString() and __toString() ?
     */
    public function __toString()
    {
        return (string)$this->_oid;
    }
    
    /**
     * Helps freeing the memory occupied by the entity.
     * Cuts all references the entity has to other entities and removes the entity
     * from the instance pool.
     * Note: The entity is no longer useable after free() has been called. Any operations
     * done with the entity afterwards can lead to unpredictable results.
     */
    public function free($deep = false)
    {
        if ($this->_state != self::STATE_LOCKED) {
            $this->_em->detach($this);
            $this->_data = array();
            $this->_id = array();

            if ($deep) {
                foreach ($this->_references as $name => $reference) {
                    if ( ! ($reference instanceof Doctrine_Null)) {
                        $reference->free($deep);
                    }
                }
            }

            $this->_references = array();
        }
    }
}