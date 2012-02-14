<?php

/**
 * A class that provides a level of abstraction for managing a PDO SQLite Database
 * 
 * Copyright 2011 Kitson P. Kelly, All Rights Reserved
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *  * Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 *  * Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *  * Neither the name of Kitson P. Kelly nor the names of its contributors
 *    may be used to endorse or promote products derived from this software
 *    without specific prior written permission.
 *    
 *  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 *  ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 *  WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 *  DISCLAIMED.  IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE
 *  FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 *  DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 *  SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 *  CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 *  OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 *  OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 * 
 * @author dojo (at) kitsonkelly.com
 *
 */

namespace kpk\core\db;

require_once('logging.inc.php');

  class Database {
    private $db;
    private $dbname;
    /**
     * @var \kpk\core\logging\Log
     */
    private $logger;
    private $inTransaction = false;
    private $transcount = 0;
    private $statements = array();
    private $parameters = array();
    
    public $enableTransactions = true;
    public $commitInterval = 30000;
    public $errorInfo;

    /**
     * Creates a new db\Database Object
     * @param string $dbName
     * @param boolean $journal [opt]
     * @param boolean $synchronus [opt]
     * @param string $initialise_script [opt]
     * @param string $page_size [opt]
     * @return kpk\core\Database
     */
    function __construct($dbName,$journal=false,$synchronus=false,$initialise_script=false,$page_size='') {
      global $applog;
      $this->logger = $applog;
      if (!empty($initialise_script)) {
        if (file_exists($initialise_script)) {
          $sqlfile = file_get_contents($initialise_script);
          $sqlstatements = explode(';',$sqlfile);
          if (!empty($page_size)) {
            array_unshift($sqlstatements,'PRAGMA page_size='.$page_size);
          }
          $tempdb = new \PDO('sqlite:'.$dbName,null,null,array(\PDO::ATTR_TIMEOUT=>1));
          if ($tempdb->errorinfo[0] !== '00000') {
            $this->logger->logEvent(__METHOD__,'DB Error: '.$tempdb->errorinfo[0].':'.$tempdb->errorinfo[1].':'.$tempdb->errorinfo[2]);
          }
          foreach ($sqlstatements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
              $tempdb->exec($statement);
            }
          }
          $tempdb = null;
        } else {
          $this->logger->logEvent(__METHOD__,'Cannot Find Initialise Script: "'.$initialise_script.'"',\kpk\core\logging\Log::EVENT_ERROR);
        }
      }
      if (file_exists($dbName)) {
        $this->db = new \PDO('sqlite:'.$dbName,null,null,array(\PDO::ATTR_PERSISTENT=>true,\PDO::ATTR_TIMEOUT=>1));
        $this->checkError();
        if (!$journal) {
          $this->db->exec('PRAGMA journal_mode = OFF;');
          $this->logger->logEvent(__METHOD__,'Journaling Off');
        }
        if (!$synchronus) {
          $this->db->exec('PRAGMA synchronous = OFF;');
          $this->logger->logEvent(__METHOD__,'Synchronous Off');
        }
        $this->dbName = $dbName;
      } else {
        $this->logger->logEvent(__METHOD__,'Cannot Find File: "'.$dbName.'"',\kpk\core\logging\Log::EVENT_ERROR);
      }
    }
    
    function __destruct() {
      if ($this->inTransaction) {
        $this->db->commit();
      }
      $this->db = null;
    }
    
    /**
     * Quotes a SQL Parameter
     * @param string $text
     * @return string
     */
    public function quote($text) {
      return $this->db->quote($text);
    }
    
    /**
     * Sets the cache size of the SQLite Database
     * @param integer $cacheSize
     */
    public function setCacheSize($cacheSize) {
      $this->db->exec('PRAGMA cache_size = '.$cacheSize);
    }
    
    /**
     * Sets the REGEX_MATCH function for the SQLite Database
     */
    public function setRegExMatch() {
      $this->db->sqliteCreateFunction('REGEX_MATCH',array($this,'RegExMatch'),2);
    }
    
    /**
     * A Callback Function for use by SQLite
     * @param string $str
     * @param string $regex
     */
    public function RegExMatch($str,$regex) {
      if (preg_match($regex, $str, $attributes)) {
        return $attributes[0];
      }
      return false;
    }
    
    /**
     * Prepares a Statement and adds it to the statement cache
     * @param string $name
     * @param string $sql
     * @param array $parameters
     */
    public function prepare($name,$sql,array $parameters) {
      $this->statements[$name] = $this->db->prepare($sql);
      $this->checkError();
      $this->parameters[$name] = $parameters;
      $this->logger->logEvent(__METHOD__,'Prepared Statement: '.$name);
    }
    
    /**
     * Internal function that checks the last DB transaction for an error.
     * @return boolean
     */
    private function checkError() {
      $this->errorInfo = $this->db->errorInfo();
      if ($this->errorInfo[0] !== '00000') {
        $this->logger->logEvent(__METHOD__,'Post Commit Error: '.$this->errorInfo[0].':'.$this->errorInfo[1].':'.$this->errorInfo[2]);
        return true;
      } else {
        return false;
      }
    }
    
    public function commit() {
      if ($this->inTransaction) {
        if ($this->db->commit()) {
          $this->logger->logEvent(__METHOD__,'Comitted '.$this->transcount.' transactions');
          $this->inTransaction = false;
          $this->transcount = 0;
          return true;
        } else {
          $this->logger->logEvent(__METHOD__,'Failed to commit '.$this->transcount.' transactions');
          $this->checkError();
          return false;
        }
      }
    }
    
    /**
     * Executes SQL against the database
     * @param string|array $sql
     * @param bool $returnInsertID [opt]
     * @param bool $useTransaction [opt]
     */
    public function executeSQL($sql,$returnInsertID=false,$useTransaction=true) {
      if ($useTransaction) {
        if (!$this->inTransaction) {
          $this->db->beginTransaction();
          $this->inTransaction = true;
        }
      } else {
        if ($this->inTransaction) {
          $this->commit();
        }
      }
      if (is_array($sql)) {
        if ($returnInsertID) {
          $results = array();
          foreach ($sql as $statement) {
            $this->db->exec($statement);
            $this->transcount++;
            $results[] = $this->db->lastInsertId();
          }
        } else {
          $results = 0;
          foreach ($sql as $statement) {
            $results += $this->db->exec($statement);
          }
        }
      } else {
        $results = $this->db->exec($sql);
        $this->transcount++;
        if ($returnInsertID) {
          $results = $this->db->lastInsertId();
        }
      }
      $this->checkError();
      if ($useTransaction) {
        if ($this->transcount >= $this->commitInterval) {
          $this->commit();
        }
      }
      return $results;
    }
    
    public function executeSQLFile($filename) {
      if (file_exists($filename)) {
        $sql = file_get_contents($filename);
        $this->executeSQL($sql,false,false);
      } else {
        $this->logger->logEvent(__METHOD__,'Cannot find file: '.basename($filename),\kpk\core\logging\Log::EVENT_ERROR);
      }
    }
    
    /**
     * Executes on of the prepared statements
     * @param string $name
     * @param array $values
     * @param boolean $useTransaction [opt]
     * @param boolean $returnInsertID [opt]
     * @return integer
     */
    public function executeStatement($name,array $values,$useTransaction=true,$returnInsertID=false) {
      if (array_key_exists($name,$this->statements)) {
        if ($useTransaction) {
          if (!$this->inTransaction) {
            $this->db->beginTransaction();
            $this->inTransaction = true;
          }
        } else {
          if ($this->inTransaction) {
            $this->commit();
          }
        }
        $this->statements[$name]->execute($values);
        $this->checkError();
        if ($returnInsertID) {
          $lastInsertID = $this->db->lastInsertId();
        }
        $this->transcount++;
        if ($useTransaction) {
          if ($this->transcount >= $this->commitInterval) {
            $this->commit();
          }
        }
        if ($returnInsertID) {
          return $lastInsertID;
        }
      } else {
        $this->logger->logEvent(__METHOD__,'Cannot find Satement: '.$name,\kpk\core\logging\Log::EVENT_ERROR);
      }
    }
    
    /**
     * Empties all the tables in the database
     */
    public function emptyTables() {
      $results = array();
      foreach ($this->tables as $table) {
        $this->logger->logEvent(__METHOD__,'Empty '.$table);
        $results[$table] = $this->executeSQL('DELETE FROM '.$table);
      }
      $this->commit();
      return $results;
    }
    
    /**
     * Vacuums the database
     */
    public function vacuum() {
      $this->logger->logEvent(__METHOD__,'Vacuum DB');
      $this->executeSQL('VACUUM;',false,false);
    }
    
    /**
     * Inserts values into the database
     * @param string $table
     * @param array $data
     * @param bool $returnInsertId
     * @param bool $useTransaction
     */
    public function insertValues($table,array $data, $returnInsertId=true, $useTransaction=true) {
      $keys = '';
      $values = '';
      $first = true;
      foreach ($data as $key=>$value) {
        if ($first) {
          $first = false;
        } else {
          $keys .= ',';
          $values .= ',';
        }
        $keys .= $key;
        $values .= "'$value'";
      }
      $sql = 'INSERT INTO '.$table.'('.$keys.') VALUES ('.$values.')';
      return $this->executeSQL($sql,$returnInsertId,$useTransaction);
    }
    
    /**
     * Function generates a prepared statement for inserting values
     * @param string $table
     * @param array $keys
     * @param boolean $replace [opt]
     */
    public function prepareInsert($table,array $keys,$replace=false) {
      if (!array_key_exists($table.'.insert',$this->statements)) {
        $this->logger->logEvent(__METHOD__,'Adding Statement: INSERT '.$table,\kpk\core\logging\Log::EVENT_INFORMATION);
        $parameters = array();
        foreach ($keys as $key) {
          $parameters[] = ':'.$key;
        }
        if ($replace) {
          $sql = 'INSERT OR REPLACE INTO '.$table.'('.implode(',',$keys).') VALUES ('.implode(',',$parameters).')';
        } else {
          $sql = 'INSERT INTO '.$table.'('.implode(',',$keys).') VALUES ('.implode(',',$parameters).')';
        }
        $this->prepare($table.'.insert',$sql,$keys);
      }
    }
    
    /**
     * Prepares an update statement based on supplied information
     * @param string $table
     * @param array $keys
     * @param string $idColumn
     */
    public function prepareUpdate($table,array $keys,$idColumn) {
      if (!array_key_exists($table.'.update',$this->statements)) {
        $this->logger->logEvent(__METHOD__,'Adding Satement: UPDATE '.$table,\kpk\core\logging\Log::EVENT_INFORMATION);
        $parameters = array();
        foreach ($keys as $key) {
          $parameters[] = $key.' = :'.$key;
        }
        $where = $idColumn.' = :'.$idColumn;
        $keys[] = $idColumn;
        $sql = 'UPDATE '.$table.' SET '.implode(',',$parameters).' WHERE '.$where;
        $this->prepare($table.'.update',$sql,$keys);
      }
    }
    
    /**
     * Returns if there is a perpared statement for a particular table
     * @param string $table
     * @param boolean $insert
     * @return boolean
     */
    public function isPrepared($table,$insert=true) {
      if ($insert) {
        return array_key_exists($table.'.insert',$this->parameters);
      } else {
        return array_key_exists($table.'.update',$this->parameters);
      }
    }
    
    /**
     * Inserts values using a prepared statement
     * @param string $table
     * @param array $data
     * @param bool $returnInsertID
     * @param bool $useTransaction
     * @return mixed
     */
    public function insertPrepared($table,array $data,$returnInsertID=false,$useTransaction=true) {
      if (array_key_exists($table.'.insert',$this->parameters)) {
        $parameters = $this->parameters[$table.'.insert'];
        $values = array();
        foreach ($parameters as $parameter) {
          if (array_key_exists($parameter,$data)) {
            $values[':'.$parameter] = $data[$parameter];
          } else {
            $values[':'.$parameter] = NULL;
          }
        }
        if ($returnInsertID) {
          return $this->executeStatement($table.'.insert',$values,$useTransaction,$returnInsertID);
        } else {
          $this->executeStatement($table.'.insert',$values,$useTransaction,$returnInsertID);
        }
      } else {
        $this->logger->logEvent(__METHOD__,'Cannot find INSRET parameters: '.$table,\kpk\core\logging\Log::EVENT_ERROR);
      }
    }
    
    /**
     * Update a record based using a prepared statement
     * @param string $table
     * @param array $data
     * @param boolean $useTransaction
     */
    public function updatePrepared($table,array $data,$useTransaction=true) {
      if(array_key_exists($table.'.update',$this->parameters)) {
        $parameters = $this->parameters[$table.'.update'];
        $values = array();
        foreach ($parameters as $parameter) {
          if (array_key_exists($parameter,$data)) {
            $values[':'.$parameter] = $data[$parameter];
          } else {
            $values[':'.$parameter] = NULL;
          }
        }
        return $this->executeStatement($table.'.update',$values,$useTransaction,false);
      } else {
        $this->logger->logEvent(__METHOD__,'Cannot find update parameters: '.$table,\kpk\core\logging\Log::EVENT_ERROR);
      }
    }
    
    /**
     * Retrieves entire records from the database and returns as an array
     * @param string $table
     * @param array $where
     * @param boolean $withROWID
     * @return array
     */
    public function retrieveRecords($table,array $where,$withROWID = false) {
      $records = array();
      if ($withROWID) {
        $sql = 'SELECT ROWID,* FROM '.$table;
      } else {
        $sql = 'SELECT * FROM '.$table;
      }
      if (count($where>0)) {
        $sql .= ' WHERE ';
        $sql .= implode(' AND ',$where);
      }
      foreach ($this->db->query($sql) as $record) {
        $records[] = $record;
      }
      return $records;
    }
    
    /**
     * Retrieve an array of records from the database providing a SQL Statement
     * @param string $sql
     * @param string $idColumn [opt]
     * @return array
     */
    public function retrieveRecordsSQL($sql,$idColumn='') {
      $records = array();
      foreach ($this->db->query($sql,\PDO::FETCH_ASSOC) as $record) {
        if (empty($idColumn)) {
          $records[] = $record;
        } else {
          $records[$record[$idColumn]] = $record;
        }
      }
      return $records;
    }
    
    /**
     * Retrieve a record from the database via provided SQL Statement
     * @param string $sql
     * @return mixed
     */
    public function retrieveRecordSQL($sql) {
      $record = false;
      foreach ($this->db->query($sql,\PDO::FETCH_ASSOC) as $result) {
        $record = $result;
      }
      return $record;
    }
    
    /**
     * Retrieve a list of items from the database
     * @param string $table
     * @param string $id_column
     * @param string $label_column [opt]
     * @param string $where [opt]
     * @param string $groupby [opt]
     * @param string $orderby [opt]
     * @return array
     */
    public function retrieveList($table,$id_column,$label_column='',$where='',$groupby='',$orderby='') {
      $results = array();
      if (($id_column==$label_column)||(empty($label_column))) {
        $sql = 'SELECT DISTINCT '.$id_column.' FROM '.$table;
      } else {
        $sql = 'SELECT DISTINCT '.$id_column.','.$label_column.' FROM '.$table;
      }
      if (!empty($where)) {
        $sql .= ' WHERE '.$where;
      }
      if (!empty($groupby)) {
        $sql .= ' GROUP BY '.$groupby;
      }
      if (!empty($orderby)) {
        $sql .= ' ORDER BY '.$orderby;
      }
      foreach ($this->db->query($sql) as $row) {
        if (($id_column==$label_column)||(empty($label_column))) {
          $results[] = array('id'=>$row[$id_column],'label'=>$row[$id_column]);
        } else {
          $results[] = array('id'=>$row[$id_column],'label'=>$row[$label_column]);
        }
      }
      return $results;
    }
    
    /**
     * Retrieve an array of columns from a table
     * @param string $table
     * @param array $columns
     * @param string $where [opt]
     * @param string $groupby [opt]
     * @param string $orderby [opt]
     * @param string $limit [opt]
     * @param bool $logSQL [opt]
     * @return array
     */
    public function retrieveColumns($table,array $columns,$where='',$groupby='',$orderby='',$limit='',$logSQL=false) {
      $columnList = implode(',',$columns);
      $sql = "SELECT DISTINCT $columnList FROM $table";
      if (!empty($where)) {
        $sql .= " WHERE $where";
      }
      if (!empty($groupby)) {
        $sql .= " GROUP BY $groupby";
      }
      if (!empty($orderby)) {
        $sql .= " ORDER BY $orderby";
      }
      if ($limit) {
        $sql .= " LIMIT $limit";
      }
      $sql .= ';';
      if ($logSQL) {
        $this->logger->logEvent(__METHOD__,'sql='.$sql,\kpk\core\logging\Log::EVENT_INFORMATION);
      }
      $statement = $this->db->prepare($sql);
      $this->checkError();
      $statement->execute();
      $results = $statement->fetchAll(\PDO::FETCH_ASSOC);
      $statement->closeCursor();
      return $results;
    }
    
    /**
     * Retrieve an array of columns with a callback function for each record
     * @param callback $callback
     * @param string $table
     * @param array $columns
     * @param string $where [opt]
     * @param string $groupby [opt]
     * @param string $orderby [opt]
     */
    public function retrieveColumnsCallback($callback,$table,array $columns,$where='',$groupby='',$orderby='') {
      if (is_callable($callback)) {
        $columnList = implode(',',$columns);
        $sql = "SELECT $columnList FROM $table";
        if (!empty($where)) {
          $sql .= " WHERE $where";
        }
        if (!empty($groupby)) {
          $sql .= " GROUP BY $groupby";
        }
        if (!empty($orderby)) {
          $sql .= " ORDER BY $orderby";
        }
        $sql .= ';';
        foreach ($this->db->query($sql,\PDO::FETCH_ASSOC) as $row) {
          call_user_func($callback,$row);
        }
        return true;
      } else {
        $this->logger->logEvent(__METHOD__,'$callback is not a valid function.',\kpk\core\logging\Log::EVENT_ERROR);
        return false;
      }
    }
    
    /**
     * Retrieve key value pair list of values
     * @param string $table
     * @param string $id_column
     * @param string $label_column [opt]
     * @param string $where [opt]
     * @param string $groupby [opt]
     * @param string $orderby [opt]
     * @return array
     */
    public function retrieveOptions($table,$id_column,$label_column='',$where='',$groupby='',$orderby='') {
      $results = array();
      if (($id_column==$label_column)||(empty($label_column))) {
        $sql = 'SELECT DISTINCT '.$id_column.' FROM '.$table;
      } else {
        $sql = 'SELECT DISTINCT '.$id_column.','.$label_column.' FROM '.$table;
      }
      if (!empty($where)) {
        $sql .= ' WHERE '.$where;
      }
      if (!empty($groupby)) {
        $sql .= ' GROUP BY '.$groupby;
      }
      if (!empty($orderby)) {
        $sql .= ' ORDER BY '.$orderby;
      }
      foreach ($this->db->query($sql,\PDO::FETCH_ASSOC) as $row) {
        if (($id_column==$label_column)||(empty($label_column))) {
          $results[$row[$id_column]] = $row[$id_column];
        } else {
          $results[$row[$id_column]] = $row[$label_column];
        }
      }
      return $results;
    }
    
    public function retrieveValues($table,array $value_columns,$id_column,$id,$where='',$logSql=false) {
      $results = array();
      $sql = 'SELECT ';
      $sql .= implode(',',$value_columns);
      $sql .= " FROM $table WHERE $id_column='$id'";
      if ($where) {
        $sql .= ' AND '.$where;
      }
      if ($logSql) {
        $this->logger->logEvent(__METHOD__,'sql='.$sql,\kpk\core\logging\Log::EVENT_DEBUG);
      }
      $statement = $this->db->prepare($sql);
      $this->checkError();
      $statement->execute();
      $results = $statement->fetchAll(\PDO::FETCH_ASSOC);
      $statement->closeCursor();
      return $results[0];
    }
    
    /**
     * Retrieve a single value from a database
     * @param string $table
     * @param string $value_column
     * @param string $id_column [opt]
     * @param string $id [opt]
     * @param string $where [opt]
     * @return string
     */
    public function retrieveValue($table,$value_column,$id_column='',$id='',$where='') {
      $results = '';
      $sql = "SELECT $value_column FROM $table";
      if ($id_column && $id) {
        $sql .= " WHERE $id_column='$id'";
        if ($where) {
          $sql .= ' AND '.$where;
        }
      } elseif ($where) {
        $sql .= ' WHERE '.$where;
      }
      foreach ($this->db->query($sql,\PDO::FETCH_NUM) as $row) {
        $results = $row[0];
      }
      return $results;
    }
    
    public function setKeyValue($table,$key_column,$key,$value_column,$value) {
      $results = false;
      $sql = "UPDATE $table SET $value_column='$value' WHERE $key_column='$key'";
      $results = $this->executeSQL($sql);
      return $results;
    }
    
    /**
     * Updated the record based on supplied array of key values
     * @param string $table
     * @param array $keyValues
     * @param string $idColumn
     * @param string $id
     * @param string $where
     */
    public function updateKeyValues($table,array $keyValues,$idColumn,$id,$where='') {
      $results = false;
      $kvArray = array();
      foreach ($keyValues as $key=>$value) {
        $kvArray[] = $key.'='.$this->db->quote($value);
      }
      $setSQL = implode(',',$kvArray);
      $sql = "UPDATE $table SET $setSQL WHERE $idColumn='$id'";
      if ($where) {
        $sql .= " AND $where";
      }
      $this->logger->logEvent(__METHOD__,'$sql = '.$sql);
      $results = $this->executeSQL($sql,false,false);
      return $results;
    }
    
    /**
     * Delete records from a table based on supplied data
     * @param string $table
     * @param string $idColumn
     * @param string $idValue
     * @param string $where [opt]
     * @return int
     */
    public function deleteRecords($table,$idColumn,$idValue,$where='') {
      $sql = "DELETE FROM $table WHERE $idColumn=".$this->quote($idValue);
      if ($where) {
        $sql .= " AND $where";
      }
      return $this->executeSQL($sql,false,false);
    }
    
    /**
     * Retrieves an associated array of values where the values based on a relationship
     * @param string $group_table
     * @param array $group_columns
     * @param string $group_id
     * @param string $item_table
     * @param array $item_columns
     * @param string $item_id
     * @param string $item_where [opt]
     * @param string $group_order [opt]
     * @param string $item_order [opt]
     * @return array
     */
    public function retriveGroup($group_table,array $group_columns,$group_id,$item_table,array $item_columns,$item_id,$item_where='',$group_order='',$item_order='') {
      $results = array();
      $group_sql = 'SELECT ';
      $group_sql .= implode(',',$group_columns);
      $group_sql .= ' FROM '.$group_table;
      if ($group_order) {
        $group_sql .= ' ORDER BY '.$group_order;
      }
      foreach ($this->db->query($group_sql) as $group) {
        $results[$group[$group_id]] = array('items'=>array());
        foreach ($group_columns as $column) {
          $results[$group[$group_id]][$column] = $group[$column];
        }
        $item_sql = 'SELECT ';
        $item_sql .= implode(',',$item_columns);
        if (empty($item_where)) {
          $item_sql .= " FROM $item_table WHERE $group_id = '$group[$group_id]'";
        } else {
          $item_sql .= " FROM $item_table WHERE $item_where and $group_id = '$group[$group_id]'";
        }
        if ($item_order) {
          $item_sql .= ' ORDER BY '.$item_order;
        }
        foreach ($this->db->query($item_sql) as $item) {
          $results[$group[$group_id]]['items'][$item[$item_id]] = array();
          foreach ($item_columns as $item_column) {
            $results[$group[$group_id]]['items'][$item[$item_id]][$item_column] = $item[$item_column];
          }
        }
      }
      foreach ($results as $group_id=>$group) {
        if (count($group['items'])==0) {
          unset($results[$group_id]);
        }
      }
      return $results;
    }
  }
  
?>