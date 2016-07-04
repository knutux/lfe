<?php
/**********************************************************
*
* Class:   DBTable
*
* This class contains common functionality (audit log, etc.)
* and shared methods (search functions for autocomplete and similar)
*
***********************************************************/

class DBTable
    {
    protected $db;
    const TABLE_AUDIT = 'audit_log';

    public function __construct ()
        {
        $this->db = Database::Instance ($this);
        }

    public function escapeString ($str)
        {
        return $this->db->escapeString ($str);
        }

    // generic function for all tables (taking field names from specific table class)
    public function executeSelect ($tableName, $sql, &$error, $verboseErrors = false)
        {
        $rows = $this->db->executeSelect ($tableName, $sql, true);
        if (false === $rows)
            {
            if ($verboseErrors)
                $error = $this->db->getLastError();
            else
                $error = "Internal error";
            return false;
            }

        return $rows;
        }

    public static function listEnumValues ($tableName, $property, $request, &$nextPage, &$metadata, &$error, $verboseErrors = false)
        {
        $error = "Not implemented - extracting value list for property $property in the class {$tableName}";
        return false;
        }

    // generic function for all tables (taking field names from specific table class)
    public static function listInstances ($tableName, $request, &$nextPage, &$metadata, &$error, $verboseErrors = false)
        {
        if (preg_match ('#^(.+)::(.+)$#', $tableName, $m) > 0)
            return self::listEnumValues ($m[1], $m[2], $request, $nextPage, $metadata, $error, $verboseErrors);

        $table = MetaData::getByName ($tableName, $error);
        if (empty ($table))
            {
            if (empty ($error) || !$verboseErrors)
                $error = "Class {$tableName} not found";
            return false;
            }

        $properties = empty ($request["props"]) ? NULL : $request["props"];
        $page = empty ($request["page"]) ? 1 : $request["page"];
        $pageLength = empty ($request["max"]) ? 5 : $request["max"];
        $nextPage = false;

        $sql = $table->createSelectStatement ($request, false, $properties, $page, $pageLength, $error, $verboseErrors);
        if (false === $sql)
            return false;

        $dbtable = new DBTable ();
        $rows = $dbtable->db->executeSelect ($table->getDBTableName (), $sql,true);
        if (false === $rows)
            {
            if ($verboseErrors)
                $error = $dbtable->db->getLastError();
            else
                $error = "Internal error";
            return false;
            }

        if (count ($rows) > $pageLength)
            {
            $rows = array_slice ($rows, 0, $pageLength);
            $nextPage = $page + 1;
            }

        $metadata = $table->getPublicMetadata (false);

        $error = NULL;
        return $rows;
        }

    public static function getPublicMetadata ($tableName, $request, &$error, $verboseErrors = false)
        {
        $table = MetaData::getByName ($tableName, $error);
        if (empty ($table))
            {
            if (empty ($error) || !$verboseErrors)
                $error = "Class {$tableName} not found";
            return false;
            }

        $error = NULL;
        return $table->getPublicMetadata (true);
        }

    // generic function for all tables (taking field names from specific table class)
    public static function getInstance ($tableName, $id, $request, &$metadata, &$canEdit, &$error, $verboseErrors = false)
        {
        $canEdit = false;
        $table = MetaData::getByName ($tableName, $error);
        if (empty ($table))
            {
            if (empty ($error) || !$verboseErrors)
                $error = "Class {$tableName} not found";
            return false;
            }

        $properties = empty ($request["props"]) ? true : $request["props"];

        $sql = $table->createSelectStatement ($request, $id, $properties, false, 0, $error, $verboseErrors);
        if (false === $sql)
            return false;

        $dbtable = new DBTable ();
        $rows = $dbtable->db->executeSelect ($table->getDBTableName (), $sql,true);
        if (false === $rows)
            {
            if ($verboseErrors)
                $error = $dbtable->db->getLastError();
            else
                $error = "Internal error";
            return false;
            }

        $metadata = $table->getPublicMetadata ();

        $error = NULL;
        if (empty ($rows)) 
            return NULL;

        $canEdit = $table->canEditInstance ($id, $error2, $verboseErrors);

        $instance = $rows[0];
        return $instance;
        }

    /******************************************************
    * Add an entry to audit_log table
    *******************************************************/
    function auditLog ($user, $affected_table, $affected_row, $action, $logData)
        {
        // makes an entry into the log - username comes from php session (login name)
        $logData = $this->db->escapeString ($logData);
        $user = $this->db->escapeString ($user);
        $sql = <<<EOT
    (`username`, `affected_table`, `affected_row`, `action`, `logdata`)
  VALUES
    ('$user', '$affected_table', $affected_row, '$action', '$logData')
EOT;
        return $this->db->executeInsert (self::TABLE_AUDIT, $sql);
        }

    // generic save function for all tables
    public static function saveInstance ($tableName, $id, $oldValues, $newValues, &$error, $verboseErrors = false)
        {
        $table = MetaData::getByName ($tableName, $error);
        if (empty ($table))
            {
            if (empty ($error) || !$verboseErrors)
                $error = "Class {$tableName} not found";
            return false;
            }

        $sql = $table->createUpdateStatement ($id, $oldValues, $newValues, $auditText, $error, $verboseErrors);
        if (false === $sql || true === $sql)
            return $sql;

        $dbtable = new DBTable ();
        $affected = $dbtable->db->executeUpdate ($table->getDBTableName (), $sql, true);
        if (false === $affected)
            {
            if ($verboseErrors)
                $error = $dbtable->db->getLastError();
            else
                $error = "Internal error";
            return false;
            }
        if (0 == $affected)
            {
            $error = "Error saving the changes. Instance might already be modified by other users.";
            return false;
            }
        else
            {
            // need to add an entry to audit_log table
            $user = Authentication::getCurrentUserName ();
            $dbtable->auditLog ($user, $table->getDBTableName (), $id, 'update', "Entry updated by $user ($auditText)");
            }

        $error = NULL;
        return true;
        }

    }
