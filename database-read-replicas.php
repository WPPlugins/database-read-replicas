<?php
/*
Plugin Name: Database Read Replicas
Plugin URI: http://jameslow.com/2011/10/12/database-read-replicas/
Version: 1.0.0
Description: BETA: Extend WordPress with MySQL database read replica support for greater speed and scalability
Author: James D. Low
Author URI: http://jameslow.com
*/

/*
Notes:
	- We assume same username/password/database name for the master as the read replicas
	- We assume the same charset operating on all too, so we can use just one connectino for the mysql_real_escape_string
	- We are lucky in that "select" and "set_charset" don't use the class reference to the database connection ($this->dbh)
	  but allow us to pass in a variable, this simplifies things :)
	- So we mainly need to override wpdb::query()
	  We do this by using a read replica by default ($this->dbh), but if it is a write query, then we create a new connection to the master
	- We also change wpdb::print_error() function to use the last used connection (readreplica or master)
*/

class wpdb_replicas extends wpdb {
	var $dbhost_master = '';
	var $dbh_master = null;
	var $dbh_last = null;

	function __construct($dbuser, $dbpassword, $dbname, $dbhost, $dbreplicas = null) {
		//We use dbhost by default so that we can do testing on two connections with the same database for developement/testing
		if ($replicas == null) {
			//Null
			$readreplica = $dbhost;
		} elseif (is_array($dbreplicas)) {
			if (count($dbreplicas) > 0) {
				//Array
				array_rand($dbreplicas);
			} else {
				//Empty
				$readreplica = $dbhost;
			}
		} else {
			//String
			$readreplica = $dbreplicas;
		}
		$this->dbhost_master = $dbhost;
		parent::__construct($dbuser, $dbpassword, $dbname, $readreplica);
	}
	
	function db_connect_master() {
		if ( WP_DEBUG ) {
			$this->dbh_master = mysql_connect( $this->dbhost_master, $this->dbuser, $this->dbpassword, true );
		} else {
			$this->dbh_master = @mysql_connect( $this->dbhost_master, $this->dbuser, $this->dbpassword, true );
		}

		if ( !$this->dbh_master ) {
			$this->bail( sprintf( /*WP_I18N_DB_CONN_ERROR*/"
<h1>Error establishing a database connection to the MASTER</h1>
<p>This either means that the username and password information in your <code>wp-config.php</code> file is incorrect or we can't contact the database server at <code>%s</code>. This could mean your host's database server is down.</p>
<ul>
	<li>Are you sure you have the correct username and password?</li>
	<li>Are you sure that you have typed the correct hostname?</li>
	<li>Are you sure that the database server is running?</li>
</ul>
<p>If you're unsure what these terms mean you should probably contact your host. If you still need help you can always visit the <a href='http://wordpress.org/support/'>WordPress Support Forums</a>.</p>
"/*/WP_I18N_DB_CONN_ERROR*/, $this->dbhost_master ), 'db_connect_fail' );

			return;
		}

		$this->set_charset( $this->dbh_master );

		//This ready is for the default $this->dbh
		//$this->ready = true;

		$this->select( $this->dbname, $this->dbh_master );
	}
	
	function print_error( $str = '' ) {
		global $EZSQL_ERROR;

		if ( !$str )
			$str = mysql_error( $this->dbh_last );
		$EZSQL_ERROR[] = array( 'query' => $this->last_query, 'error_str' => $str );

		if ( $this->suppress_errors )
			return false;

		if ( $caller = $this->get_caller() )
			$error_str = sprintf( /*WP_I18N_DB_QUERY_ERROR_FULL*/'WordPress database error %1$s for query %2$s made by %3$s'/*/WP_I18N_DB_QUERY_ERROR_FULL*/, $str, $this->last_query, $caller );
		else
			$error_str = sprintf( /*WP_I18N_DB_QUERY_ERROR*/'WordPress database error %1$s for query %2$s'/*/WP_I18N_DB_QUERY_ERROR*/, $str, $this->last_query );

		if ( function_exists( 'error_log' )
			&& ( $log_file = @ini_get( 'error_log' ) )
			&& ( 'syslog' == $log_file || @is_writable( $log_file ) )
			)
			@error_log( $error_str );

		// Are we showing errors?
		if ( ! $this->show_errors )
			return false;

		// If there is an error then take note of it
		if ( is_multisite() ) {
			$msg = "WordPress database error: [$str]\n{$this->last_query}\n";
			if ( defined( 'ERRORLOGFILE' ) )
				error_log( $msg, 3, ERRORLOGFILE );
			if ( defined( 'DIEONDBERROR' ) )
				wp_die( $msg );
		} else {
			$str   = htmlspecialchars( $str, ENT_QUOTES );
			$query = htmlspecialchars( $this->last_query, ENT_QUOTES );

			print "<div id='error'>
			<p class='wpdberror'><strong>WordPress database error:</strong> [$str]<br />
			<code>$query</code></p>
			</div>";
		}
	}
	
	function query( $query ) {
		if ( ! $this->ready )
			return false;

		// some queries are made before the plugins have been loaded, and thus cannot be filtered with this method
		if ( function_exists( 'apply_filters' ) )
			$query = apply_filters( 'query', $query );
		
		//Check for "select" or "set"
		$querytrim = strtoupper(ltrim($query));
		if (strpos($querytrim,'SELECT') === 0 || strpos($querytrim,'SET') === 0) {
			$this->dbh_last = $this->dbh;
		} else {
			if ($this->dbh_master == null) {
				$this->db_connect_master();
			}
			$this->dbh_last = $this->dbh_master;
		}

		$return_val = 0;
		$this->flush();

		// Log how the function was called
		$this->func_call = "\$db->query(\"$query\")";

		// Keep track of the last query for debug..
		$this->last_query = $query;

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES )
			$this->timer_start();

		$this->result = @mysql_query( $query, $this->dbh_last );
		$this->num_queries++;

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES )
			$this->queries[] = array( $query, $this->timer_stop(), $this->get_caller() );

		// If there is an error then take note of it..
		if ( $this->last_error = mysql_error( $this->dbh_last ) ) {
			$this->print_error();
			return false;
		}

		if ( preg_match( '/^\s*(create|alter|truncate|drop) /i', $query ) ) {
			$return_val = $this->result;
		} elseif ( preg_match( '/^\s*(insert|delete|update|replace) /i', $query ) ) {
			$this->rows_affected = mysql_affected_rows( $this->dbh_last );
			// Take note of the insert_id
			if ( preg_match( '/^\s*(insert|replace) /i', $query ) ) {
				$this->insert_id = mysql_insert_id($this->dbh_last);
			}
			// Return number of rows affected
			$return_val = $this->rows_affected;
		} else {
			$i = 0;
			while ( $i < @mysql_num_fields( $this->result ) ) {
				$this->col_info[$i] = @mysql_fetch_field( $this->result );
				$i++;
			}
			$num_rows = 0;
			while ( $row = @mysql_fetch_object( $this->result ) ) {
				$this->last_result[$num_rows] = $row;
				$num_rows++;
			}

			@mysql_free_result( $this->result );

			// Log number of rows the query returned
			// and return number of rows selected
			$this->num_rows = $num_rows;
			$return_val     = $num_rows;
		}

		return $return_val;
	}
}
$wpdb = new wpdb_replicas( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST, $read_replicas);
$wpdb->set_prefix($table_prefix);