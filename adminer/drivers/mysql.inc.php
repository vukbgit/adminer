<?php
namespace Adminer;

$drivers = array("server" => "MySQL / MariaDB") + $drivers;

if (!defined('Adminer\DRIVER')) {
	define('Adminer\DRIVER', "server"); // server - backwards compatibility
	// MySQLi supports everything, MySQL doesn't support multiple result sets, PDO_MySQL doesn't support orgtable
	if (extension_loaded("mysqli") && $_GET["ext"] != "pdo") {
		class Db extends \MySQLi {
			public $extension = "MySQLi", $flavor = '';

			function __construct() {
				parent::init();
			}

			/** @see https://php.net/mysqli.construct */
			function connect($server = "", $username = "", $password = "", $database = null, $port = null, $socket = null) {
				global $adminer;
				mysqli_report(MYSQLI_REPORT_OFF); // stays between requests, not required since PHP 5.3.4
				list($host, $port) = explode(":", $server, 2); // part after : is used for port or socket
				$ssl = $adminer->connectSsl();
				if ($ssl) {
					$this->ssl_set($ssl['key'], $ssl['cert'], $ssl['ca'], '', '');
				}
				$return = @$this->real_connect(
					($server != "" ? $host : ini_get("mysqli.default_host")),
					($server . $username != "" ? $username : ini_get("mysqli.default_user")),
					($server . $username . $password != "" ? $password : ini_get("mysqli.default_pw")),
					$database,
					(is_numeric($port) ? $port : ini_get("mysqli.default_port")),
					(!is_numeric($port) ? $port : $socket),
					($ssl ? ($ssl['verify'] !== false ? 2048 : 64) : 0) // 2048 - MYSQLI_CLIENT_SSL, 64 - MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT (not available before PHP 5.6.16)
				);
				$this->options(MYSQLI_OPT_LOCAL_INFILE, false);
				return $return;
			}

			function set_charset($charset) {
				if (parent::set_charset($charset)) {
					return true;
				}
				// the client library may not support utf8mb4
				parent::set_charset('utf8');
				return $this->query("SET NAMES $charset");
			}

			function result($query, $field = 0) {
				$result = $this->query($query);
				if (!$result) {
					return false;
				}
				$row = $result->fetch_array();
				return ($row ? $row[$field] : false);
			}

			function quote($string) {
				return "'" . $this->escape_string($string) . "'";
			}
		}

	} elseif (extension_loaded("mysql") && !((ini_bool("sql.safe_mode") || ini_bool("mysql.allow_local_infile")) && extension_loaded("pdo_mysql"))) {
		class Db {
			public
				$extension = "MySQL", ///< @var string extension name
				$flavor = '', ///< @var string different vendor with the same API, e.g. MariaDB, usually stays empty
				$server_info, ///< @var string server version
				$affected_rows, ///< @var int number of affected rows
				$info, ///< @var string see https://php.net/mysql_info
				$errno, ///< @var int last error code
				$error ///< @var string last error message
			;
			private $link, $result;

			/** Connect to server
			* @param string
			* @param string
			* @param string
			* @return bool
			*/
			function connect($server, $username, $password) {
				if (ini_bool("mysql.allow_local_infile")) {
					$this->error = lang('Disable %s or enable %s or %s extensions.', "'mysql.allow_local_infile'", "MySQLi", "PDO_MySQL");
					return false;
				}
				$this->link = @mysql_connect(
					($server != "" ? $server : ini_get("mysql.default_host")),
					("$server$username" != "" ? $username : ini_get("mysql.default_user")),
					("$server$username$password" != "" ? $password : ini_get("mysql.default_password")),
					true,
					131072 // CLIENT_MULTI_RESULTS for CALL
				);
				if ($this->link) {
					$this->server_info = mysql_get_server_info($this->link);
				} else {
					$this->error = mysql_error();
				}
				return (bool) $this->link;
			}

			/** Set the client character set
			* @param string
			* @return bool
			*/
			function set_charset($charset) {
				if (function_exists('mysql_set_charset')) {
					if (mysql_set_charset($charset, $this->link)) {
						return true;
					}
					// the client library may not support utf8mb4
					mysql_set_charset('utf8', $this->link);
				}
				return $this->query("SET NAMES $charset");
			}

			/** Quote string to use in SQL
			* @param string
			* @return string escaped string enclosed in '
			*/
			function quote($string) {
				return "'" . mysql_real_escape_string($string, $this->link) . "'";
			}

			/** Select database
			* @param string
			* @return bool
			*/
			function select_db($database) {
				return mysql_select_db($database, $this->link);
			}

			/** Send query
			* @param string
			* @param bool
			* @return mixed bool or Result
			*/
			function query($query, $unbuffered = false) {
				$result = @($unbuffered ? mysql_unbuffered_query($query, $this->link) : mysql_query($query, $this->link)); // @ - mute mysql.trace_mode
				$this->error = "";
				if (!$result) {
					$this->errno = mysql_errno($this->link);
					$this->error = mysql_error($this->link);
					return false;
				}
				if ($result === true) {
					$this->affected_rows = mysql_affected_rows($this->link);
					$this->info = mysql_info($this->link);
					return true;
				}
				return new Result($result);
			}

			/** Send query with more resultsets
			* @param string
			* @return bool
			*/
			function multi_query($query) {
				return $this->result = $this->query($query);
			}

			/** Get current resultset
			* @return Result
			*/
			function store_result() {
				return $this->result;
			}

			/** Fetch next resultset
			* @return bool
			*/
			function next_result() {
				// MySQL extension doesn't support multiple results
				return false;
			}

			/** Get single field from result
			* @param string
			* @param int
			* @return string
			*/
			function result($query, $field = 0) {
				$result = $this->query($query);
				return ($result ? $result->fetch_column($field) : false);
			}
		}

		class Result {
			public $num_rows; ///< @var int number of rows in the result
			private $result, $offset = 0;

			/** Constructor
			* @param resource
			*/
			function __construct($result) {
				$this->result = $result;
				$this->num_rows = mysql_num_rows($result);
			}

			/** Fetch next row as associative array
			* @return string[]
			*/
			function fetch_assoc() {
				return mysql_fetch_assoc($this->result);
			}

			/** Fetch next row as numbered array
			* @return list<string>
			*/
			function fetch_row() {
				return mysql_fetch_row($this->result);
			}

			/** Fetch a single column
			* @param int
			* @return string or false if there are no rows
			*/
			function fetch_column($field) {
				return ($this->num_rows ? mysql_result($this->result, 0, $field) : false);
			}

			/** Fetch next field
			* @return object properties: name, type (0 number, 15 varchar, 254 char), charsetnr (63 binary); optionally: table, orgtable, orgname
			*/
			function fetch_field() {
				$return = mysql_fetch_field($this->result, $this->offset++); // offset required under certain conditions
				$return->orgtable = $return->table;
				$return->charsetnr = ($return->blob ? 63 : 0);
				return $return;
			}

			/** Free result set
			*/
			function __destruct() {
				mysql_free_result($this->result);
			}
		}

	} elseif (extension_loaded("pdo_mysql")) {
		class Db extends PdoDb {
			public $extension = "PDO_MySQL";

			function connect($server, $username, $password) {
				global $adminer;
				$options = array(\PDO::MYSQL_ATTR_LOCAL_INFILE => false);
				$ssl = $adminer->connectSsl();
				if ($ssl) {
					if ($ssl['key']) {
						$options[\PDO::MYSQL_ATTR_SSL_KEY] = $ssl['key'];
					}
					if ($ssl['cert']) {
						$options[\PDO::MYSQL_ATTR_SSL_CERT] = $ssl['cert'];
					}
					if ($ssl['ca']) {
						$options[\PDO::MYSQL_ATTR_SSL_CA] = $ssl['ca'];
					}
					if (isset($ssl['verify'])) {
						$options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $ssl['verify'];
					}
				}
				$this->dsn(
					"mysql:charset=utf8;host=" . str_replace(":", ";unix_socket=", preg_replace('~:(\d)~', ';port=\1', $server)),
					$username,
					$password,
					$options
				);
				return true;
			}

			function set_charset($charset) {
				$this->query("SET NAMES $charset"); // charset in DSN is ignored before PHP 5.3.6
			}

			function select_db($database) {
				// database selection is separated from the connection so dbname in DSN can't be used
				return $this->query("USE " . idf_escape($database));
			}

			function query($query, $unbuffered = false) {
				$this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, !$unbuffered);
				return parent::query($query, $unbuffered);
			}
		}

	}



	class Driver extends SqlDriver {
		static $possibleDrivers = array("MySQLi", "MySQL", "PDO_MySQL");
		static $jush = "sql"; ///< @var string JUSH identifier

		public $unsigned = array("unsigned", "zerofill", "unsigned zerofill");
		public $operators = array("=", "<", ">", "<=", ">=", "!=", "LIKE", "LIKE %%", "REGEXP", "IN", "FIND_IN_SET", "IS NULL", "NOT LIKE", "NOT REGEXP", "NOT IN", "IS NOT NULL", "SQL");
		public $functions = array("char_length", "date", "from_unixtime", "lower", "round", "floor", "ceil", "sec_to_time", "time_to_sec", "upper");
		public $grouping = array("avg", "count", "count distinct", "group_concat", "max", "min", "sum");

		function __construct($connection) {
			parent::__construct($connection);
			$this->types = array(
				lang('Numbers') => array("tinyint" => 3, "smallint" => 5, "mediumint" => 8, "int" => 10, "bigint" => 20, "decimal" => 66, "float" => 12, "double" => 21),
				lang('Date and time') => array("date" => 10, "datetime" => 19, "timestamp" => 19, "time" => 10, "year" => 4),
				lang('Strings') => array("char" => 255, "varchar" => 65535, "tinytext" => 255, "text" => 65535, "mediumtext" => 16777215, "longtext" => 4294967295),
				lang('Lists') => array("enum" => 65535, "set" => 64),
				lang('Binary') => array("bit" => 20, "binary" => 255, "varbinary" => 65535, "tinyblob" => 255, "blob" => 65535, "mediumblob" => 16777215, "longblob" => 4294967295),
				lang('Geometry') => array("geometry" => 0, "point" => 0, "linestring" => 0, "polygon" => 0, "multipoint" => 0, "multilinestring" => 0, "multipolygon" => 0, "geometrycollection" => 0),
			);
			$this->editFunctions = array(
				array(
					"char" => "md5/sha1/password/encrypt/uuid",
					"binary" => "md5/sha1",
					"date|time" => "now",
				), array(
					number_type() => "+/-",
					"date" => "+ interval/- interval",
					"time" => "addtime/subtime",
					"char|text" => "concat",
				)
			);
			if (min_version('5.7.8', 10.2, $connection)) {
				$this->types[lang('Strings')]["json"] = 4294967295;
			}
			if (min_version('', 10.7, $connection)) {
				$this->types[lang('Strings')]["uuid"] = 128;
				$this->editFunctions[0]['uuid'] = 'uuid';
			}
			if (min_version(9, '', $connection)) {
				$this->types[lang('Numbers')]["vector"] = 16383;
				$this->editFunctions[0]['vector'] = 'string_to_vector';
			}
			if (min_version(5.7, 10.2, $connection)) {
				$this->generated = array("STORED", "VIRTUAL");
			}
		}

		function unconvertFunction($field) {
			return (preg_match("~binary~", $field["type"]) ? "<code class='jush-sql'>UNHEX</code>"
				: ($field["type"] == "bit" ? doc_link(array('sql' => 'bit-value-literals.html'), "<code>b''</code>")
				: (preg_match("~geometry|point|linestring|polygon~", $field["type"]) ? "<code class='jush-sql'>GeomFromText</code>"
				: "")));
		}

		function insert($table, $set) {
			return ($set ? parent::insert($table, $set) : queries("INSERT INTO " . table($table) . " ()\nVALUES ()"));
		}

		function insertUpdate($table, $rows, $primary) {
			$columns = array_keys(reset($rows));
			$prefix = "INSERT INTO " . table($table) . " (" . implode(", ", $columns) . ") VALUES\n";
			$values = array();
			foreach ($columns as $key) {
				$values[$key] = "$key = VALUES($key)";
			}
			$suffix = "\nON DUPLICATE KEY UPDATE " . implode(", ", $values);
			$values = array();
			$length = 0;
			foreach ($rows as $set) {
				$value = "(" . implode(", ", $set) . ")";
				if ($values && (strlen($prefix) + $length + strlen($value) + strlen($suffix) > 1e6)) { // 1e6 - default max_allowed_packet
					if (!queries($prefix . implode(",\n", $values) . $suffix)) {
						return false;
					}
					$values = array();
					$length = 0;
				}
				$values[] = $value;
				$length += strlen($value) + 2; // 2 - strlen(",\n")
			}
			return queries($prefix . implode(",\n", $values) . $suffix);
		}

		function slowQuery($query, $timeout) {
			if (min_version('5.7.8', '10.1.2')) {
				if ($this->conn->flavor == 'maria') {
					return "SET STATEMENT max_statement_time=$timeout FOR $query";
				} elseif (preg_match('~^(SELECT\b)(.+)~is', $query, $match)) {
					return "$match[1] /*+ MAX_EXECUTION_TIME(" . ($timeout * 1000) . ") */ $match[2]";
				}
			}
		}

		function convertSearch($idf, $val, $field) {
			return (preg_match('~char|text|enum|set~', $field["type"]) && !preg_match("~^utf8~", $field["collation"]) && preg_match('~[\x80-\xFF]~', $val['val'])
				? "CONVERT($idf USING " . charset($this->conn) . ")"
				: $idf
			);
		}

		function warnings() {
			$result = $this->conn->query("SHOW WARNINGS");
			if ($result && $result->num_rows) {
				ob_start();
				select($result); // select() usually needs to print a big table progressively
				return ob_get_clean();
			}
		}

		function tableHelp($name, $is_view = false) {
			$maria = ($this->conn->flavor == 'maria');
			if (information_schema(DB)) {
				return strtolower("information-schema-" . ($maria ? "$name-table/" : str_replace("_", "-", $name) . "-table.html"));
			}
			if (DB == "mysql") {
				return ($maria ? "mysql$name-table/" : "system-schema.html"); //! more precise link
			}
		}

		function hasCStyleEscapes() {
			static $c_style;
			if ($c_style === null) {
				$sql_mode = $this->conn->result("SHOW VARIABLES LIKE 'sql_mode'", 1);
				$c_style = (strpos($sql_mode, 'NO_BACKSLASH_ESCAPES') === false);
			}
			return $c_style;
		}

		function engines() {
			$return = array();
			foreach (get_rows("SHOW ENGINES") as $row) {
				if (preg_match("~YES|DEFAULT~", $row["Support"])) {
					$return[] = $row["Engine"];
				}
			}
			return $return;
		}
	}



	/** Escape database identifier
	* @param string
	* @return string
	*/
	function idf_escape($idf) {
		return "`" . str_replace("`", "``", $idf) . "`";
	}

	/** Get escaped table name
	* @param string
	* @return string
	*/
	function table($idf) {
		return idf_escape($idf);
	}

	/** Connect to the database
	* @param array{string, string, string} [$server, $username, $password]
	* @return mixed Db or string for error
	*/
	function connect($credentials) {
		global $drivers;
		$connection = new Db;
		if ($connection->connect($credentials[0], $credentials[1], $credentials[2])) {
			$connection->set_charset(charset($connection));
			$connection->query("SET sql_quote_show_create = 1, autocommit = 1");
			$connection->flavor = (preg_match('~MariaDB~', $connection->server_info) ? 'maria' : 'mysql');
			$drivers[DRIVER] = ($connection->flavor == 'maria' ? "MariaDB" : "MySQL");
			return $connection;
		}
		$return = $connection->error;
		if (function_exists('iconv') && !is_utf8($return) && strlen($s = iconv("windows-1250", "utf-8", $return)) > strlen($return)) { // windows-1250 - most common Windows encoding
			$return = $s;
		}
		return $return;
	}

	/** Get cached list of databases
	* @param bool
	* @return list<string>
	*/
	function get_databases($flush) {
		// SHOW DATABASES can take a very long time so it is cached
		$return = get_session("dbs");
		if ($return === null) {
			$query = "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA ORDER BY SCHEMA_NAME"; // SHOW DATABASES can be disabled by skip_show_database
			$return = ($flush ? slow_query($query) : get_vals($query));
			restart_session();
			set_session("dbs", $return);
			stop_session();
		}
		return $return;
	}

	/** Formulate SQL query with limit
	* @param string everything after SELECT
	* @param string including WHERE
	* @param int
	* @param int
	* @param string
	* @return string
	*/
	function limit($query, $where, $limit, $offset = 0, $separator = " ") {
		return " $query$where" . ($limit !== null ? $separator . "LIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
	}

	/** Formulate SQL modification query with limit 1
	* @param string
	* @param string everything after UPDATE or DELETE
	* @param string
	* @param string
	* @return string
	*/
	function limit1($table, $query, $where, $separator = "\n") {
		return limit($query, $where, 1, 0, $separator);
	}

	/** Get database collation
	* @param string
	* @param list<string>[] result of collations()
	* @return string
	*/
	function db_collation($db, $collations) {
		$return = null;
		$create = get_val("SHOW CREATE DATABASE " . idf_escape($db), 1);
		if (preg_match('~ COLLATE ([^ ]+)~', $create, $match)) {
			$return = $match[1];
		} elseif (preg_match('~ CHARACTER SET ([^ ]+)~', $create, $match)) {
			// default collation
			$return = $collations[$match[1]][-1];
		}
		return $return;
	}

	/** Get logged user
	* @return string
	*/
	function logged_user() {
		return get_val("SELECT USER()");
	}

	/** Get tables list
	* @return string[] [$name => $type]
	*/
	function tables_list() {
		return get_key_vals("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
	}

	/** Count tables in all databases
	* @param list<string>
	* @return int[] [$db => $tables]
	*/
	function count_tables($databases) {
		$return = array();
		foreach ($databases as $db) {
			$return[$db] = count(get_vals("SHOW TABLES IN " . idf_escape($db)));
		}
		return $return;
	}

	/** Get table status
	* @param string
	* @param bool return only "Name", "Engine" and "Comment" fields
	* @return TableStatus[]
	* @phpstan-type TableStatus array{Name:string, Engine:string, Comment:string, Oid:int, Rows:int, Collation:string, Auto_increment:int, Data_length:int, Index_length:int, Data_free:int}
	*/
	function table_status($name = "", $fast = false) {
		$return = array();
		foreach (
			get_rows(
				$fast
				? "SELECT TABLE_NAME AS Name, ENGINE AS Engine, TABLE_COMMENT AS Comment FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() " . ($name != "" ? "AND TABLE_NAME = " . q($name) : "ORDER BY Name")
				: "SHOW TABLE STATUS" . ($name != "" ? " LIKE " . q(addcslashes($name, "%_\\")) : "")
			) as $row
		) {
			if ($row["Engine"] == "InnoDB") {
				// ignore internal comment, unnecessary since MySQL 5.1.21
				$row["Comment"] = preg_replace('~(?:(.+); )?InnoDB free: .*~', '\1', $row["Comment"]);
			}
			if (!isset($row["Engine"])) {
				$row["Comment"] = "";
			}
			if ($name != "") {
				// MariaDB: Table name is returned as lowercase on macOS, so we fix it here.
				$row["Name"] = $name;
			}
			$return[$row["Name"]] = $row;
		}
		return $return;
	}

	/** Find out whether the identifier is view
	* @param TableStatus
	* @return bool
	*/
	function is_view($table_status) {
		return $table_status["Engine"] === null;
	}

	/** Check if table supports foreign keys
	* @param TableStatus result of table_status1()
	* @return bool
	*/
	function fk_support($table_status) {
		return preg_match('~InnoDB|IBMDB2I' . (min_version(5.6) ? '|NDB' : '') . '~i', $table_status["Engine"]);
	}

	/** Get information about fields
	* @param string
	* @return Field[]
	* @phpstan-type Field array{field:string, full_type:string, type:string, length:int, unsigned:string, default:string, null:bool, auto_increment:bool, on_update:string, collation:string, privileges:int[], comment:string, primary:bool, generated:string}
	*/
	function fields($table) {
		global $connection;
		$maria = ($connection->flavor == 'maria');
		$return = array();
		foreach (get_rows("SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . q($table) . " ORDER BY ORDINAL_POSITION") as $row) {
			$field = $row["COLUMN_NAME"];
			$type = $row["COLUMN_TYPE"];
			$generation = $row["GENERATION_EXPRESSION"];
			$extra = $row["EXTRA"];
			// https://mariadb.com/kb/en/library/show-columns/, https://github.com/vrana/adminer/pull/359#pullrequestreview-276677186
			preg_match('~^(VIRTUAL|PERSISTENT|STORED)~', $extra, $generated);
			preg_match('~^([^( ]+)(?:\((.+)\))?( unsigned)?( zerofill)?$~', $type, $match_type);
			$default = $row["COLUMN_DEFAULT"];
			if ($default != "") {
				$is_text = preg_match('~text|json~', $match_type[1]);
				if (!$maria && $is_text) {
					// default value a'b of text column is stored as _utf8mb4\'a\\\'b\' in MySQL
					$default = preg_replace("~^(_\w+)?('.*')$~", '\2', stripslashes($default));
				}
				if ($maria || $is_text) {
					$default = ($default == "NULL" ? null : preg_replace_callback("~^'(.*)'$~", function ($match) {
						return stripslashes(str_replace("''", "'", $match[1]));
					}, $default));
				}
				if (!$maria && preg_match('~binary~', $match_type[1]) && preg_match('~^0x(\w*)$~', $default, $match)) {
					$default = pack("H*", $match[1]);
				}
			}
			$return[$field] = array(
				"field" => $field,
				"full_type" => $type,
				"type" => $match_type[1],
				"length" => $match_type[2],
				"unsigned" => ltrim($match_type[3] . $match_type[4]),
				"default" => ($generated
					? ($maria ? $generation : stripslashes($generation))
					: $default
				),
				"null" => ($row["IS_NULLABLE"] == "YES"),
				"auto_increment" => ($extra == "auto_increment"),
				"on_update" => (preg_match('~\bon update (\w+)~i', $extra, $match) ? $match[1] : ""), //! available since MySQL 5.1.23
				"collation" => $row["COLLATION_NAME"],
				"privileges" => array_flip(explode(",", "$row[PRIVILEGES],where,order")),
				"comment" => $row["COLUMN_COMMENT"],
				"primary" => ($row["COLUMN_KEY"] == "PRI"),
				"generated" => ($generated[1] == "PERSISTENT" ? "STORED" : $generated[1]),
			);
		}
		return $return;
	}

	/** Get table indexes
	* @param string
	* @param string Db to use
	* @return Index[]
	* @phpstan-type Index array{type:string, columns:list<string>, lengths:list<int>, descs:list<bool>}
	*/
	function indexes($table, $connection2 = null) {
		$return = array();
		foreach (get_rows("SHOW INDEX FROM " . table($table), $connection2) as $row) {
			$name = $row["Key_name"];
			$return[$name]["type"] = ($name == "PRIMARY" ? "PRIMARY" : ($row["Index_type"] == "FULLTEXT" ? "FULLTEXT" : ($row["Non_unique"] ? ($row["Index_type"] == "SPATIAL" ? "SPATIAL" : "INDEX") : "UNIQUE")));
			$return[$name]["columns"][] = $row["Column_name"];
			$return[$name]["lengths"][] = ($row["Index_type"] == "SPATIAL" ? null : $row["Sub_part"]);
			$return[$name]["descs"][] = null;
		}
		return $return;
	}

	/** Get foreign keys in table
	* @param string
	* @return ForeignKey[]
	* @phpstan-type ForeignKey array{db:string, ns:string, table:string, source:list<string>, target:list<string>, on_delete:string, on_update:string}
	*/
	function foreign_keys($table) {
		global $driver;
		static $pattern = '(?:`(?:[^`]|``)+`|"(?:[^"]|"")+")';
		$return = array();
		$create_table = get_val("SHOW CREATE TABLE " . table($table), 1);
		if ($create_table) {
			preg_match_all(
				"~CONSTRAINT ($pattern) FOREIGN KEY ?\\(((?:$pattern,? ?)+)\\) REFERENCES ($pattern)(?:\\.($pattern))? \\(((?:$pattern,? ?)+)\\)(?: ON DELETE ($driver->onActions))?(?: ON UPDATE ($driver->onActions))?~",
				$create_table,
				$matches,
				PREG_SET_ORDER
			);
			foreach ($matches as $match) {
				preg_match_all("~$pattern~", $match[2], $source);
				preg_match_all("~$pattern~", $match[5], $target);
				$return[idf_unescape($match[1])] = array(
					"db" => idf_unescape($match[4] != "" ? $match[3] : $match[4]),
					"table" => idf_unescape($match[4] != "" ? $match[4] : $match[3]),
					"source" => array_map('Adminer\idf_unescape', $source[0]),
					"target" => array_map('Adminer\idf_unescape', $target[0]),
					"on_delete" => ($match[6] ?: "RESTRICT"),
					"on_update" => ($match[7] ?: "RESTRICT"),
				);
			}
		}
		return $return;
	}

	/** Get view SELECT
	* @param string
	* @return array{select:string}
	*/
	function view($name) {
		return array("select" => preg_replace('~^(?:[^`]|`[^`]*`)*\s+AS\s+~isU', '', get_val("SHOW CREATE VIEW " . table($name), 1)));
	}

	/** Get sorted grouped list of collations
	* @return list<string>[]
	*/
	function collations() {
		$return = array();
		foreach (get_rows("SHOW COLLATION") as $row) {
			if ($row["Default"]) {
				$return[$row["Charset"]][-1] = $row["Collation"];
			} else {
				$return[$row["Charset"]][] = $row["Collation"];
			}
		}
		ksort($return);
		foreach ($return as $key => $val) {
			sort($return[$key]);
		}
		return $return;
	}

	/** Find out if database is information_schema
	* @param string
	* @return bool
	*/
	function information_schema($db) {
		return ($db == "information_schema")
			|| (min_version(5.5) && $db == "performance_schema");
	}

	/** Get escaped error message
	* @return string
	*/
	function error() {
		global $connection;
		return h(preg_replace('~^You have an error.*syntax to use~U', "Syntax error", $connection->error));
	}

	/** Create database
	* @param string
	* @param string
	* @return string
	*/
	function create_database($db, $collation) {
		return queries("CREATE DATABASE " . idf_escape($db) . ($collation ? " COLLATE " . q($collation) : ""));
	}

	/** Drop databases
	* @param list<string>
	* @return bool
	*/
	function drop_databases($databases) {
		$return = apply_queries("DROP DATABASE", $databases, 'Adminer\idf_escape');
		restart_session();
		set_session("dbs", null);
		return $return;
	}

	/** Rename database from DB
	* @param string new name
	* @param string
	* @return bool
	*/
	function rename_database($name, $collation) {
		$return = false;
		if (create_database($name, $collation)) {
			$tables = array();
			$views = array();
			foreach (tables_list() as $table => $type) {
				if ($type == 'VIEW') {
					$views[] = $table;
				} else {
					$tables[] = $table;
				}
			}
			$return = (!$tables && !$views) || move_tables($tables, $views, $name);
			drop_databases($return ? array(DB) : array());
		}
		return $return;
	}

	/** Generate modifier for auto increment column
	* @return string
	*/
	function auto_increment() {
		$auto_increment_index = " PRIMARY KEY";
		// don't overwrite primary key by auto_increment
		if ($_GET["create"] != "" && $_POST["auto_increment_col"]) {
			foreach (indexes($_GET["create"]) as $index) {
				if (in_array($_POST["fields"][$_POST["auto_increment_col"]]["orig"], $index["columns"], true)) {
					$auto_increment_index = "";
					break;
				}
				if ($index["type"] == "PRIMARY") {
					$auto_increment_index = " UNIQUE";
				}
			}
		}
		return " AUTO_INCREMENT$auto_increment_index";
	}

	/** Run commands to create or alter table
	* @param string "" to create
	* @param string new name
	* @param list<array{string, list<string>, string}> of [$orig, $process_field, $after]
	* @param list<string>
	* @param string
	* @param string
	* @param string
	* @param string number
	* @param string
	* @return bool
	*/
	function alter_table($table, $name, $fields, $foreign, $comment, $engine, $collation, $auto_increment, $partitioning) {
		global $connection;
		$alter = array();
		foreach ($fields as $field) {
			if ($field[1]) {
				$default = $field[1][3];
				if (preg_match('~ GENERATED~', $default)) {
					// swap default and null
					$field[1][3] = ($connection->flavor == 'maria' ? "" : $field[1][2]); // MariaDB doesn't support NULL on virtual columns
					$field[1][2] = $default;
				}
				$alter[] = ($table != "" ? ($field[0] != "" ? "CHANGE " . idf_escape($field[0]) : "ADD") : " ") . " " . implode($field[1]) . ($table != "" ? $field[2] : "");
			} else {
				$alter[] = "DROP " . idf_escape($field[0]);
			}
		}
		$alter = array_merge($alter, $foreign);
		$status = ($comment !== null ? " COMMENT=" . q($comment) : "")
			. ($engine ? " ENGINE=" . q($engine) : "")
			. ($collation ? " COLLATE " . q($collation) : "")
			. ($auto_increment != "" ? " AUTO_INCREMENT=$auto_increment" : "")
		;
		if ($table == "") {
			return queries("CREATE TABLE " . table($name) . " (\n" . implode(",\n", $alter) . "\n)$status$partitioning");
		}
		if ($table != $name) {
			$alter[] = "RENAME TO " . table($name);
		}
		if ($status) {
			$alter[] = ltrim($status);
		}
		return ($alter || $partitioning ? queries("ALTER TABLE " . table($table) . "\n" . implode(",\n", $alter) . $partitioning) : true);
	}

	/** Run commands to alter indexes
	* @param string escaped table name
	* @param array{string, string, 'DROP'|list<string>} of ["index type", "name", ["column definition", ...]] or ["index type", "name", "DROP"]
	* @return bool
	*/
	function alter_indexes($table, $alter) {
		foreach ($alter as $key => $val) {
			$alter[$key] = ($val[2] == "DROP"
				? "\nDROP INDEX " . idf_escape($val[1])
				: "\nADD $val[0] " . ($val[0] == "PRIMARY" ? "KEY " : "") . ($val[1] != "" ? idf_escape($val[1]) . " " : "") . "(" . implode(", ", $val[2]) . ")"
			);
		}
		return queries("ALTER TABLE " . table($table) . implode(",", $alter));
	}

	/** Run commands to truncate tables
	* @param list<string>
	* @return bool
	*/
	function truncate_tables($tables) {
		return apply_queries("TRUNCATE TABLE", $tables);
	}

	/** Drop views
	* @param list<string>
	* @return bool
	*/
	function drop_views($views) {
		return queries("DROP VIEW " . implode(", ", array_map('Adminer\table', $views)));
	}

	/** Drop tables
	* @param list<string>
	* @return bool
	*/
	function drop_tables($tables) {
		return queries("DROP TABLE " . implode(", ", array_map('Adminer\table', $tables)));
	}

	/** Move tables to other schema
	* @param list<string>
	* @param list<string>
	* @param string
	* @return bool
	*/
	function move_tables($tables, $views, $target) {
		global $connection;
		$rename = array();
		foreach ($tables as $table) {
			$rename[] = table($table) . " TO " . idf_escape($target) . "." . table($table);
		}
		if (!$rename || queries("RENAME TABLE " . implode(", ", $rename))) {
			$definitions = array();
			foreach ($views as $table) {
				$definitions[table($table)] = view($table);
			}
			$connection->select_db($target);
			$db = idf_escape(DB);
			foreach ($definitions as $name => $view) {
				if (!queries("CREATE VIEW $name AS " . str_replace(" $db.", " ", $view["select"])) || !queries("DROP VIEW $db.$name")) {
					return false;
				}
			}
			return true;
		}
		//! move triggers
		return false;
	}

	/** Copy tables to other schema
	* @param list<string>
	* @param list<string>
	* @param string
	* @return bool
	*/
	function copy_tables($tables, $views, $target) {
		queries("SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
		foreach ($tables as $table) {
			$name = ($target == DB ? table("copy_$table") : idf_escape($target) . "." . table($table));
			if (
				($_POST["overwrite"] && !queries("\nDROP TABLE IF EXISTS $name"))
				|| !queries("CREATE TABLE $name LIKE " . table($table))
				|| !queries("INSERT INTO $name SELECT * FROM " . table($table))
			) {
				return false;
			}
			foreach (get_rows("SHOW TRIGGERS LIKE " . q(addcslashes($table, "%_\\"))) as $row) {
				$trigger = $row["Trigger"];
				if (!queries("CREATE TRIGGER " . ($target == DB ? idf_escape("copy_$trigger") : idf_escape($target) . "." . idf_escape($trigger)) . " $row[Timing] $row[Event] ON $name FOR EACH ROW\n$row[Statement];")) {
					return false;
				}
			}
		}
		foreach ($views as $table) {
			$name = ($target == DB ? table("copy_$table") : idf_escape($target) . "." . table($table));
			$view = view($table);
			if (
				($_POST["overwrite"] && !queries("DROP VIEW IF EXISTS $name"))
				|| !queries("CREATE VIEW $name AS $view[select]") //! USE to avoid db.table
			) {
				return false;
			}
		}
		return true;
	}

	/** Get information about trigger
	* @param string trigger name
	* @return Trigger
	* @phpstan-type Trigger array{Trigger:string, Timing:string, Event:string, Of:string, Type:string, Statement:string}
	*/
	function trigger($name) {
		if ($name == "") {
			return array();
		}
		$rows = get_rows("SHOW TRIGGERS WHERE `Trigger` = " . q($name));
		return reset($rows);
	}

	/** Get defined triggers
	* @param string
	* @return array{string, string}[]
	*/
	function triggers($table) {
		$return = array();
		foreach (get_rows("SHOW TRIGGERS LIKE " . q(addcslashes($table, "%_\\"))) as $row) {
			$return[$row["Trigger"]] = array($row["Timing"], $row["Event"]);
		}
		return $return;
	}

	/** Get trigger options
	* @return array{Timing: list<string>, Event: list<string>, Type: list<string>}
	*/
	function trigger_options() {
		return array(
			"Timing" => array("BEFORE", "AFTER"),
			"Event" => array("INSERT", "UPDATE", "DELETE"),
			"Type" => array("FOR EACH ROW"),
		);
	}

	/** Get information about stored routine
	* @param string
	* @param string "FUNCTION" or "PROCEDURE"
	* @return Routine
	* @phpstan-type Routine array{fields:list<array{field:string, type:string, length:string, unsigned:string, null:bool, full_type:string, inout:string, collation:string}>, comment:string, returns:array, definition:string, language:string}
	*/
	function routine($name, $type) {
		global $driver;
		$aliases = array("bool", "boolean", "integer", "double precision", "real", "dec", "numeric", "fixed", "national char", "national varchar");
		$space = "(?:\\s|/\\*[\s\S]*?\\*/|(?:#|-- )[^\n]*\n?|--\r?\n)";
		$enum = $driver->enumLength;
		$type_pattern = "((" . implode("|", array_merge(array_keys($driver->types()), $aliases)) . ")\\b(?:\\s*\\(((?:[^'\")]|$enum)++)\\))?"
			. "\\s*(zerofill\\s*)?(unsigned(?:\\s+zerofill)?)?)(?:\\s*(?:CHARSET|CHARACTER\\s+SET)\\s*['\"]?([^'\"\\s,]+)['\"]?)?";
		$pattern = "$space*(" . ($type == "FUNCTION" ? "" : $driver->inout) . ")?\\s*(?:`((?:[^`]|``)*)`\\s*|\\b(\\S+)\\s+)$type_pattern";
		$create = get_val("SHOW CREATE $type " . idf_escape($name), 2);
		preg_match("~\\(((?:$pattern\\s*,?)*)\\)\\s*" . ($type == "FUNCTION" ? "RETURNS\\s+$type_pattern\\s+" : "") . "(.*)~is", $create, $match);
		$fields = array();
		preg_match_all("~$pattern\\s*,?~is", $match[1], $matches, PREG_SET_ORDER);
		foreach ($matches as $param) {
			$fields[] = array(
				"field" => str_replace("``", "`", $param[2]) . $param[3],
				"type" => strtolower($param[5]),
				"length" => preg_replace_callback("~$enum~s", 'Adminer\normalize_enum', $param[6]),
				"unsigned" => strtolower(preg_replace('~\s+~', ' ', trim("$param[8] $param[7]"))),
				"null" => 1,
				"full_type" => $param[4],
				"inout" => strtoupper($param[1]),
				"collation" => strtolower($param[9]),
			);
		}
		return array(
			"fields" => $fields,
			"comment" => get_val("SELECT ROUTINE_COMMENT FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = " . q($name)),
		) + ($type != "FUNCTION" ? array("definition" => $match[11]) : array(
			"returns" => array("type" => $match[12], "length" => $match[13], "unsigned" => $match[15], "collation" => $match[16]),
			"definition" => $match[17],
			"language" => "SQL", // available in information_schema.ROUTINES.BODY_STYLE
		));
	}

	/** Get list of routines
	* @return list<string[]> ["SPECIFIC_NAME" => , "ROUTINE_NAME" => , "ROUTINE_TYPE" => , "DTD_IDENTIFIER" => ]
	*/
	function routines() {
		return get_rows("SELECT ROUTINE_NAME AS SPECIFIC_NAME, ROUTINE_NAME, ROUTINE_TYPE, DTD_IDENTIFIER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()");
	}

	/** Get list of available routine languages
	* @return list<string>
	*/
	function routine_languages() {
		return array(); // "SQL" not required
	}

	/** Get routine signature
	* @param string
	* @param Routine result of routine()
	* @return string
	*/
	function routine_id($name, $row) {
		return idf_escape($name);
	}

	/** Get last auto increment ID
	* @param Result or true
	* @return string
	*/
	function last_id($result) {
		return get_val("SELECT LAST_INSERT_ID()"); // mysql_insert_id() truncates bigint
	}

	/** Explain select
	* @param Db
	* @param string
	* @return Result
	*/
	function explain($connection, $query) {
		return $connection->query("EXPLAIN " . (min_version(5.1) && !min_version(5.7) ? "PARTITIONS " : "") . $query);
	}

	/** Get approximate number of rows
	* @param TableStatus
	* @param list<string>
	* @return int or null if approximate number can't be retrieved
	*/
	function found_rows($table_status, $where) {
		return ($where || $table_status["Engine"] != "InnoDB" ? null : $table_status["Rows"]);
	}

	/* Not used is MySQL but checked in compile.php:
	/** Get user defined types
	* @return string[] [$id => $name]
	function types() {
		return array();
	}

	/** Get values of user defined type
	* @param int
	* @return string
	function type_values($id) {
		return "";
	}

	/** Get existing schemas
	* @return list<string>
	function schemas() {
		return array();
	}

	/** Get current schema
	* @return string
	function get_schema() {
		return "";
	}

	/** Set current schema
	* @param string
	* @param Db
	* @return bool
	function set_schema($schema, $connection2 = null) {
		return true;
	}
	*/

	/** Get SQL command to create table
	* @param string
	* @param bool
	* @param string
	* @return string
	*/
	function create_sql($table, $auto_increment, $style) {
		$return = get_val("SHOW CREATE TABLE " . table($table), 1);
		if (!$auto_increment) {
			$return = preg_replace('~ AUTO_INCREMENT=\d+~', '', $return); //! skip comments
		}
		return $return;
	}

	/** Get SQL command to truncate table
	* @param string
	* @return string
	*/
	function truncate_sql($table) {
		return "TRUNCATE " . table($table);
	}

	/** Get SQL command to change database
	* @param string
	* @return string
	*/
	function use_sql($database) {
		return "USE " . idf_escape($database);
	}

	/** Get SQL commands to create triggers
	* @param string
	* @return string
	*/
	function trigger_sql($table) {
		$return = "";
		foreach (get_rows("SHOW TRIGGERS LIKE " . q(addcslashes($table, "%_\\")), null, "-- ") as $row) {
			$return .= "\nCREATE TRIGGER " . idf_escape($row["Trigger"]) . " $row[Timing] $row[Event] ON " . table($row["Table"]) . " FOR EACH ROW\n$row[Statement];;\n";
		}
		return $return;
	}

	/** Get server variables
	* @return list<string[]> [[$name, $value]]
	*/
	function show_variables() {
		return get_rows("SHOW VARIABLES");
	}

	/** Get status variables
	* @return list<string[]> [[$name, $value]]
	*/
	function show_status() {
		return get_rows("SHOW STATUS");
	}

	/** Get process list
	* @return list<string[]> [$row]
	*/
	function process_list() {
		return get_rows("SHOW FULL PROCESSLIST");
	}

	/** Convert field in select and edit
	* @param Field one element from fields()
	* @return string
	*/
	function convert_field($field) {
		if (preg_match("~binary~", $field["type"])) {
			return "HEX(" . idf_escape($field["field"]) . ")";
		}
		if ($field["type"] == "bit") {
			return "BIN(" . idf_escape($field["field"]) . " + 0)"; // + 0 is required outside MySQLnd
		}
		if (preg_match("~geometry|point|linestring|polygon~", $field["type"])) {
			return (min_version(8) ? "ST_" : "") . "AsWKT(" . idf_escape($field["field"]) . ")";
		}
	}

	/** Convert value in edit after applying functions back
	* @param Field one element from fields()
	* @param string SQL expression
	* @return string
	*/
	function unconvert_field($field, $return) {
		if (preg_match("~binary~", $field["type"])) {
			$return = "UNHEX($return)";
		}
		if ($field["type"] == "bit") {
			$return = "CONVERT(b$return, UNSIGNED)";
		}
		if (preg_match("~geometry|point|linestring|polygon~", $field["type"])) {
			$prefix = (min_version(8) ? "ST_" : "");
			$return = $prefix . "GeomFromText($return, $prefix" . "SRID($field[field]))";
		}
		return $return;
	}

	/** Check whether a feature is supported
	* @param string "check|comment|copy|database|descidx|drop_col|dump|event|indexes|kill|materializedview|partitioning|privileges|procedure|processlist|routine|scheme|sequence|status|table|trigger|type|variables|view|view_trigger"
	* @return bool
	*/
	function support($feature) {
		return !preg_match("~scheme|sequence|type|view_trigger|materializedview" . (min_version(8) ? "" : "|descidx" . (min_version(5.1) ? "" : "|event|partitioning")) . (min_version('8.0.16', '10.2.1') ? "" : "|check") . "~", $feature);
	}

	/** Kill a process
	* @param int
	* @return bool
	*/
	function kill_process($val) {
		return queries("KILL " . number($val));
	}

	/** Return query to get connection ID
	* @return string
	*/
	function connection_id() {
		return "SELECT CONNECTION_ID()";
	}

	/** Get maximum number of connections
	* @return int
	*/
	function max_connections() {
		return get_val("SELECT @@max_connections");
	}
}
