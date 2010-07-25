<?php
// Utilities
if( !class_exists( 'WP_User_Search' ) ) include_once(ABSPATH . 'wp-admin/includes/user.php');

/**
 * This class is an extension of the WP_User_Search and allows for more advanced user searches.
 * The search is constructed by passing in an associative array with a bunch of arguments.
 * It's nowhere near complete, but will be added to slowly.
 * 
 * At least one of the following is required:
 * 
 * 'search_term' => 'string'
 *	- Does a LIKE search across common user fields 
 *
 * 'search_fields' => array(
 *						'field' => string,
 *						'field' => array( 'string', 'string' )
 *						)
 *		- Searches for the values through the specified fields (supported fields: 'ID', 'user_login', 'user_nicename', 'user_email', 'user_url', 'display_name'
 *
 * 'role' => 'string'
 * 		- Returns all users belonging to the specified role
 *
 * 'usermeta' => array(
 *					'field' => string,
 *					'field => array( 'string', 'string' )
 *					)
 *		- Does a search based on specified usermeta. Can look for a single value, or multiple values, or multiple metas with multiple values
 *
 * The following are optional:
 * 'return_fields' => 'string' || array('string', 'string') || 'all'
 *		- the fields that should be returned with get_results. 'all' returns all columns. A single column name returns a single column results. An array with multiple fields returns the columns specified. On single columns work for now.
 * 'page' => int
 *		- what page to start searching from
 *
 * 'orderby' => string
 * 		- ordering for the query return; defaults to ID
 *
 */
class EF_User_Query extends WP_User_Search {

	var $query_vars;

	function EF_User_Query ( $query_vars = array() ) {

		if ( empty($query_vars) || !isset($query_vars) ) {
			return new WP_Error('ef_user_query-empty-args', __('C\'mon! You need to pass at least some arguments for the query!'));
		}
		
		$this->query_vars = $query_vars;
		
		$this->parse_query_vars();
		$this->prepare_query();
		$this->query();
		
		$this->prepare_vars_for_template_usage();
		$this->do_paging();
	}

	function parse_query_vars ( ) {
		
		$qv = &$this->query_vars;
		
		// Keyword search
		$this->search_term = trim( $qv['search_term'] );
		
		// Field search
		$this->search_fields = $this->scrub_search_fields( $qv['search_fields'] );
		
		// Paging
		$this->raw_page = ( '' == $qv['page'] ) ? false : (int) $qv['page'];
		$this->page = (int) ( '' == $qv['page'] ) ? 1 : $qv['page'];
		$this->first_user = ($this->page - 1) * $this->users_per_page;

		// Fields returned by search
		if( !$qv['return_fields'] ) 
			$this->return_fields = 'ID';
		else
			$this->return_fields = $qv['return_fields'];
/* TODO: enable multiple field/all select
		else if ( $qv['return_fields'] == 'all' )
			$this->return_fields = $wpdb->users . '.*';
		else
			$this->return_fields = $this->scrub_return_fields($qv['return_fields']);
*/

		// Role
		$this->role = trim($qv['role']);
		
		// Sort
		$this->orderby = ($orderby) ? $orderby : 'ID';	

		// TODO: if not array, convert it to one
		$this->usermetas = $qv['usermeta'];

	}
	
	function prepare_query( ) {
		global $wpdb;
		
		// Set SELECT
		// TODO: Allow multiple fields, all fields
		$this->query_select = 'SELECT '. esc_sql($this->return_fields);
				
		// Set table
		$this->query_from = " FROM $wpdb->users";
		
		// Set up Limit counts
		if($users_count) $this->query_limit = $wpdb->prepare(" LIMIT %d, %d", $this->first_user, $this->users_per_page);
		
		// Order By
		$this->query_orderby = ' ORDER BY '. $this->orderby;
		
		$query_where_searches = array();
		
		// LIKE search or field search
		$search_sql = '';
		if ( $this->search_term ) {
			$searches = array();
			//$search_sql = ' AND (';
			
			foreach ( array('user_login', 'user_nicename', 'user_email', 'user_url', 'display_name') as $col ) {
				$searches[] = $col . " LIKE '%$this->search_term%'";
			}
			
			$search_sql .= '('.implode(' OR ', $searches) .')';
			$query_where_searches[] = $search_sql;
		} else if ( is_array($this->search_fields) && !empty($this->search_fields) ) {
		
			$searches = array();
			//$search_sql = ' AND (';
			
			foreach ( $this->search_fields as $field => $value ) {
				if( is_array($value) ) {
					$value = array_map('esc_sql', $value);
					$value = implode('\',\'', $value);
					$searches[] = $wpdb->prepare( "$field IN ('$value')" );
				} else {
					$value = esc_sql($value);
					$searches[] = $wpdb->prepare( "$field = %s", $value );
				}
			}
			
			$search_sql .= '('. implode(' OR ', $searches) .')';
			$query_where_searches[] = $search_sql;
		}
				
		
		// Search by roles
		if ( $this->role ) {
			// TODO: allow search by multiple roles
			$this->join_usermeta = true;
			
			$query_where_searches[] = $wpdb->prepare("$wpdb->usermeta.meta_key = '{$wpdb->prefix}capabilities' AND $wpdb->usermeta.meta_value LIKE %s", '%' . $this->role . '%');
			
			// Below version does exact string searches but doesn't use prepare :(
			//$this->role = esc_sql($this->role);
			//$this->query_where .= " AND $wpdb->usermeta.meta_key = '{$wpdb->prefix}capabilities' AND $wpdb->usermeta.meta_value LIKE '%\"$this->role\"%'";
		} 
		
		// usermeta
		if( is_array($this->usermetas) && !empty($this->usermetas) ) {

			$this->join_usermeta = true;
			$metas = array();
			
			foreach( $this->usermetas as $key => $value ) {

				$meta = $wpdb->prepare( " ($wpdb->usermeta.meta_key = %s", $key );

				if( is_array($value) ) {
					$value = array_map( 'esc_sql', $value );
					
					$values = implode('\',\'', $value);

					$meta .= " AND $wpdb->usermeta.meta_value IN ('$values') )";
				} else {
					if( $value )
						$meta .= $wpdb->prepare(" AND $wpdb->usermeta.meta_value = %s)", $value);
				}
				$metas[] = $meta;
			}
			
			$query_where_searches[] = '('. implode(' OR ', $metas) .')';
		}
		
		// If we don't have any where clauses, kill the query
		if( count( $query_where_searches ) == 0 )
			$this->query_where = " WHERE 0=1";	
		else
			$this->query_where = " WHERE 1=1 AND (". implode(' OR ', $query_where_searches) .")";
		$this->query_join .= ($this->join_usermeta) ? " INNER JOIN $wpdb->usermeta ON $wpdb->users.ID = $wpdb->usermeta.user_id" : "";

	}
	
	function query() {
		global $wpdb;
		$this->full_query = $this->query_select . $this->query_from . $this->query_join . $this->query_where . $this->query_orderby . $this->query_limit;
		// TODO: change this to get_results, when supporting multiple fields, all fields
		$this->results = $wpdb->get_col($this->full_query);
		
		if ( $this->results )
			$this->total_users_for_query = $wpdb->get_var('SELECT COUNT(ID) ' . $this->query_from_where); // no limit
		else
			$this->search_errors = new WP_Error('no_matching_users_found', __('No matching users were found!'));
    }
	
	function get_results( $fill_user = false ) {
		if( $fill_user ) {
			$this->users = array();
			foreach( (array) $this->results as $user ) {
				$this->users[] = _fill_user($user);
			}
			return $this->users;
		} else {
			return (array) $this->results;
		}
	}
	
	/*
	function scrub_return_fields( $fields ) {
		// TODO: Build and use this when enabling mutltiple fields query
		if( !is_array($fields) ) {
			if( empty($fields) ) return array();
			$fields = explode(',', $fields);
		}
		
		$scrubbed_fields = array();
		$allowed_fields = array(
			'ID',
			'user_login',
			'user_nicename',
			'user_email',
			'user_url',
			'display_name'
		);
		

		foreach( $fields as $field ) {
			if( !in_array($field, $allowed_fields) ) continue;
			if(!empty($field))
				$scrubbed_fields[] = $field;
		}
		
		return $scrubbed_fields;
	}
	*/
	function scrub_search_fields( $fields ) {
		if( !is_array($fields) ) {
			if( empty($fields) ) return array();
			$fields = explode(',', $fields);
		}
		
		$scrubbed_fields = array();
		$allowed_fields = array(
			'ID',
			'user_login',
			'user_nicename',
			'user_email',
			'user_url',
			'display_name'
		);
		
		foreach( $fields as $field => $value ) {
			
			if( !in_array($field, $allowed_fields) ) continue;
			if( !empty($value) )
				$scrubbed_fields[$field] = $value;
			//TODO: allow comma-separted strings: explode then iterate
		}
		return $scrubbed_fields;
	}
}

/** Abstracted functions for compatablity with WP.com **/

/**
 * Returns the $return field for the user(s) matching the $field and $value(s)
 * @param string $field
 * @param string|array $value 
 * @param string $return The field to return 
 */
function get_users_field_by ( $field, $value, $return = 'ID' ) {
	
	$args = array( 
		'search_fields' => array($field => $value),
		'return_fields' => $return
		);
	
	// Create user query obj and get results
	$search = new EF_User_Query($args);
	$users = $search->get_results();

	if( !$users || is_wp_error($users) )
		return false;
	return $users;
}

/**
 * Returns an array of all users with the value or values matching the specified meta_key
 * @param string $meta_key 
 * @param string|array $meta_value 
 * @param string $return The field to return
 */
function ef_get_users_by_usermeta( $meta_key, $meta_value = '', $return = 'ID' ) {
	global $limit_to_blog_users;
	
	$args = array(
		'return_fields' => $return,
		'usermeta' => array( $meta_key => $meta_value )
		);
	
	// Instantiate the search class and get results
	$user_query = new EF_User_Query($args);

	return $user_query->get_results();
}

/**
 * Wrapper for get_metadata('user', ... )
 * @param int $obj_id ID of the user
 * @param string $meta_key
 * @param bool $single Return just the first entry if true, or all entries if false
 */
function ef_get_user_metadata( $obj_id, $meta_key = '', $single = false ) {
	return get_metadata( 'user', $obj_id, $meta_key, $single );
}

/**
 * Wrapper for get_metadata('user', ... )
 * @param int $obj_id ID of the user
 * @param string $meta_key
 * @param string $meta_value
 * @param bool $unique
 */
function ef_add_user_metadata( $obj_id, $meta_key, $meta_value, $unique = false ) {
	return add_metadata( 'user', $obj_id, $meta_key, $meta_value, $unique );
}

/**
 * Wrapper for update_metadata('user', ... )
 * @param int $obj_id ID of the user
 * @param string $meta_key 
 * @param string $meta_value
 * @param string $prev_value
 */
function ef_update_user_metadata( $obj_id, $meta_key, $meta_value, $prev_value = '' ) {
	return update_metadata( 'user', $obj_id, $meta_key, $meta_value, $prev_value );
}

/**
 * Wrapper for delete_metadata('user', ... )
 * @param int $obj_id ID of the user
 * @param string $meta_key 
 * @param string $meta_value 
 */
function ef_delete_user_metadata( $obj_id, $meta_key, $meta_value = '', $delete_all = false ) {
	return delete_metadata( 'user', $obj_id, $meta_key, $meta_value, $delete_all );
}

/**
 * Adds an array of capabilities to a role.
 */
function ef_add_caps_to_role( $role, $caps ) {
	global $wp_roles;
	
	if ( $wp_roles->is_role( $role ) ) {
		$role =& get_role( $role );
		foreach ( $caps as $cap )
			$role->add_cap( $cap );
	}
}

/**
 * Returns a float value representing the provided string version. e.g. 1.0.1 will be returned as 1.01.
 * This is useful for version number comparisons.
 */
function ef_version_number_float( $version ) {
	$version_numbers = explode('.', $version);
	$version_float = 0;
	for ($i = 0, $multiplier = 1; $i < count( $version_numbers ); ++$i, $multiplier /= 10)
		$version_float += $version_numbers[$i] * $multiplier;
	return $version_float;
}