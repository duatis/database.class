<?php
	/**
	 * @author Jaume Duatis <jaume@duatis.com>
	 * Manage database conections and querys
	 */
	class Database
	{
		var $lnk;
		private  $lastId;
		private  $postgre;
		private  $schema;

		/**
		 * Constructor
		 * @param string  $host     [required] Database host
		 * @param string  $user     [required] Database User
		 * @param string  $pwd      [required] Database user's password
		 * @param string  $database [required] Database name
		 * @param boolean $postgre  [Default: false] Werther satabase server is postgre or not.
		 * @param string  $port     Database connection port
		 */
		function __construct( $host, $user, $pwd, $database, $postgre=false, $port="" )
		{
			$this->schema 	= $database;
			$this->postgre 	= $postgre;
			
			if(!$postgre)
			{
				$this->lnk = mysqli_connect($host,$user, $pwd) or die(mysqli_error($this->lnk));
				mysqli_select_db( $this->lnk,$database);
				mysqli_set_charset($this->lnk,"utf8");
			}else
			{
				$this->lnk = pg_connect("host=$host port=$port dbname=$database user=$user password=$pwd") or die("Postgre: Connection Error");
				pg_query($this->lnk, "set client_encoding to 'utf8'");
			}
		}
		
		/**
		 * Executes a query
		 * @param  string $query [Required] Well formatted sql query.
		 * @return mysqlResult   The query result     
		 */
		function query($query)
		{
			if(!$this->postgre)
			{
				$result = mysqli_query($this->lnk,$query) or die(mysqli_error($this->lnk).": ".$query);
			}else
			{
				$result = pg_query($this->lnk, $query." RETURNING id") or die(pg_last_error().": ".$query);
				$insert_row = pg_fetch_row($result);
				$this->lastId = $insert_row[0];

			}

			return $result;
		}
		
		/**
		 * Executes a query and returns an array with the resutls fetched as array or as objects.
		 * @param  string  $query  [Required] Well formatted sql query.
		 * @param  boolean $object [Default: false] Werther results should be fetched as object or not.
		 * @return array           The results of the query.
		 */
		function getRows($query, $object = false)
		{
			$ret = array();

			if(!$this->postgre)
			{
				$res = $this->query( $query );//mysqli_query($this->lnk,$query) or die(mysqli_error($this->lnk).": ".$query);
				if(mysqli_affected_rows($this->lnk)>0)
				{
					while($row = mysqli_fetch_assoc($res))
					{
						$ret[] = ( $object )? self::toObject( $row ):$row;
					}
				}
			}else
			{
				$res = pg_query($this->lnk, $query) or die(pg_last_error());
				if(pg_num_rows($res)>0)
				{
					while($row = pg_fetch_assoc($res))
					{
						$ret[] = ( $object )? self::toObject( $row ):$row;
					}
				}
			}

			return $ret;
		}

		/**
		 * Executes a query and returns the result as json string.
		 * @param  string $query [Required] Well formatted sql query.
		 * @return string        JSON string with the results.
		 */
		function queryJSON($query){
			return json_encode($this->getRows($query));
		}

		/**
		 * [Executes a query and returns a single result as json string.
		 * @param  string $query [Required] Well formatted sql query.
		 * @return string        JSON string with the results.
		 */
		function queryJSONObject($query){
			$res = $this->getRows($query);
			return json_encode( $res[0] );
		}
		
		/**
		 * @return int Inserted id from last query.
		 */
		function lastId()
		{
			if($this->postgre)
			{
				return $this->lastId;
			}
			return mysqli_insert_id($this->lnk);
		}
		
		function escape($string)
		{
			if($this->postgre)
			{
				return pg_escape_string($string);
			}else
			{
				return  mysqli_escape_string($string);
			}
		}

		/**
		 * Converts a row result into an object.
		 * @param  array $row [Required] A result row.
		 * @return object     Object containing the values as attributes.
		 */
		static function toObject( $row )
		{
			$obj = new stdClass();
			foreach ($row as $key => $value) {
				$obj->{$key} = $value;
			}
			return $obj;
		}

		static function scape($string)
		{
			return addslashes( $string );
		}

		/**
		 * Retrieves all the rows from a table.
		 * @param  strig  $table  The table to be retrieved.
		 * @param  boolean $object Werther function should return objects or  arrays.
		 * @return array 	Array containing the results.
		 */
		function all( $table, $object = false ){
			$query = sprintf("SELECT * FROM %s", $table);
			return $res = $this->getRows( $query, $object );
		}

		/**
		 * Returns a single row from table. If the row doesn't exists returns false.
		 * @param  string  $table  Table name.
		 * @param  int  $id     The id of the row.
		 * @param  boolean $object Werther function should return an object or an array.
		 * @return array/object/false          The object found.
		 */
		function find( $table, $id, $object = false )
		{
			$query = sprintf("SELECT * FROM %s WHERE id=%d", $table, $id);
			$res = $this->getRows( $query, $object );
			if( $res ){
				return $res[0];
			}
			else
			{
				return $res;
			}
		}


		/**
		 * Retrieves values from a table.
		 * @param  string  $table  [Required] The table to query.
		 * @param  array   $and    [Default: empty array] Array with exclusive (AND) filters with the field name as array key and the value to compare, is value is array first term is the opernat and second the value to compare.
		 * @param  array   $or     [Default: empty array] Array with inclusive (OR)  filters with the field name as array key and the value to compare, is value is array first term is the opernat and second the value to compare.
		 * @param  boolean $object [Default false] Werther results should be fetched as object or not.
		 * @return array           Query results.
		 */
		function where( $table, $and = array(), $or = array(), $object = false )
		{

			$where = "";
			$whereand = "";
			$conj = "";
			foreach ($and as $key => $value) {

				$where = "WHERE";
				if( is_array($value) ){
					$op 	= $value[0];
					$values = $value[1];
				}else
				{
					$op = "=";
				}

				$whereand .= sprintf(" %s %s%s'%s'", $conj, $key, $op, $value);
				$conj = "AND";
			}

			$conj = ( "AND" == $conj )? "OR":"";

			$whereor = "";
			foreach ($or as $key => $value) {
				$where = "WHERE";
				if( is_array($value) ){
					$op 	= $value[0];
					$values = $value[1];
				}else
				{
					$op = "=";
				}

				$whereor .= sprintf(" %s %s%s'%s'", $conj, $key, $op, $value);
				$conj = "OR";
			}

			$query = sprintf("SELECT * FROM %s %s %s %s", $table, $where, $whereand, $whereor);

			$res = $this->getRows( $query, $object );
			if( sizeof( $res ) > 0 ){
				return $res;
			}
			else
			{
				return false;
			}
		}

		/**
		 * Save a row to a table
		 * @param  string $table  The table name to save the row.
		 * @param  array $values  Array with field name as key and value as value.
		 * @return void
		 */
		function save( $table, $values ){
			$conj = $fields = $vals = "";
			foreach ($values as $key => $value) {
				$fields .= sprintf(" %s%s", $conj, $key	);
				$vals 	.= sprintf(" %s'%s'", $conj, self::scape( $value ) );
				$conj = ",";
			}

			$query = sprintf("INSERT INTO %s (%s) VALUES (%s)", $table, $fields, $vals);
			$this->query( $query );
		}

		/**
		 * Update a row in a table
		 * @param  [type] $table  [description]
		 * @param  int $id     The id of the row to update.
		 * @param  string $table  The table name to save the row.
		 * @param  array $values  Array with field name as key and value as value.
		 * @return void
		 */
		function update( $table, $id, $values ){
			$conj = $sets = "";
			foreach ($values as $key => $value) {
				$sets .= sprintf(" %s %s='%s'", $conj, $key, self::scape( $value ) );
				$conj = ",";
			}

			$query = sprintf("UPDATE %s SET %s WHERE id=%s", $table, $sets, $id);

			$this->query( $query );
		}

		/**
		 * Truncate table
		 * @param  string $table The table to truncate.
		 * @return void    
		 */
		function truncate( $table ){
			$this->query( sprintf("TRUNCATE TABLE %s", $table) );
		}
	}
?>