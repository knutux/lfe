<?php

abstract class MetaData
    {
    static $cache = array ();
    private $defaultProps = false;
    protected $dbtable;
    
    function __construct ()
        {
        $this->dbtable = new DBTable ();
        }

    private static function loadByName ($tableName, &$error)
        {
        if (preg_match ('#^([a-z][a-z_\-0-9]+)$#', $tableName) <= 0)
            {
            $error = "Invalid class name";
            return false;
            }

        $fileName = __DIR__."/tables/$tableName.php";
        if (!file_exists ($fileName))
            {
            $error = "Unrecognized class name";
            return false;
            }

        require_once ($fileName);
        $className = "{$tableName}_metadata";
        if (class_exists ($className))
            return new $className ();

        $className = "$tableName";
        if (class_exists ($className))
            return new $className ();
        
        $error = "Invalid class handler";
        return false;
        }

    // load class handler by looking for a file in /tables/ subfolder
    // (handler should inherit MetaData and contain all the metadata
    //  required for select/update/delete)
    public static function getByName ($tableName, &$error)
        {
        $error = NULL;
        $tableName = strtolower ($tableName);
        if (false === array_key_exists ($tableName, self::$cache))
            {
            self::$cache[$tableName] = self::loadByName ($tableName, $error);
            }

        if (empty (self::$cache[$tableName]))
            return false;

        return self::$cache[$tableName];
        }

    public abstract function getPropertyList ();
    public abstract function getDBTableName ();
    public abstract function getTableId ();
    protected abstract function enforceSelectSecurity ($request, $tableAlias, &$criteria, &$error, $verboseErrors = false);
    public function canEditInstance ($id, &$error, $verboseErrors = false)
        {
        $error = 'Not authorized';
        return false;
        }
    protected function getRelationsMetadata ()
        {
        return array ();
        }
    protected function validateBeforeSave ($id, $oldValues, $newValues, $prop, &$newVal)
        {
        if (!empty ($prop->pattern) && preg_match ("#^{$prop->pattern}#", $newVal) == 0)
            return $prop->name;
        return true;
        }

    public function getTableDisplayName ()
        {
        // default functionality
        $name = $this->getTableId ();
        return strtoupper(substr($name, 0, 1)).substr($name, 1);
        }

    public function getPublicMetadata ($includeRelations = true)
        {
        $meta = new StdClass ();
        $properties = $this->getPropertyList ();
        $meta->properties = array ();
        $meta->tableName = $this->getTableId ();
        $meta->displayName = $this->getTableDisplayName ();
        foreach ($properties as $prop)
            {
            $p = new StdClass ();
            $p->name = $prop->name;
            $p->label = $prop->label;
            $p->description = $prop->description;
            $p->readonly = !empty ($prop->readonly);
            $p->required = !empty ($prop->isRequired);

            if (!empty ($prop->pattern))
                $p->pattern = $prop->pattern;

            if ($prop->dbLength > 0)
                $p->length = $prop->dbLength;

            $p->purpose = "secondary";
            if(!empty ($prop->purpose))
                $p->purpose = $prop->purpose;
            else if ($prop->name == "id")
                $p->purpose = "id";
            else if ($prop->flags & self::FLAG_LABEL)
                $p->purpose = "label";
            else if ($prop->isDefault)
                $p->purpose = "primary";

            if (self::FLAG_RELATION & $prop->flags)
                {
                $p->name = array ("prop" => $prop->name, "id" => "{$prop->name}.id", "label" => "{$prop->name}.label");
                if (self::FLAGS_FOREIGN_KEY == (self::FLAGS_FOREIGN_KEY & $prop->flags))
                    {
                    $p->relatedTable = $prop->relatedTable;
                    }
                else
                    {
                    $p->relatedTable = "{$meta->tableName}::{$prop->name}";
                    $p->valueList = $this->getEnumValueList ($prop, $error);
                    }
                }

            $meta->properties[] = $p;
            }

        $meta->relations = $this->getRelationsMetadata ();
        foreach ($meta->relations as &$rel)
            {
            $relatedTable = MetaData::getByName ($rel->className, $err);
            if (!empty ($relatedTable))
                $rel->metadata = $relatedTable->getPublicMetadata (false);
            }

        return $meta;
        }

    protected function getEnumValueList ($prop, &$error, $verboseErrors = false)
        {
        $sql = <<<EOT
SELECT `{$prop->relatedIdColumn}`, `{$prop->relatedTextColumn}`
    FROM `{$prop->relatedTable}`
 ORDER BY `{$prop->relatedIdColumn}`
EOT;
        $rows = $this->dbtable->executeSelect ($prop->relatedTable, $sql, $error, $verboseErrors);
        if (false === $rows)
            return false;

        $result = array ();
        foreach ($rows as $row)
            {
            $r = new StdClass ();
            $r->id = $row[$prop->relatedIdColumn];
            $r->label = $row[$prop->relatedTextColumn];
            $result[] = $r;
            }

        return $result;
        }

    protected function preprocessQuery ($request, $id, $alias, &$joins, &$criteria, &$additional, &$error, $verboseErrors = false)
        {
        if (!empty ($id))
            {
            $alias = $this->getTableId ();
            $idColumn = $this->getIdColumn ();
            if (empty ($idColumn))
                $criteria[] = "1=0";
            else if (is_array ($id))
                {
                foreach ($id as $n)
                    {
                    if (!is_numeric ($n))
                        {
                        $criteria[] = "1=0";
                        return true;
                        }
                    }

                $criteria[] = "`$alias`.`$idColumn` IN (".implode(",", $id).")";
                }
            else if (!is_numeric ($id))
                $criteria[] = "1=0";
            else
                $criteria[] = "`$alias`.`$idColumn`=$id";
            }

        return true;
        }

    public function getIdColumn ()
        {
        foreach ($this->getPropertyList () as $prop)
            {
            if ("id" == $prop->name)
                return $prop->dbName;
            }

        return false;
        }

    public function getDefaultPropertySet ()
        {
        if (false === $this->defaultProps)
            {
            $this->defaultProps = array ();
            foreach ($this->getPropertyList () as $prop)
                {
                if ($prop->isDefault)
                    $this->defaultProps[] = $prop->name;
                }
            }

        return $this->defaultProps;
        }

    public function getLabelFields ()
        {
        $fields = array ();
        foreach ($this->getPropertyList () as $prop)
            {
            if ($prop->flags & self::FLAG_LABEL)
                $fields[$prop->name] = $prop->dbName;
            }

        return $fields;
        }

    public function createSelectStatement ($request, $id, $propertyNames, $pageNumber = false, $pageLength = 10, &$error = NULL, $verboseErrors = false)
        {
        $dbTableName = $this->getDBTableName ();
        $columnList = array ();
        $alias = $this->getTableId ();
        $criteria = $joins = array ();
        $additional = array (); // TODO: sort by, order by, having, etc

        foreach ($this->getPropertyList () as $prop)
            {
            if (true !== $propertyNames && !$prop->isDefault && (empty ($propertyNames) || false === array_search ($prop->name, $propertyNames)))
                continue;

            if (self::FLAGS_ENUM_ID == ($prop->flags & self::FLAGS_ENUM_ID))
                {
                $relatedAlias = "{$prop->name}_{$prop->relatedTable}";
                $joins[] = "LEFT OUTER JOIN `{$prop->relatedTable}` `{$relatedAlias}` ON `$alias`.`{$prop->dbName}`=`$relatedAlias`.`{$prop->relatedIdColumn}`";
                $columnList[] = "`$relatedAlias`.`{$prop->relatedIdColumn}` `{$prop->name}.id`";
                $columnList[] = "`$relatedAlias`.`{$prop->relatedTextColumn}` `{$prop->name}.label`";
                continue;
                }

            if (self::FLAGS_FOREIGN_KEY == ($prop->flags & self::FLAGS_FOREIGN_KEY))
                {
                $relatedTable = MetaData::getByName ($prop->relatedTable, $error);
                if (empty ($relatedTable))
                    {
                    if (empty ($error) || !$verboseErrors)
                        $error = "Class {$prop->relatedTable} not found";
                    return false;
                    }

                $relatedDBName = $relatedTable->getDBTableName ();
                $relatedIdColumn = $relatedTable->getIdColumn ();
                if (empty ($relatedIdColumn))
                    {
                    $error = "Class {$relatedTable} has no identifier field defined";
                    return false;
                    }
                    
                $relatedAlias = "{$prop->name}_{$prop->relatedTable}";
                $joins[] = "LEFT OUTER JOIN `{$relatedDBName}` `{$relatedAlias}` ON `$alias`.`{$prop->dbName}`=`$relatedAlias`.`{$relatedIdColumn}`";
                $columnList[] = "`$relatedAlias`.`{$relatedIdColumn}` `{$prop->name}.id`";
                $hasLabelColumn = false;
                $labelColumns = array ();
                foreach ($relatedTable->getLabelFields() as $propName => $colName)
                    {
                    $hasLabelColumn |= "label" == $propName;
                    $labelColumns[] = "`$relatedAlias`.`{$colName}`";
                    $columnList[] = "`$relatedAlias`.`{$colName}` `{$prop->name}.$propName`";
                    }
                if (!$hasLabelColumn)
                    {
                    $labelColumns = implode (", ", $labelColumns);
                    $columnList[] = "CONCAT_WS(' ', $labelColumns) `{$prop->name}.label`";
                    }
                continue;
                }

            if (preg_match ('#^([a-z_]+)\.([^`]+)$#', $prop->dbName, $m) > 0)
                {
                $columnList[] = "`{$m[1]}`.`{$m[2]}` `{$prop->name}`";
                }
            else
                $columnList[] = "`$alias`.`{$prop->dbName}` `{$prop->name}`";
            }

        /* TODO: check if there are related table columns
            (for example, "company.name" for service), add required
            joins and look for properties in related table metadata
        */

        $columnList = implode (", ", $columnList);
        
        // convert filters (id, text) to SQL criteria
        if (false === $this->preprocessQuery ($request, $id, $alias, $joins, $criteria, $additional, $error, $verboseErrors))
            return false;

        // add criteria to enforce security
        if (false === $this->enforceSelectSecurity ($request, $alias, $criteria, $error, $verboseErrors))
            return false;
        
        if (empty ($criteria))
            $criteria = "1=0"; // enforceSelectSecurity must set at least one criteria (even if it is "1=1" for admins)
        else
            $criteria = implode ("\n AND\n  ", $criteria);

        if (!empty ($joins))
            $joins = implode ("  \n", $joins);
        else
            $joins = "";

        $limit = "";
        if ($pageNumber > 0)
            {
            $offset = ($pageNumber - 1) * $pageLength;
            $rowCount = $pageLength + 1; // add 1 to know if there are additional pages left (caller must be prepared for that)
            $limit = "LIMIT $offset, $rowCount";
            }
            
        $sql = <<<EOT
SELECT $columnList
  FROM `$dbTableName` `$alias`
  $joins
 WHERE
  $criteria
 $limit
EOT;
        return $sql;
        }

    public function createUpdateStatement ($id, $oldValues, $newValues, &$auditLog, &$error = NULL, $verboseErrors = false)
        {
        $auditLog = NULL;
        if (!$this->canEditInstance ($id, $error, $verboseErrors))
            return false;

        $propToColumn = array ();
        $readonlyProperties = array ();
        foreach ($this->getPropertyList () as $prop)
            {
            if (!empty ($prop->readonly))
                $readonlyProperties[$prop->name] = $prop->dbName;
            else
                $propToColumn[$prop->name] = $prop;
            }

        $dbTableName = $this->getDBTableName ();
        $criteria = array ("{$readonlyProperties['id']}='$id'");
        $set = array ();
        $auditChanges = array ();
        $validationErrors = array ();
        foreach ($newValues as $prop => $val)
            {
            $oldVal = empty ($oldValues[$prop]) && 0 !== $oldValues[$prop] ? NULL : $oldValues[$prop];
            if ($oldVal === $val || (NULL === $oldVal && '' === $val))
                continue; // user did not change the value, so no need to update

            if (empty ($propToColumn[$prop]))
                {
                if (!empty ($readonlyProperties[$prop]))
                    $error = "Property $prop is read-only.";
                else
                    $error = "Property $prop was not recognized";
                return false;
                }

            $propDef = $propToColumn[$prop];
            $err = $this->validateBeforeSave ($id, $oldValues, $newValues, $propDef, $val);
            if (true !== $err)
                $validationErrors[] = $err;

            $dbName = $propDef->dbName;
            if (NULL === $oldVal)
                $criteria[] = "(`$dbName` IS NULL OR `$dbName`='')";
            else
                $criteria[] = "`$dbName`='".$this->dbtable->escapeString ($oldVal)."'";
            $val = NULL === $val ? 'NULL' : $this->dbtable->escapeString ($val);
            $set[] = "`$dbName`='$val'";
            if (is_numeric ($oldVal) && is_numeric ($val))
                $auditChanges[] = "$dbName changed from $oldVal to $val";
            else
                $auditChanges[] = "$dbName changed from '$oldVal' to '$val'";
            }
            
        if (empty ($set))
            return true; // nothing changed

        if (!empty ($validationErrors))
            {
            $error = "Value of one or more properties is invalid (".implode (", ", $validationErrors).")";
            return false;
            }

        $auditLog = implode (", ", $auditChanges);
        $set = implode (", ", $set);
        $where = implode (' AND ', $criteria);
        return $this->createUpdateStatementFromParts ($set, $where);
        }

    protected function createUpdateStatementFromParts ($set, $where)
        {
        $sql = <<<EOT
   SET $set
 WHERE $where
EOT;
        return $sql;
        }

    private static $permissions = array ();
    protected function getPermissionList (&$error, $verboseErrors = false)
        {
        $userId = Authentication::getCurrentUserId();
        if (false == array_key_exists ($userId, self::$permissions))
            {
            // collect and cache permissions for current user
            $tableName = 'contact_permissions';
            $sql = <<<EOT
SELECT *
  FROM `$tableName`
 WHERE `contact_id`=$userId
EOT;
            $rows = $this->dbtable->executeSelect ($tableName, $sql, $error, $verboseErrors);
            if (false === $rows)
                $rows = array (false, $error);
            self::$permissions[$userId] = $rows;
            }

        $rows = self::$permissions[$userId];
        if (2 == count ($rows) && false === $rows[0])
            {
            $error = $rows[1];
            return false;
            }

        return $rows;
        }

    protected function getVisibleCompanyIds (&$error, $verboseErrors = false)
        {
        return $this->getCompanyIdsWithPermission (NULL, $error, $verboseErrors);
        }

    protected function getCompanyIdsWithPermission ($permissionName, &$error, $verboseErrors = false)
        {
        $userId = Authentication::getCurrentUserId();
        if (empty ($userId))
            {
            $error = "Not loggen in";
            return false;
            }

        $permissions = $this->getPermissionList ($error, $verboseErrors);
        if (false === $permissions)
            return false;

        $ids = array ();
        foreach ($permissions as $row)
            {
            if (!empty ($permissionName) && empty ($row[$permissionName]))
                continue;

            $ids[] = $row["company_id"];
            }

        return $ids;
        }

    const TYPE_INTEGER  = 0x0001;
    const TYPE_STRING   = 0x0002;
    const TYPE_DOUBLE   = 0x0004;
    const TYPE_BIGINT   = 0x0008;
    const TYPE_DATE     = 0x0010;

    const FLAG_DEFAULT  = 0x00010000;
    const FLAG_LABEL    = 0x00020000;
    const FLAG_RELATION = 0x00040000;
    const FLAGS_ENUM_ID = 0x000C0000; // self::FLAG_RELATION | 0x00080000;
    const FLAGS_FOREIGN_KEY = 0x00140000; // self::FLAG_RELATION | 0x00100000;
    const FLAG_REQUIRED = 0x00200000;

    /*
    Flags - one of the TYPE_* values and any combination of FLAG_* flags
    */
    protected function createProperty ($propertyName, $label, $description = false, $flags = self::TYPE_STRING, $columnName = false, $length = false)
        {
        $prop = new StdClass ();
        $prop->name = $propertyName;
        $prop->label = $label;
        $prop->dbName = empty ($columnName) ? $propertyName : $columnName;
        $prop->description = empty ($description) ? $propertyName : $description;
        $prop->dbLength = $length;
        $prop->flags = $flags;
        $prop->isDefault = 0 != ($flags & self::FLAG_DEFAULT);
        $prop->isRequired = 0 != ($flags & self::FLAG_REQUIRED);
        return $prop;
        }

    protected function createInstanceIDProperty ($columnName, $description = "Instance ID")
        {
        $prop = $this->createProperty ("id", "Id", $description, self::TYPE_INTEGER | self::FLAG_DEFAULT, $columnName);
        $prop->readonly = true;
        return $prop;
        }
    protected function createLabelProperty ($propertyName, $label = "Name", $description = "Instance name", $columnName = NULL, $length = 255)
        {
        return $this->createProperty ($propertyName, $label, $description, self::TYPE_STRING | self::FLAG_DEFAULT | self::FLAG_REQUIRED | self::FLAG_LABEL, $columnName, $length);
        }
    protected function createStringProperty ($propertyName, $label, $description, $columnName, $default = false, $length = 255)
        {
        return $this->createProperty ($propertyName, $label, $description, self::TYPE_STRING | ($default ? self::FLAG_DEFAULT : 0), $columnName, $length);
        }
    protected function createLongTextProperty ($propertyName, $label, $description, $columnName, $default = false)
        {
        return $this->createProperty ($propertyName, $label, $description, self::TYPE_STRING | ($default ? self::FLAG_DEFAULT : 0), $columnName);
        }
    protected function createBigintProperty ($propertyName, $label, $description, $columnName, $default = false)
        {
        return $this->createProperty ($propertyName, $label, $description, self::TYPE_BIGINT | ($default ? self::FLAG_DEFAULT : 0), $columnName);
        }
    protected function createIntegerProperty ($propertyName, $label, $description, $columnName, $default = false)
        {
        return $this->createProperty ($propertyName, $label, $description, self::TYPE_INTEGER | ($default ? self::FLAG_DEFAULT : 0), $columnName);
        }
    protected function createPriceProperty ($propertyName, $label, $description, $columnName, $default = false)
        {
        return $this->createProperty ($propertyName, $label, $description, self::TYPE_DOUBLE | ($default ? self::FLAG_DEFAULT : 0), $columnName);
        }
    protected function createDateProperty ($propertyName, $label, $description, $columnName, $default = false)
        {
        return $this->createProperty ($propertyName, $label, $description, self::TYPE_DATE | ($default ? self::FLAG_DEFAULT : 0), $columnName);
        }
    // property defined with foreign key
    protected function createJoinedProperty ($propertyName, $label, $description, $columnName, $relatedTable, $default = false)
        {
        $prop = $this->createProperty ($propertyName, $label, $description, self::FLAGS_FOREIGN_KEY | ($default ? self::FLAG_DEFAULT : 0), $columnName);
        $prop->relatedTable = $relatedTable;
        return $prop;
        }
    // property defined with foreign key (and related table has just id and text) 
    protected function createEnumProperty ($propertyName, $label, $description, $columnName, $relatedTable, $relatedIdColumn, $relatedTextColumn, $default = false)
        {
        $prop = $this->createProperty ($propertyName, $label, $description, self::FLAGS_ENUM_ID | ($default ? self::FLAG_DEFAULT : 0), $columnName);
        $prop->relatedTable = $relatedTable;
        $prop->relatedIdColumn = $relatedIdColumn;
        $prop->relatedTextColumn = $relatedTextColumn;
        return $prop;
        }
    }