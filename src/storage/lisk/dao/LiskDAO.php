<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Simple object-authoritative data access object that makes it easy to build
 * stuff that you need to save to a database. Basically, it means that the
 * amount of boilerplate code (and, particularly, boilerplate SQL) you need
 * to write is greatly reduced.
 *
 * Lisk makes it fairly easy to build something quickly and end up with
 * reasonably high-quality code when you're done (e.g., getters and setters,
 * objects, transactions, reasonably structured OO code). It's also very thin:
 * you can break past it and use MySQL and other lower-level tools when you
 * need to in those couple of cases where it doesn't handle your workflow
 * gracefully.
 *
 * However, Lisk won't scale past one database and lacks many of the features
 * of modern DAOs like Hibernate: for instance, it does not support joins or
 * polymorphic storage.
 *
 * This means that Lisk is well-suited for tools like Differential, but often a
 * poor choice elsewhere. And it is strictly unsuitable for many projects.
 *
 * Lisk's model is object-authoritative: the PHP class definition is the
 * master authority for what the object looks like.
 *
 * =Building New Objects=
 *
 * To create new Lisk objects, extend @{class:LiskDAO} and implement
 * @{method:establishConnection}. It should return an AphrontDatabaseConnection;
 * this will tell Lisk where to save your objects.
 *
 *   class Dog extends LiskDAO {
 *
 *     protected $name;
 *     protected $breed;
 *
 *     public function establishConnection() {
 *       return $some_connection_object;
 *     }
 *   }
 *
 * Now, you should create your table:
 *
 *   CREATE TABLE dog (
 *     id int unsigned not null auto_increment primary key,
 *     name varchar(32) not null,
 *     breed varchar(32) not null,
 *     dateCreated int unsigned not null,
 *     dateModified int unsigned not null
 *   );
 *
 * For each property in your class, add a column with the same name to the
 * table (see getConfiguration() for information about changing this mapping).
 * Additionally, you should create the three columns `id`,  `dateCreated` and
 * `dateModified`. Lisk will automatically manage these, using them to implement
 * autoincrement IDs and timestamps. If you do not want to use these features,
 * see getConfiguration() for information on disabling them. At a bare minimum,
 * you must normally have an `id` column which is a primary or unique key with a
 * numeric type, although you can change its name by overriding getIDKey() or
 * disable it entirely by overriding getIDKey() to return null. Note that many
 * methods rely on a single-part primary key and will no longer work (they will
 * throw) if you disable it.
 *
 * As you add more properties to your class in the future, remember to add them
 * to the database table as well.
 *
 * Lisk will now automatically handle these operations: getting and setting
 * properties, saving objects, loading individual objects, loading groups
 * of objects, updating objects, managing IDs, updating timestamps whenever
 * an object is created or modified, and some additional specialized
 * operations.
 *
 * = Creating, Retrieving, Updating, and Deleting =
 *
 * To create and persist a Lisk object, use save():
 *
 *   $dog = id(new Dog())
 *     ->setName('Sawyer')
 *     ->setBreed('Pug')
 *     ->save();
 *
 * Note that **Lisk automatically builds getters and setters for all of your
 * object's properties** via __call(). You can override these by defining
 * versions yourself.
 *
 * Calling save() will persist the object to the database. After calling
 * save(), you can call getID() to retrieve the object's ID.
 *
 * To load objects by ID, use the load() method:
 *
 *   $dog = id(new Dog())->load($id);
 *
 * This will load the Dog record with ID $id into $dog, or ##null## if no such
 * record exists (load() is an instance method rather than a static method
 * because PHP does not support late static binding, at least until PHP 5.3).
 *
 * To update an object, change its properties and save it:
 *
 *   $dog->setBreed('Lab')->save();
 *
 * To delete an object, call delete():
 *
 *   $dog->delete();
 *
 * That's Lisk CRUD in a nutshell.
 *
 * = Queries =
 *
 * Often, you want to load a bunch of objects, or execute a more specialized
 * query. Use loadAllWhere() or loadOneWhere() to do this:
 *
 *   $pugs = $dog->loadAllWhere('breed = %s', 'Pug');
 *   $sawyer = $dog->loadOneWhere('name = %s', 'Sawyer');
 *
 * These methods work like @{function:queryfx}, but only take half of a query
 * (the part after the WHERE keyword). Lisk will handle the connection, columns,
 * and object construction; you are responsible for the rest of it.
 * loadAllWhere() returns a list of objects, while loadOneWhere() returns a
 * single object (or null).
 *
 * @task   config  Configuring Lisk
 * @task   load    Loading Objects
 * @task   info    Examining Objects
 * @task   save    Writing Objects
 * @task   hook    Hooks and Callbacks
 * @task   util    Utilities
 *
 * @group storage
 */
abstract class LiskDAO {

  const CONFIG_OPTIMISTIC_LOCKS     = 'enable-locks';
  const CONFIG_IDS                  = 'id-mechanism';
  const CONFIG_TIMESTAMPS           = 'timestamps';
  const CONFIG_AUX_PHID             = 'auxiliary-phid';
  const CONFIG_SERIALIZATION        = 'col-serialization';

  const SERIALIZATION_NONE          = 'id';
  const SERIALIZATION_JSON          = 'json';
  const SERIALIZATION_PHP           = 'php';

  const IDS_AUTOINCREMENT           = 'ids-auto';
  const IDS_PHID                    = 'ids-phid';
  const IDS_MANUAL                  = 'ids-manual';

  /**
   *  Build an empty object.
   *
   *  @return obj Empty object.
   */
  public function __construct() {
    $id_key = $this->getIDKey();
    if ($id_key) {
      $this->$id_key = null;
    }
  }

  abstract protected function establishConnection($mode);


/* -(  Configuring Lisk  )--------------------------------------------------- */


  /**
   * Change Lisk behaviors, like optimistic locks and timestamps. If you want
   * to change these behaviors, you should override this method in your child
   * class and change the options you're interested in. For example:
   *
   *   public function getConfiguration() {
   *     return array(
   *       Lisk_DataAccessObject::CONFIG_EXAMPLE => true,
   *     ) + parent::getConfiguration();
   *   }
   *
   * The available options are:
   *
   * CONFIG_OPTIMISTIC_LOCKS
   * Lisk automatically performs optimistic locking on objects, which protects
   * you from read-modify-write concurrency problems. Lock failures are
   * detected at write time and arise when two users read an object, then both
   * save it. In theory, you should detect these failures and accommodate them
   * in some sensible way (for instance, by showing the user differences
   * between the original record and the copy they are trying to update, and
   * prompting them to merge them). In practice, most Lisk tools are quick
   * and dirty and don't get to that level of sophistication, but optimistic
   * locks can still protect you from yourself sometimes. If you don't want
   * to use optimistic locks, you can disable them. The performance cost of
   * doing this locking is very very small (optimistic locks were chosen
   * because they're simple and cheap, and highly optimized for the case where
   * collisions are rare). By default, this option is OFF.
   *
   * CONFIG_IDS
   * Lisk objects need to have a unique identifying ID. The three mechanisms
   * available for generating this ID are IDS_AUTOINCREMENT (default, assumes
   * the ID column is an autoincrement primary key), IDS_PHID (to generate a
   * unique PHID for each object) or IDS_MANUAL (you are taking full
   * responsibility for ID management).
   *
   * CONFIG_TIMESTAMPS
   * Lisk can automatically handle keeping track of a `dateCreated' and
   * `dateModified' column, which it will update when it creates or modifies
   * an object. If you don't want to do this, you may disable this option.
   * By default, this option is ON.
   *
   * CONFIG_AUX_PHID
   * This option can be enabled by being set to some truthy value. The meaning
   * of this value is defined by your PHID generation mechanism. If this option
   * is enabled, a `phid' property will be populated with a unique PHID when an
   * object is created (or if it is saved and does not currently have one). You
   * need to override generatePHID() and hook it into your PHID generation
   * mechanism for this to work. By default, this option is OFF.
   *
   * CONFIG_SERIALIZATION
   * You can optionally provide a column serialization map that will be applied
   * to values when they are written to the database. For example:
   *
   *   self::CONFIG_SERIALIZATION => array(
   *     'complex' => self::SERIALIZATION_JSON,
   *   )
   *
   * This will cause Lisk to JSON-serialize the 'complex' field before it is
   * written, and unserialize it when it is read.
   *
   *
   * @return dictionary  Map of configuration options to values.
   *
   * @task   config
   */
  protected function getConfiguration() {
    return array(
      self::CONFIG_OPTIMISTIC_LOCKS         => false,
      self::CONFIG_IDS                      => self::IDS_AUTOINCREMENT,
      self::CONFIG_TIMESTAMPS               => true,
    );
  }


  /**
   *  Determine the setting of a configuration option for this class of objects.
   *
   *  @param  const       Option name, one of the CONFIG_* constants.
   *  @return mixed       Option value, if configured (null if unavailable).
   *
   *  @task   config
   */
  public function getConfigOption($option_name) {
    static $options = null;

    if (!isset($options)) {
      $options = $this->getConfiguration();
    }

    return idx($options, $option_name);
  }


/* -(  Loading Objects  )---------------------------------------------------- */


  /**
   * Load an object by ID. You need to invoke this as an instance method, not
   * a class method, because PHP doesn't have late static binding (until
   * PHP 5.3.0). For example:
   *
   *   $dog = id(new Dog())->load($dog_id);
   *
   * @param  int       Numeric ID identifying the object to load.
   * @return obj|null  Identified object, or null if it does not exist.
   *
   * @task   load
   */
  public function load($id) {
    if (!($id = (int)$id)) {
      throw new Exception("Bogus ID provided to load().");
    }

    return $this->loadOneWhere(
      '%C = %d',
      $this->getIDKeyForUse(),
      $id);
  }


  /**
   * Loads all of the objects, unconditionally.
   *
   * @return dict    Dictionary of all persisted objects of this type, keyed
   *                 on object ID.
   *
   * @task   load
   */
  public function loadAll() {
    return $this->loadAllWhere('1 = 1');
  }


  /**
   * Load all objects which match a WHERE clause. You provide everything after
   * the 'WHERE'; Lisk handles everything up to it. For example:
   *
   *   $old_dogs = id(new Dog())->loadAllWhere('age > %d', 7);
   *
   * The pattern and arguments are as per queryfx().
   *
   * @param  string  queryfx()-style SQL WHERE clause.
   * @param  ...     Zero or more conversions.
   * @return dict    Dictionary of matching objects, keyed on ID.
   *
   * @task   load
   */
  public function loadAllWhere($pattern/*, $arg, $arg, $arg ... */) {
    $args = func_get_args();
    $data = call_user_func_array(
      array($this, 'loadRawDataWhere'),
      $args);
    return $this->loadAllFromArray($data);
  }


  /**
   * Load a single object identified by a 'WHERE' clause. You provide
   * everything  after the 'WHERE', and Lisk builds the first half of the
   * query. See loadAllWhere(). This method is similar, but returns a single
   * result instead of a list.
   *
   * @param  string    queryfx()-style SQL WHERE clause.
   * @param  ...       Zero or more conversions.
   * @return obj|null  Matching object, or null if no object matches.
   *
   * @task   load
   */
  public function loadOneWhere($pattern/*, $arg, $arg, $arg ... */) {
    $args = func_get_args();
    $data = call_user_func_array(
      array($this, 'loadRawDataWhere'),
      $args);

    if (count($data) > 1) {
      throw new AphrontQueryCountException(
        "More than 1 result from loadOneWhere()!");
    }

    $data = reset($data);
    if (!$data) {
      return null;
    }

    return $this->loadFromArray($data);
  }


  protected function loadRawDataWhere($pattern/*, $arg, $arg, $arg ... */) {
    $connection = $this->getConnection('r');

    $lock_clause = '';
    if ($connection->isReadLocking()) {
      $lock_clause = 'FOR UPDATE';
    } else if ($connection->isWriteLocking()) {
      $lock_clause = 'LOCK IN SHARE MODE';
    }

    $args = func_get_args();
    $args = array_slice($args, 1);

    $pattern = 'SELECT * FROM %T WHERE '.$pattern.' %Q';
    array_unshift($args, $this->getTableName());
    array_push($args, $lock_clause);
    array_unshift($args, $pattern);

    return call_user_func_array(
      array($connection, 'queryData'),
      $args);
  }


  /**
   * Reload an object from the database, discarding any changes to persistent
   * properties. If the object uses optimistic locks and you are in a locking
   * mode while transactional, this will effectively synchronize the locks.
   * This is pretty heady. It is unlikely you need to use this method.
   *
   * @return this
   *
   * @task   load
   */
  public function reload() {

    if (!$this->getID()) {
      throw new Exception("Unable to reload object that hasn't been loaded!");
    }

    $use_locks = $this->getConfigOption(self::CONFIG_OPTIMISTIC_LOCKS);

    if (!$use_locks) {
      $result = $this->loadOneWhere(
        '%C = %d',
        $this->getIDKeyForUse(),
        $this->getID());
    } else {
      $result = $this->loadOneWhere(
        '%C = %d AND %C = %d',
        $this->getIDKeyForUse(),
        $this->getID(),
        'version',
        $this->getVersion());
    }

    if (!$result) {
      throw new AphrontQueryObjectMissingException($use_locks);
    }

    return $this;
  }


  /**
   * Initialize this object's properties from a dictionary. Generally, you
   * load single objects with loadOneWhere(), but sometimes it may be more
   * convenient to pull data from elsewhere directly (e.g., a complicated
   * join via queryData()) and then load from an array representation.
   *
   * @param  dict  Dictionary of properties, which should be equivalent to
   *               selecting a row from the table or calling getProperties().
   * @return this
   *
   * @task   load
   */
  public function loadFromArray(array $row) {
    $map = array();
    foreach ($row as $k => $v) {
      $map[$k] = $v;
    }

    $this->willReadData($map);

    foreach ($map as $prop => $value) {
      $this->$prop = $value;
    }

    $this->didReadData();

    return $this;
  }


  /**
   * Initialize a list of objects from a list of dictionaries. Usually you
   * load lists of objects with loadAllWhere(), but sometimes that isn't
   * flexible enough. One case is if you need to do joins to select the right
   * objects:
   *
   *   function loadAllWithOwner($owner) {
   *     $data = $this->queryData(
   *       'SELECT d.*
   *         FROM owner o
   *           JOIN owner_has_dog od ON o.id = od.ownerID
   *           JOIN dog d ON od.dogID = d.id
   *         WHERE o.id = %d',
   *       $owner);
   *     return $this->loadAllFromArray($data);
   *   }
   *
   * This is a lot messier than loadAllWhere(), but more flexible.
   *
   * @param  list  List of property dictionaries.
   * @return dict  List of constructed objects, keyed on ID.
   *
   * @task   load
   */
  public function loadAllFromArray(array $rows) {
    $result = array();

    $id_key = $this->getIDKey();

    foreach ($rows as $row) {
      $obj = clone $this;
      if ($id_key) {
        $result[$row[$id_key]] = $obj->loadFromArray($row);
      } else {
        $result[] = $obj->loadFromArray($row);
      }
    }

    return $result;
  }


/* -(  Examining Objects  )-------------------------------------------------- */


  /**
   * Retrieve the unique, numerical ID identifying this object. This value
   * will be null if the object hasn't been persisted.
   *
   * @return int   Unique numerical ID.
   *
   * @task   info
   */
  public function getID() {
    $id_key = $this->getIDKeyForUse();
    return $this->$id_key;
  }


  /**
   * Retrieve a list of all object properties. Note that some may be
   * "transient", which means they should not be persisted to the database.
   * Transient properties can be identified by calling
   * getTransientProperties().
   *
   * @return dict  Dictionary of normalized (lowercase) to canonical (original
   *               case) property names.
   *
   * @task   info
   */
  protected function getProperties() {
    static $properties = null;
    if (!isset($properties)) {
      $class = new ReflectionClass(get_class($this));
      $properties = array();
      foreach ($class->getProperties() as $p) {
        $properties[strtolower($p->getName())] = $p->getName();
      }

      $id_key = $this->getIDKey();
      if ($id_key) {
        if (!isset($properties[strtolower($id_key)])) {
          $properties[strtolower($id_key)] = $id_key;
        }
      }

      if ($this->getConfigOption(self::CONFIG_OPTIMISTIC_LOCKS)) {
        $properties['version'] = 'version';
      }

      if ($this->getConfigOption(self::CONFIG_TIMESTAMPS)) {
        $properties['datecreated'] = 'dateCreated';
        $properties['datemodified'] = 'dateModified';
      }

      if (!$this->isPHIDPrimaryID() &&
          $this->getConfigOption(self::CONFIG_AUX_PHID)) {
        $properties['phid'] = 'phid';
      }
    }
    return $properties;
  }


  /**
   * Check if a property exists on this object.
   *
   * @return string|null   Canonical property name, or null if the property
   *                       does not exist.
   *
   * @task   info
   */
  protected function checkProperty($property) {
    static $properties = null;
    if (!isset($properties)) {
      $properties = $this->getProperties();
    }

    return idx($properties, strtolower($property));
  }


  /**
   * Get or build the database connection for this object.
   *
   * @return LiskDatabaseConnection   Lisk connection object.
   *
   * @task   info
   */
  protected function getConnection($mode) {
    if ($mode != 'r' && $mode != 'w') {
      throw new Exception("Unknown mode '{$mode}', should be 'r' or 'w'.");
    }

    // TODO: We don't do anything with the read/write mode right now, but
    // should.

    if (!isset($this->__connection)) {
      $this->__connection = $this->establishConnection($mode);
    }

    return $this->__connection;
  }


  /**
   * Convert this object into a property dictionary. This dictionary can be
   * restored into an object by using loadFromArray() (unless you're using
   * legacy features with CONFIG_CONVERT_CAMELCASE, but in that case you should
   * just go ahead and die in a fire).
   *
   * @return dict  Dictionary of object properties.
   *
   * @task   info
   */
  protected function getPropertyValues() {
    $map = array();
    foreach ($this->getProperties() as $p) {
      // We may receive a warning here for properties we've implicitly added
      // through configuration; squelch it.
      $map[$p] = @$this->$p;
    }
    return $map;
  }


  /**
   * Convert this object into a property dictionary containing only properties
   * which will be persisted to the database.
   *
   * @return dict  Dictionary of persistent object properties.
   *
   * @task   info
   */
  protected function getPersistentPropertyValues() {
    $map = $this->getPropertyValues();
    foreach ($this->getTransientProperties() as $p) {
      unset($map[$p]);
    }
    return $map;
  }


/* -(  Writing Objects  )---------------------------------------------------- */


  /**
   * Persist this object to the database. In most cases, this is the only
   * method you need to call to do writes. If the object has not yet been
   * inserted this will do an insert; if it has, it will do an update.
   *
   * @return this
   *
   * @task   save
   */
  public function save() {
    if ($this->shouldInsertWhenSaved()) {
      return $this->insert();
    } else {
      return $this->update();
    }
  }


  /**
   * Save this object, forcing the query to use REPLACE regardless of object
   * state.
   *
   * @return this
   *
   * @task   save
   */
  public function replace() {
    return $this->insertRecordIntoDatabase('REPLACE');
  }


  /**
   *  Save this object, forcing the query to use INSERT regardless of object
   *  state.
   *
   *  @return this
   *
   *  @task   save
   */
  public function insert() {
    return $this->insertRecordIntoDatabase('INSERT');
  }


  /**
   *  Save this object, forcing the query to use UPDATE regardless of object
   *  state.
   *
   *  @return this
   *
   *  @task   save
   */
  public function update() {
    $use_locks = $this->getConfigOption(self::CONFIG_OPTIMISTIC_LOCKS);

    $this->willSaveObject();
    $data = $this->getPersistentPropertyValues();
    $this->willWriteData($data);

    $map = array();
    foreach ($data as $k => $v) {
      if ($use_locks && $k == 'version') {
        continue;
      }
      $map[$k] = $v;
    }

    $conn = $this->getConnection('w');

    foreach ($map as $key => $value) {
      $map[$key] = qsprintf($conn, '%C = %ns', $key, $value);
    }
    $map = implode(', ', $map);

    if ($use_locks) {
      $conn->query(
        'UPDATE %T SET %Q, version = version + 1 WHERE %C = %d AND %C = %d',
        $this->getTableName(),
        $map,
        $this->getIDKeyForUse(),
        $this->getID(),
        'version',
        $this->getVersion());
    } else {
      $conn->query(
        'UPDATE %T SET %Q WHERE %C = %d',
        $this->getTableName(),
        $map,
        $this->getIDKeyForUse(),
        $this->getID());
    }

    if ($conn->getAffectedRows() !== 1) {
      throw new AphrontQueryObjectMissingException($use_locks);
    }

    if ($use_locks) {
      $this->setVersion($this->getVersion() + 1);
    }

    $this->didWriteData();

    return $this;
  }


  /**
   * Delete this object, permanently.
   *
   * @return this
   *
   * @task   save
   */
  public function delete() {
    $this->willDelete();

    $conn = $this->getConnection('w');
    $conn->query(
      'DELETE FROM %T WHERE %C = %d',
      $this->getTableName(),
      $this->getIDKeyForUse(),
      $this->getID());

    $this->didDelete();

    return $this;
  }


  /**
   * Internal implementation of INSERT and REPLACE.
   *
   * @param  const   Either "INSERT" or "REPLACE", to force the desired mode.
   *
   * @task   save
   */
  protected function insertRecordIntoDatabase($mode) {
    $this->willSaveObject();
    $data = $this->getPersistentPropertyValues();

    $id_mechanism = $this->getConfigOption(self::CONFIG_IDS);
    switch ($id_mechanism) {
      //  If we are using autoincrement IDs, let MySQL assign the value for the
      //  ID column.
      case self::IDS_AUTOINCREMENT:
        unset($data[$this->getIDKeyForUse()]);
        break;
      case self::IDS_PHID:
        if (empty($data[$this->getIDKeyForUse()])) {
          $phid = $this->generatePHID();
          $this->setID($phid);
          $data[$this->getIDKeyForUse()] = $phid;
        }
        break;
      case self::IDS_MANUAL:
        break;
      default:
        throw new Exception('Unknown CONFIG_IDs mechanism!');
    }

    if ($this->getConfigOption(self::CONFIG_OPTIMISTIC_LOCKS)) {
      $data['version'] = 0;
    }

    $this->willWriteData($data);

    $columns = array_keys($data);
    foreach ($columns as $k => $property) {
      $columns[$k] = $property;
    }

    $conn = $this->getConnection('w');

    $conn->query(
      '%Q INTO %T (%LC) VALUES (%Ls)',
      $mode,
      $this->getTableName(),
      $columns,
      $data);

    // Update the object with the initial Version value
    if ($this->getConfigOption(self::CONFIG_OPTIMISTIC_LOCKS)) {
      $this->setVersion(0);
    }

    // Only use the insert id if this table is using auto-increment ids
    if ($id_mechanism === self::IDS_AUTOINCREMENT) {
      $this->setID($conn->getInsertID());
    }

    $this->didWriteData();

    return $this;
  }


  /**
   * Method used to determine whether to insert or update when saving.
   *
   * @return bool true if the record should be inserted
   */
  protected function shouldInsertWhenSaved() {
    $key_type = $this->getConfigOption(self::CONFIG_IDS);
    $use_locks = $this->getConfigOption(self::CONFIG_OPTIMISTIC_LOCKS);

    if ($key_type == self::IDS_MANUAL) {
      if ($use_locks) {
        // If we are manually keyed and the object has a version (which means
        // that it has been saved to the DB before), do an update, otherwise
        // perform an insert.
        if ($this->getID() && $this->getVersion() !== null) {
          return false;
        } else {
          return true;
        }
      } else {
        throw new Exception(
          'You are not using optimistic locks, but are using manual IDs. You '.
          'must override the shouldInsertWhenSaved() method to properly '.
          'detect when to insert a new record.');
      }
    } else {
      return !$this->getID();
    }
  }


/* -(  Hooks and Callbacks  )------------------------------------------------ */


  /**
   * Retrieve the database table name. By default, this is the class name.
   *
   * @return string  Table name for object storage.
   *
   * @task   hook
   */
  public function getTableName() {
    return get_class($this);
  }


  /**
   * Helper: Whether this class is configured to use PHIDs as the primary ID.
   * @task internal
   */
  private function isPHIDPrimaryID() {
    return ($this->getConfigOption(self::CONFIG_IDS) === self::IDS_PHID);
  }


  /**
   * Retrieve the primary key column, "id" by default. If you can not
   * reasonably name your ID column "id", override this method.
   *
   * @return string  Name of the ID column.
   *
   * @task   hook
   */
  public function getIDKey() {
    return
      $this->isPHIDPrimaryID() ?
      'phid' :
      'id';
  }


  protected function getIDKeyForUse() {
    $id_key = $this->getIDKey();
    if (!$id_key) {
      throw new Exception(
        "This DAO does not have a single-part primary key. The method you ".
        "called requires a single-part primary key.");
    }
    return $id_key;
  }


  /**
   * Generate a new PHID, used by CONFIG_AUX_PHID and IDS_PHID.
   *
   * @return phid    Unique, newly allocated PHID.
   *
   * @task   hook
   */
  protected function generatePHID() {
    throw new Exception(
      "To use CONFIG_AUX_PHID or IDS_PHID, you need to overload ".
      "generatePHID() to perform PHID generation.");
  }


  /**
   * If your object has properties which you don't want to be persisted to the
   * database, you can override this method and specify them.
   *
   * @return list    List of properties which should NOT be persisted.
   *                 Property names should be in normalized (lowercase) form.
   *                 By default, all properties are persistent.
   *
   * @task   hook
   */
  protected function getTransientProperties() {
    return array();
  }


  /**
   * Hook to apply serialization or validation to data before it is written to
   * the database. See also willReadData().
   *
   * @task hook
   */
  protected function willWriteData(array &$data) {
    $this->applyLiskDataSerialization($data, false);
  }


  /**
   * Hook to perform actions after data has been written to the database.
   *
   * @task hook
   */
  protected function didWriteData() {}


  /**
   * Hook to make internal object state changes prior to INSERT, REPLACE or
   * UPDATE.
   *
   * @task hook
   */
  protected function willSaveObject() {
    $use_timestamps = $this->getConfigOption(self::CONFIG_TIMESTAMPS);

    if ($use_timestamps) {
      if (!$this->getDateCreated()) {
        $this->setDateCreated(time());
      }
      $this->setDateModified(time());
    }

    if (($this->isPHIDPrimaryID() && !$this->getID())) {
      // If PHIDs are the primary ID, the subclass could have overridden the
      // name of the ID column.
      $this->setID($this->generatePHID());
    } else if ($this->getConfigOption(self::CONFIG_AUX_PHID) &&
               !$this->getPHID()) {
      // The subclass could still want PHIDs.
      $this->setPHID($this->generatePHID());
    }
  }


  /**
   * Hook to apply serialization or validation to data as it is read from the
   * database. See also willWriteData().
   *
   * @task hook
   */
  protected function willReadData(array &$data) {
    $this->applyLiskDataSerialization($data, $deserialize = true);
  }

  /**
   * Hook to perform an action on data after it is read from the database.
   *
   * @task hook
   */
  protected function didReadData() {}

  /**
   * Hook to perform an action before the deletion of an object.
   *
   * @task hook
   */
  protected function willDelete() {}

  /**
   * Hook to perform an action after the deletion of an object.
   *
   * @task hook
   */
  protected function didDelete() {}

/* -(  Utilities  )---------------------------------------------------------- */


  /**
   * Applies configured serialization to a dictionary of values.
   *
   * @task util
   */
  protected function applyLiskDataSerialization(array &$data, $deserialize) {
    $serialization = $this->getConfigOption(self::CONFIG_SERIALIZATION);
    if ($serialization) {
      foreach (array_intersect_key($serialization, $data) as $col => $format) {
        switch ($format) {
          case self::SERIALIZATION_NONE:
            break;
          case self::SERIALIZATION_PHP:
            if ($deserialize) {
              $data[$col] = unserialize($data[$col]);
            } else {
              $data[$col] = serialize($data[$col]);
            }
            break;
          case self::SERIALIZATION_JSON:
            if ($deserialize) {
              $data[$col] = json_decode($data[$col], true);
            } else {
              $data[$col] = json_encode($data[$col]);
            }
            break;
          default:
            throw new Exception("Unknown serialization format '{$format}'.");
        }
      }
    }
  }


  /**
   * Black magic. Builds implied get*() and set*() for all properties.
   *
   * @param  string  Method name.
   * @param  list    Argument vector.
   * @return mixed   get*() methods return the property value. set*() methods
   *                 return $this.
   * @task   util
   */
  public function __call($method, $args) {
    if (!strncmp($method, 'get', 3)) {
      $property = substr($method, 3);
      if (!($property = $this->checkProperty($property))) {
        throw new Exception("Bad getter call: {$method}");
      }
      if (count($args) !== 0) {
        throw new Exception("Getter call should have zero args: {$method}");
      }
      return @$this->$property;
    }

    if (!strncmp($method, 'set', 3)) {
      $property = substr($method, 3);
      $property = $this->checkProperty($property);
      if (!$property) {
        throw new Exception("Bad setter call: {$method}");
      }
      if (count($args) !== 1) {
        throw new Exception("Setter should have exactly one arg: {$method}");
      }
      if ($property == 'ID') {
        $property = $this->getIDKeyForUse();
      }
      $this->$property = $args[0];
      return $this;
    }

    throw new Exception("Unable to resolve method: {$method}.");
  }
}