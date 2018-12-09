<?php

class DAL {

    public static $_db; 

    protected static $_stmt = array(); 

    protected static $_identifier_quote_character; 

    private static $_tableColumns = array(); // columns populated dynamically

    public $data;

    public $dirty;

    protected static $_primary_column_name = 'id'; 

    protected static $_tableName = '_the_db_table_name_';

    public function __construct($data = array()) {
        static::getFieldnames();
        $this->clearDirtyFields();
        if (is_array($data)) {
            $this->hydrate($data);
        }
    }

    public function hasData() {
        return is_object($this->data);
    }

    public function dataPresent() {
        if (!$this->hasData()) {
            throw new \Exception('No data');
        }

        return true;
    }

    public function __set($name, $value) {
        if (!$this->hasData()) {
            $this->data = new \stdClass();
        }
        $this->data->$name = $value;
        $this->markFieldDirty($name);
    }

    public function markFieldDirty($name) {
        $this->dirty->$name = true; 
    }

    public function isFieldDirty($name) {
        return isset($this->dirty->$name) && ($this->dirty->$name == true);
    }

    public function clearDirtyFields() {
        $this->dirty = new \stdClass();
    }

    public function __get($name) {
        if (!$this->hasData()) {
            throw new \Exception("data property=$name has not been initialised", 1);
        }

        if (property_exists($this->data, $name)) {
            return $this->data->$name;
        }

        $trace = debug_backtrace();
        throw new \Exception(
            'Undefined property via __get(): '.$name.
            ' in '.$trace[0]['file'].
            ' on line '.$trace[0]['line'],
            1
        );
    }

    public function __isset($name) {
        if ($this->hasData() && property_exists($this->data, $name)) {
            return true;
        }

        return false;
    }

    public static function connectDb($dsn, $username, $password, $driverOptions = array()) {
        static::$_db = new \PDO($dsn, $username, $password, $driverOptions);
        static::$_db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION); 
        static::_setup_identifier_quote_character();
    }

    public static function _setup_identifier_quote_character() {
        if (is_null(static::$_identifier_quote_character)) {
            static::$_identifier_quote_character = static::_detect_identifier_quote_character();
        }
    }

    protected static function _detect_identifier_quote_character() {
        switch (static::getDriverName()) {
            case 'pgsql':
            case 'sqlsrv':
            case 'dblib':
            case 'mssql':
            case 'sybase':
                return '"';
            case 'mysql':
            case 'sqlite':
            case 'sqlite2':
            default:
                return '`';
        }
    }

    protected static function getDriverName() {
        if (!static::$_db) {
            throw new \Exception('No database connection setup');
        }
        return static::$_db->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    protected static function _quote_identifier($identifier) {
        $class = get_called_class();
        $parts = explode('.', $identifier);
        $parts = array_map(array(
            $class,
            '_quote_identifier_part'
        ), $parts);
        return join('.', $parts);
    }

    protected static function _quote_identifier_part($part) {
        if ($part === '*') {
            return $part;
        }
        return static::$_identifier_quote_character.$part.static::$_identifier_quote_character;
    }

    protected static function getFieldnames() {
        $class = get_called_class();
        if (!isset(self::$_tableColumns[$class])) {
            $st                          = static::execute('DESCRIBE '.static::_quote_identifier(static::$_tableName));
            self::$_tableColumns[$class] = $st->fetchAll(\PDO::FETCH_COLUMN);
        }
        return self::$_tableColumns[$class];
    }

    public function hydrate($data) {
        foreach (static::getFieldnames() as $fieldname) {
            if (isset($data[$fieldname])) {
                $this->$fieldname = $data[$fieldname];
            } else if (!isset($this->$fieldname)) { 
                $this->$fieldname = null;
            }
        }
    }

    public function clear() {
        foreach (static::getFieldnames() as $fieldname) {
            $this->$fieldname = null;
        }
        $this->clearDirtyFields();
    }

    public function __sleep() {
        return static::getFieldnames();
    }

    public function toArray() {
        $a = array();
        foreach (static::getFieldnames() as $fieldname) {
            $a[$fieldname] = $this->$fieldname;
        }
        return $a;
    }

    static public function getById($id) {
        return static::fetchOneWhere(static::_quote_identifier(static::$_primary_column_name).' = ?', array($id));
    }

    static public function first() {
        return static::fetchOneWhere('1=1 ORDER BY '.static::_quote_identifier(static::$_primary_column_name).' ASC');
    }

    static public function last() {
        return static::fetchOneWhere('1=1 ORDER BY '.static::_quote_identifier(static::$_primary_column_name).' DESC');
    }

    static public function find($id) {
        $find_by_method = 'find_by_'.(static::$_primary_column_name);
        static::$find_by_method($id);
    }

    static public function __callStatic($name, $arguments) {
        if (preg_match('/^find_by_/', $name) == 1) {
            $fieldname = substr($name, 8); // remove find by
            $match     = $arguments[0];
            return static::fetchAllWhereMatchingSingleField($fieldname, $match);
        } else if (preg_match('/^findOne_by_/', $name) == 1) {
            $fieldname = substr($name, 11); // remove findOne_by_
            $match     = $arguments[0];
            return static::fetchOneWhereMatchingSingleField($fieldname, $match, 'ASC');
        } else if (preg_match('/^first_by_/', $name) == 1) {
            $fieldname = substr($name, 9); // remove first_by_
            $match     = $arguments[0];
            return static::fetchOneWhereMatchingSingleField($fieldname, $match, 'ASC');
        } else if (preg_match('/^last_by_/', $name) == 1) {
            $fieldname = substr($name, 8); // remove last_by_
            $match     = $arguments[0];
            return static::fetchOneWhereMatchingSingleField($fieldname, $match, 'DESC');
        } else if (preg_match('/^count_by_/', $name) == 1) {
            $fieldname = substr($name, 9); // remove find by
            $match     = $arguments[0];
            if (is_array($match)) {
                return static::countAllWhere(static::_quote_identifier($fieldname).' IN ('.static::createInClausePlaceholders($match).')', $match);
            } else {
                return static::countAllWhere(static::_quote_identifier($fieldname).' = ?', array($match));
            }
        }
        throw new \Exception(__CLASS__.' not such static method['.$name.']');
    }

    public static function fetchOneWhereMatchingSingleField($fieldname, $match, $order) {
        if (is_array($match)) {
            return static::fetchOneWhere(static::_quote_identifier($fieldname).' IN ('.static::createInClausePlaceholders($match).') ORDER BY '.static::_quote_identifier($fieldname).' '.$order, $match);
        } else {
            return static::fetchOneWhere(static::_quote_identifier($fieldname).' = ? ORDER BY '.static::_quote_identifier($fieldname).' '.$order, array($match));
        }
    }

    public static function fetchAllWhereMatchingSingleField($fieldname, $match) {
        if (is_array($match)) {
            return static::fetchAllWhere(static::_quote_identifier($fieldname).' IN ('.static::createInClausePlaceholders($match).')', $match);
        } else {
            return static::fetchAllWhere(static::_quote_identifier($fieldname).' = ?', array($match));
        }
    }

    static public function createInClausePlaceholders($params) {
        return implode(',', array_fill(0, count($params), '?'));
    }

    static public function count() {
        $st = static::execute('SELECT COUNT(*) FROM '.static::_quote_identifier(static::$_tableName));
        return (int) $st->fetchColumn(0);
    }

    static public function countAllWhere($SQLfragment = '', $params = array()) {
        $SQLfragment = self::addWherePrefix($SQLfragment);
        $st          = static::execute('SELECT COUNT(*) FROM '.static::_quote_identifier(static::$_tableName).$SQLfragment, $params);
        return (int) $st->fetchColumn(0);
    }

    static protected function addWherePrefix($SQLfragment) {
        return $SQLfragment ? ' WHERE '.$SQLfragment : $SQLfragment;
    }

    static public function fetchWhere($SQLfragment = '', $params = array(), $limitOne = false) {
        $class       = get_called_class();
        $SQLfragment = self::addWherePrefix($SQLfragment);
        $st          = static::execute(
            'SELECT * FROM '.static::_quote_identifier(static::$_tableName).$SQLfragment.($limitOne ? ' LIMIT 1' : ''),
            $params
        );
        $st->setFetchMode(\PDO::FETCH_ASSOC);
        if ($limitOne) {
            return new $class($st->fetch());
        }
        $results = [];
        foreach ($st->fetch() as $row) {
            $results[] = new $class($row);
        }
        return $results;
    }

    static public function fetchAllWhere($SQLfragment = '', $params = array()) {
        return static::fetchWhere($SQLfragment, $params, false);
    }

    static public function fetchOneWhere($SQLfragment = '', $params = array()) {
        return static::fetchWhere($SQLfragment, $params, true);
    }

    static public function deleteById($id) {
        $st = static::execute(
            'DELETE FROM '.static::_quote_identifier(static::$_tableName).' WHERE '.static::_quote_identifier(static::$_primary_column_name).' = ? LIMIT 1',
            array($id)
        );
        return ($st->rowCount() == 1);
    }

    public function delete() {
        return self::deleteById($this->{static::$_primary_column_name} );
    }

    static public function deleteAllWhere($where, $params = array()) {
        $st = static::execute(
            'DELETE FROM '.static::_quote_identifier(static::$_tableName).' WHERE '.$where,
            $params
        );
        return $st;
    }

    static public function validate() {
        return true;
    }

    public function insert($autoTimestamp = true, $allowSetPrimaryKey = false) {
        $pk      = static::$_primary_column_name;
        $timeStr = gmdate('Y-m-d H:i:s');
        if ($autoTimestamp && in_array('created_at', static::getFieldnames())) {
            $this->created_at = $timeStr;
        }
        if ($autoTimestamp && in_array('updated_at', static::getFieldnames())) {
            $this->updated_at = $timeStr;
        }
        $this->validate();
        if ($allowSetPrimaryKey !== true) {
            $this->$pk = null; // ensure id is null
        }
        $set   = $this->setString(!$allowSetPrimaryKey);
        $query = 'INSERT INTO '.static::_quote_identifier(static::$_tableName).' SET '.$set['sql'];
        $st    = static::execute($query, $set['params']);
        if ($st->rowCount() == 1) {
            $this->{static::$_primary_column_name} = static::$_db->lastInsertId();
            $this->clearDirtyFields();
        }
        return ($st->rowCount() == 1);
    }

    public function update($autoTimestamp = true) {
        if ($autoTimestamp && in_array('updated_at', static::getFieldnames())) {
            $this->updated_at = gmdate('Y-m-d H:i:s');
        }
        $this->validate();
        $set             = $this->setString();
        $query           = 'UPDATE '.static::_quote_identifier(static::$_tableName).' SET '.$set['sql'].' WHERE '.static::_quote_identifier(static::$_primary_column_name).' = ? LIMIT 1';
        $set['params'][] = $this->{static::$_primary_column_name};
        $st              = static::execute(
            $query,
            $set['params']
        );
        if ($st->rowCount() == 1) {
            $this->clearDirtyFields();
        }
        return ($st->rowCount() == 1);
    }

    public static function execute($query, $params = array()) {
        $st = static::_prepare($query);
        $st->execute($params);
        return $st;
    }

    protected static function _prepare($query) {
        if (!isset(static::$_stmt[$query])) {
            // cache prepared query if not seen before
            static::$_stmt[$query] = static::$_db->prepare($query);
        }
        return static::$_stmt[$query]; // return cache copy
    }

    public function save() {
        if ($this->{static::$_primary_column_name}) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }

    protected function setString($ignorePrimary = true) {

        $fragments = array();

        $params = [];
        foreach (static::getFieldnames() as $field) {
            if ($ignorePrimary && $field == static::$_primary_column_name) {
                continue;
            }
            if (isset($this->$field) && $this->isFieldDirty($field)) { 
                if ($this->$field === null) {
                    $fragments[] = static::_quote_identifier($field).' = NULL';
                } else {
                    $fragments[] = static::_quote_identifier($field).' = ?';
                    $params[]    = $this->$field;
                }
            }
        }
        $sqlFragment = implode(", ", $fragments);
        return [
            'sql'    => $sqlFragment,
            'params' => $params
        ];
    }

    public static function datetimeToMysqldatetime($dt) {
        $dt = (is_string($dt)) ? strtotime($dt) : $dt;
        return date('Y-m-d H:i:s', $dt);
    }
}

?>
