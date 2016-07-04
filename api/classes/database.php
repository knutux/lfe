<?php
/**********************************************************
*
* Class:   Database
*
* Wraps MySQL functionality and allows executing the SQL
* statements in uniform way.
* Most of the functions ensure that logged in user has
* access to perform select/insert/update/delete on the
* table.
* 
***********************************************************/

class Database
    {
    private static $db = array ();
    private $handle = 0;
    private $lastError = NULL;

    const READ = "Read";
    const CREATE = "Create";
    const EDIT = "Edit";
    const DELETE = "Delete";

    public function __destruct ()
        {
        $this->disconnect ();
        }
    
    /******************************************************
    * Connects to the MySql database using specified
    * configuration (predefined settings are used if called
    * without arguments)
    *******************************************************/
    static public function Instance ($accessManager, $host = false, $user = false, $password = false, $dbName = false, $autocommit = true)
        {
        $host = empty ($host) ? DB_HOST : $host;
        $user = empty ($user) ? DB_USER : $user;
        $password = false === $password ? DB_PASS : $password;
        $dbName = empty ($dbName) ? DB_NAME : $dbName;
        $key = "$host|$user|$dbName";
        if (empty (self::$db[$key]))
            {
            $db = new Database ();
            if ($db->connect ($host, $user, $password, $dbName, $autocommit))
                {
                self::$db[$key] = $db;
                }
            else
                self::$db[$key] = NULL;
            }

        return self::$db[$key];
        }

    /******************************************************
    * Connects to the MySql database
    *******************************************************/
    public function connect ($host, $user, $password, $database, $autocommit = true)
        {
        $this->handle = new mysqli ($host, $user, $password, $database);
        if ($this->handle->connect_errno)
            {
            error_log ("Failed to connect to MySQL: (" . $this->handle->connect_errno . ") " . $this->handle->connect_error);
            }
        else
            {
            // $this->handle->query("SET NAMES 'utf8'");
            $this->handle->autocommit ($autocommit);
            return true;
            }

        return false;
        }
        
    public function autocommit ($autocommit)
        {
        if (!empty ($this->handle))
            $this->handle->autocommit ($autocommit);
        }

    public function disconnect ()
        {
        if ($this->handle)
            {
            $this->handle->close ();
            }
        }

    /******************************************************
    * Checks if current user has specified access to the table
    *******************************************************/
    public function checkAccess ($tableName, $access)
        {
        if (empty ($this->accessManager) || !$this->accessManager->checkAccess ($tableName, $access))
            {
            // everyone should be able to create audit log records
            $exception = Database::CREATE == $access && "audit_log" == $tableName;
            if (!$exception)
                {
                $this->lastError = "No $access access on $tableName";
                return false;
                }
            }

        $this->lastError = NULL;
        return true;
        }

    /******************************************************
    * Executes SQL select statement.
    * Ensures current user has access unless $skipAccessCheck
    * parameter is true (this should only be used in
    * exceptional cases)
    *******************************************************/
    public function executeSelect ($tableName, $sql, $skipAccessCheck = false)
        {
        if (!$skipAccessCheck && !$this->checkAccess ($tableName, Database::READ))
            return false;

        $hResult = $this->handle->query ($sql);

        // if error occurred, we still need to free resources, but set result to "false"
        $resultSet = $this->logError () ? null : false;

        if ($hResult && $hResult->num_rows > 0)
            {
            while ($row = $hResult->fetch_array (MYSQLI_ASSOC))
                {
                $resultSet[] = $row;
                }
            }

        if ($hResult)
            $hResult->free ();
        return $resultSet;
        }

    /******************************************************
    * Rertieves the prepared SQL statement handle, which
    * can be used to bind variables and fetch rows one by one.
    * Ensures current user has read access.
    *******************************************************/
    public function prepareSelect ($tableName, $sql)
        {
        if (!$this->checkAccess ($tableName, Database::READ))
            return false;

        $stmt = $this->handle->prepare ($sql);
        if (!$stmt)
            {
            $this->logError ($sql);
            return false;
            }

        return $stmt;
        }
    
    public function getInsertId ()
        {
        return $this->handle->insert_id;
        }

    /******************************************************
    * Executes SQL insert statement.
    * Ensures current user has create access
    * unless $skipAccessCheck parameter is true (this should
    * only be used in exceptional cases)
    * Params:
    *   $tableName - name of the DB table to modify
    *   $columnsAndValues - insert statement fragments, for
    *       example, "(Col1, Col2) VALUES (1, '2')"
    *   $returnId - if true, created row id is returned
    * Returns:
    *   false on error (use getLastError() to get message)
    *   ID or affected rows on success
    *******************************************************/
    public function executeInsert ($tableName, $columnsAndValues, $returnId = false, $skipAccessCheck = false)
        {
        if (!$skipAccessCheck && !$this->checkAccess ($tableName, Database::CREATE))
            return false;

        $affected = $this->executeSQLNoCheck ("INSERT INTO `$tableName` $columnsAndValues", true);
        if (!$affected || !$returnId)
            return $affected;

        return $this->getInsertId ();
        }
        
    /******************************************************
    * Executes SQL insert statement with bound parameter.
    * Used to insert blob (file, image)
    * Ensures current user has create access
    * Returns:
    *   false on error (use getLastError() to get message)
    *   ID on success
    *******************************************************/
    public function executeInsertWithParam ($tableName, $columnsAndValues, $val)
        {
        $returnId = true;
        if (!$this->checkAccess ($tableName, Database::CREATE))
            return false;

        $sqlStatement = "INSERT INTO `$tableName` $columnsAndValues";
        $stmt = $this->handle->prepare ($sqlStatement);
        if (!$stmt)
            {
            $this->logError ($sqlStatement);
            return false;
            }

        $null = NULL;
        $stmt->bind_param ('b', $null);
        $stmt->send_long_data (0, $val);

        $ret = $stmt->execute ();
        if (!$ret)
            $this->lastError = $this->getLastError (NULL, $stmt);
        $stmt->close ();
        if (!$ret)
            return $ret;

        return $this->getInsertId ();
        }
        
    /******************************************************
    * Executes SQL update statement.
    * Ensures current user has edit access on the table.
    * Params:
    *   $tableName - name of the DB table to modify
    *   $setAndWhere - update statement fragments, for
    *       example, "SET Col=1 WHERE Col=2"
    * Returns:
    *   false on error (use getLastError() to get message)
    *   affected row count on success
    *******************************************************/
    public function executeUpdate ($tableName, $setAndWhere, $accessAlreadyChecked = false)
        {
        if (!$accessAlreadyChecked && !$this->checkAccess ($tableName, Database::EDIT))
            return false;

        return $this->executeSQLNoCheck ("UPDATE `$tableName` $setAndWhere", true);
        }

    /******************************************************
    * Executes SQL delete statement.
    * Ensures current user has delete access on the table.
    * Params:
    *   $tableName - name of the DB table to delete record from
    *   $condition - where condition, for
    *       example, "WHERE ID=123"
    * Returns:
    *   false on error (use getLastError() to get message)
    *   affected row count on success
    *******************************************************/
    public function executeDelete ($tableName, $condition)
        {
        if (!$this->checkAccess ($tableName, Database::DELETE))
            return false;

        return $this->executeSQLNoCheck ("DELETE FROM `$tableName` $condition", true);
        }

    /******************************************************
    * Executes generic SQL statement.
    * Ensures current user has full access on all the tables.
    * Returns:
    *   false on error (use getLastError() to get message)
    *   affected row count on success
    *******************************************************/
    public function executeSQL ($sqlStatement, $returnAffected = false)
        {
        if (!$this->checkAccess ("All", "Any"))
            return false;

        return $this->executeSQLNoCheck ($sqlStatement, $returnAffected);
        }

    public function executeSQLNoCheck ($sqlStatement, $returnAffected = false)
        {
        if ($this->handle->query ($sqlStatement))
            {
            if ($returnAffected)
                return $this->handle->affected_rows;
            return true;
            }

        $this->logError ($sqlStatement);
        return false;
        }

    public function escapeString ($string)
        {
        return $this->handle->real_escape_string ($string);
        }
       
    public function begin ()
        {
        return true;
        }
        
    public function rollback ()
        {
        return $this->handle->rollback ();
        }
        
    public function commit ()
        {
        return $this->handle->commit ();
        }
        
    /******************************************************
    * Retrieves error message from given handle (prepared
    * statement) or from last executed action (if handle
    * is false).
    *******************************************************/
    public function getLastError ($sqlStatement = NULL, $handle = false)
        {
        if (false === $handle)
            {
            if (!empty ($this->lastError))
                return $this->lastError;
            $handle = $this->handle;
            }

        if (0 == $handle->errno)
            return NULL;

        switch (mysql_errno())
            {
            case 1062:
                return "Duplicate entry";

            default:
                return "MySql error. Please contact site administrators if error persists. ({$handle->errno}: {$handle->error}) : ".$sqlStatement;
            }
        }

    /******************************************************
    * Retrieves MySql error id of the last error message
    *******************************************************/
    public function getLastErrorId ($handle = false)
        {
        if (false === $handle)
            $handle = $this->handle;

        return $handle->errno;
        }

    protected function logError ($sqlStatement = NULL)
        {
        $error = $this->getLastError ($sqlStatement);
        if (NULL == $error)
            return true;

        error_log ($error);
        return false;
        }
    }

