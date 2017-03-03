<?php

/**
 *  DB - A simple database class
 */
class DB {
	private static $instance = null;

	# @object, The PDO object
	private $pdo;

	public static function getInstance($host, $db, $user, $password, $port = NULL) {
		if (self::$instance === null) {
			self::$instance = new self($host, $db, $user, $password, $port = NULL);
		}

		return self::$instance;
	}

	private function __construct($host, $db, $user, $password, $port = NULL) {
		$dsn = "mysql:host=$host;dbname=$db";
		if(!is_null($port)) {
			$dsn .= ";port=$port";
		}
		try {
			# Read settings from INI file, set UTF8
			$this->pdo = new PDO($dsn, $user, $password, [
				PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
			]);

		}
		catch (PDOException $e) {
			die($e->getMessage());
		}
	}

	public function query($sql, $params = NULL) {
		if(!empty($params)) {
			$sth = $this->pdo->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
			$sth->execute($params);
		} else {
			$sth = $this->pdo->query($sql);
		}

		return $sth;
	}

	/**
	 * @param string $table_name
	 * @param string|array $select
	 * $select = ['id', 'name', 'value']
	 * @param array|NULL $filter
	 * $filter = ['id' => 1]
	 * @param array|NULL $order
	 * $order = [
	 *      ['id', 'name'],
	 *      'DESC'
	 * ]
	 * @param array|NULL $limits
	 * $limits = ['limit' => 5, 'offset' => 3]
	 * @return array|bool
	 */
	public function find($table_name, $select = '*', array $filter = NULL, array $order = NULL, array $limits = NULL) {
		$sql = 'SELECT ';
		$params = [];

		if(is_array($select)) {
			$sql .= implode(',', $select);
		} else {
			$sql .= $select;
		}

		$sql .= " FROM `$table_name`";

		if(!empty($filter)) {
			$sql .= ' WHERE ';
			foreach($filter as $col => $value) {
				$sql .= end($filter) !== $value ? "`$col`=:$col AND " : "`$col`=:$col";
			}
			$params = array_merge($params, $filter);
		}

		if(!empty($order)) {
			$sql .= ' ORDER BY ';
			$sql .= implode(',', $order[0]);
			$sql .= " ".$order[1];
		}

		if(!empty($limits)) {
			$sql .= ' LIMIT ';
			if(!empty($limits['offset'])) {
				$sql .= $limits['offset'].','.$limits['limit'];
			} else {
				$sql .= $limits['limit'];
			}
		}

		return $this->query($sql, $params)->fetchAll(PDO::FETCH_CLASS);
	}

	public function add($table_name, $data) {
		$sql = 'INSERT INTO '.$table_name.' ('.implode(',', array_keys(reset($data))).') VALUES ';

		foreach($data as &$row) {
			$row_str = '(';
			foreach($row as $col => $value) {
				$row_str .= "'$value',";
			}

			$row = substr($row_str, 0, -1).')';
		}

		$sql .= implode(',', $data);

		return $this->query($sql);
	}

	public function count($table_name) {
		$sql = 'SELECT COUNT(*) as count FROM '.$table_name;
		$sth = $this->query($sql);

		$result = $sth->fetchAll(PDO::FETCH_CLASS);
		return !empty(reset($result)) ? reset($result)->count : FALSE;
	}

	public function remove($table_name, array $condition) {
		$sql = "DELETE FROM $table_name WHERE ";

		foreach($condition as $col => $value) {
			if(is_array($value) ){
				$sql .= "`$col` IN (".implode(',', $value).") AND ";
				unset($condition[$col]);
			} else {
				$sql .= "`$col`=:$col AND ";
			}

			$sql = substr($sql, 0, -5);
		}

		return $this->query($sql, $condition);
	}

	public function update($table_name, $data, $condition) {
		$sql = "UPDATE $table_name SET ";

		foreach($data as $k => $v) {
			$sql .= "`$k`='$v',";
		}

		$sql = substr($sql, 0, -1);

		$sql .= ' WHERE ';
		foreach($condition as $col => &$value) {
			if(is_array($value) ){
				$sql .= "`$col` IN (".implode(',', $value).") AND ";
				unset($condition[$col]);
			} else {
				$sql .= "`$col`=:$col AND ";
			}

			$sql = substr($sql, 0, -5);
		}

		return $this->query($sql, $condition);
	}

	public function search_doubles($table_name, $offset = FALSE) {
		$sql = "SELECT * FROM `$table_name` GROUP BY `name` HAVING COUNT(*) > 1 ORDER BY `id` ASC";
		if($offset) {
			$limit = $this->query($sql)->rowCount() - $offset;
			$sql .= " LIMIT $offset, $limit";
		}

		return $this->query($sql)->fetchAll(PDO::FETCH_CLASS);
	}

	/**
	 * Cоздает необходимые таблицы
	 */
	public function create_tables() {
		$account_sql = '
			CREATE TABLE IF NOT EXISTS `account` (
			  `id` int(11) unsigned NOT NULL,
			  `data` longtext,
			  UNIQUE KEY `account` (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;
		';

		$customers_sql = '
			CREATE TABLE IF NOT EXISTS `customers` (
			  `id` int(11) unsigned NOT NULL,
			  `name` varchar(255) DEFAULT NULL,
			  `data` longtext,
			  UNIQUE KEY `customer_id` (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;
		';

		$leads_sql = '
			CREATE TABLE IF NOT EXISTS `leads` (
			  `id` int(11) unsigned NOT NULL,
			  `name` varchar(255) DEFAULT NULL,
			  `pipeline_id` int(11) unsigned DEFAULT NULL,
			  `data` longtext,
			  UNIQUE KEY `lead_id` (`id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;
		';

		$runtime_log_sql = '
			CREATE TABLE IF NOT EXISTS `runtime_log` (
			  `step_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			  `description` varchar(255) DEFAULT NULL,
			  `step_info` longtext,
			  `date_start` int(11) NOT NULL,
			  `date_end` int(11) DEFAULT NULL,
			  PRIMARY KEY (`step`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8;
		';

		$this->query($account_sql.$customers_sql.$leads_sql.$runtime_log_sql);
	}

	public function data_isset() {
		$tables = ['account', 'customers', 'leads', 'runtime_log'];
		foreach($tables as $table) {
			if((int)$this->count($table) > 0) {
				return TRUE;
			}
		}

		return FALSE;
	}

	public function clear_table($table_name) {
		$sql = 'TRUNCATE TABLE '.$table_name;

		return $this->query($sql);
	}

	public function clear_data() {
		$tables = ['account', 'customers', 'leads', 'runtime_log'];
		foreach($tables as $table) {
			$this->clear_table($table);
		}
	}

	public function get_last_record($table_name) {
		$result = $this->find($table_name, '*', NULL, NULL, ['limit' => 1]);
		return !empty(reset($result)) ? reset($result): FALSE;
	}

	public function get_last_action() {
		$result = $this->find('runtime_log', '*', NULL, [['step_id'],'DESC'], ['limit' => 1]);
		return !empty(reset($result)) ? reset($result): FALSE;
	}

	/**
	 *  Returns the last inserted id.
	 *  @return string
	 */
	public function get_last_insert_id()
	{
		return $this->pdo->lastInsertId();
	}

	/**
	 * Starts the transaction
	 * @return boolean, true on success or false on failure
	 */
	public function begin_transaction()
	{
		return $this->pdo->beginTransaction();
	}

	/**
	 *  Execute Transaction
	 *  @return boolean, true on success or false on failure
	 */
	public function commit_transaction()
	{
		return $this->pdo->commit();
	}

	/**
	 *  Rollback of Transaction
	 *  @return boolean, true on success or false on failure
	 */
	public function roll_back()
	{
		return $this->pdo->rollBack();
	}


	public function __destruct() {
		$this->pdo = null;
	}

	private function __wakeup() {}
	private function __clone() {}

}