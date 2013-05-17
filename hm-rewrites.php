<?php

/*
Plugin Name: HM Rewrite
Version: 1.0
Author: Human Made Limited
Author URI: http://hmn.md/
*/


class HM_Rewrite {

	static private $instance;

	private static $rules = array();
	public static $matched_rule;

	/**
	 * Add a rewrite rule, this should be called on `init`
	 *
	 * @param HM_Rewrite_Rule $rule [description]
	 */
	public static function add_rule( HM_Rewrite_Rule $rule ) {
		self::$rules[] = $rule;
	}

	/**
	 * Get all the rewrite rules added
	 *
	 * @return HM_Rewrite_Rule[]
	 */
	public static function get_rules() {
		return self::$rules;
	}

	/**
	 * Remove a rule
	 *
	 * @param  string $id the identifier for the rule (regex or id if specific in the rule)
	 */
	public static function remove_rule( $id ) {

		$rules = &self::$rules;
		array_walk( self::$rules, function( $rule, $key ) use ( $id, &$rules ) {
			if ( $rule->id == $id )
				unset( $rules[$key] );
		} );

	}

	/**
	 * Get the rewrite rule for a given id (regex or id if specificed in the rule)
	 *
	 * @param  string $id
	 * @return HM_Rewrite_Rule
	 */
	public static function get_rule( $id ) {

		$rule = array_filter( self::$rules, function( HM_Rewrite_Rule $rule ) use ( $id ) {
			return $rule->id == $id;
		} );

		return reset( $rule );

	}

	/**
	 * Called when a regex is matched on page load, will call the rewrite rule responsible for it (if any)
	 *
	 * @param  string $regex
	 */
	public static function matched_regex( $regex ) {

		array_walk( self::$rules, function( HM_Rewrite_Rule $rule ) use ( $regex ) {
			if ( $rule->get_regex() == $regex ) {
				HM_Rewrite::$matched_rule = $rule;
				$rule->matched_rule();
			}
		} );
	}

	/**
	 * Do a response to a request
	 *
	 * @param string  $status         'success', 'error', or a custom status
	 * @param string  $message        Message to include with response (optional)
	 * @param int     $status_header  HTTP status header (optional)
	 */
	public static function do_json_response( $status, $message = '', $status_header = false ) {

		if ( ! $status_header ) {
			if ( 'success' == $status )
				$status_header = 200;
			else
				$status_header = 405;
		}

		header( 'Content-Type: application/json' );
		status_header( (int)$status_header );
		echo json_encode( array( 'status' => $status, 'message' => $message ) );
		exit;
	}

}

class HM_Rewrite_Rule {

	public $id = '';
	public $regex = '';
	public $query_args = '';
	public $request_callbacks = array();
	public $query_callbacks = array();
	public $title_callbacks = array();
	public $parse_query_callbacks = array();
	public $body_class_callbacks = array();
	public $admin_bar_callbacks = array();
	public $template = '';
	public $access_rule = '';
	public $request_methods = array();
	public $disable_canonical = false;

	public function __construct( $regex, $id = null ) {

		$this->regex = $regex;
		$this->id = $id ? $id : $regex;
	}

	/**
	 * Get the regex for the rewrite rule
	 *
	 * @return string
	 */
	public function get_regex() {

		if ( substr( $this->regex, 0, 1 ) !== substr( $this->regex, strlen( $this->regex ) - 1 ) )
			return $this->regex;

		return $this->regex;
	}

	public function set_wp_query_args( $vars ) {
		if ( ! is_string( $vars ) )
			throw new Exception( 'set_wp_query_args currently only supports accepting a string.' );

		$this->query_args = $vars;
	}

	/**
	 * Get the WP_Query args for this rule
	 * @return array
	 */
	public function get_wp_query_args() {
		return $this->query_args;
	}

	public function get_public_query_var_exports() {

		return array_keys( wp_parse_args( $this->get_wp_query_args() ) );
	}

	/**
	 * Called when this rule is matched for the page load
	 *
	 */
	public function matched_rule() {

		global $wp;

		// check request methods match
		if ( $this->request_methods && ! in_array( strtolower( $_SERVER['REQUEST_METHOD'] ), $this->request_methods ) ) {
			HM_Rewrite::do_json_response( 'error', 'Invalid request method', 403 );
		}

		do_action( 'hm_parse_request_' . $this->get_regex(), $wp );

		foreach ( $this->request_callbacks as $callback )
			call_user_func_array( $callback, array( $wp ) );

		$t = $this;

		// set up the hooks for everything
		add_action( 'template_redirect', function( $template ) use ( $t ) {

			global $wp_query;

			// check permissions
			$permission = $t->access_rule;
			$redirect = '';

			switch ( $permission ) {

				case 'logged_out_only' :

					$redirect = is_user_logged_in();

				break;

				case 'logged_in_only' :

					$redirect = ! is_user_logged_in();

				break;

				case 'displayed_user_only' :
					$redirect = ! is_user_logged_in() || get_query_var( 'author' ) != get_current_user_id();

				break;
			}

			if ( $redirect ) {

				$redirect = home_url( '/' );

				// If there is a "redirect_to" redirect there
				if ( ! empty( $_REQUEST['redirect_to'] ) )
					$redirect = hm_parse_redirect( urldecode( esc_url( $_REQUEST['redirect_to'] ) ) );

				wp_redirect( $redirect );

				exit;
			}

			foreach ( $t->query_callbacks as $callback )
				call_user_func_array( $callback, array( $wp_query ) );

			

			if ( $t->template ) {

				$template = $t->template;

				if ( ! file_exists( $template ) )
					$template = get_stylesheet_directory() . '/' . $t->template;

				if ( ! file_exists( $template ) )
					$template = get_template_directory() . '/' . $t->template;

				include( $template );
				exit;
			}
		});

		add_filter( 'parse_query', $closure = function( WP_Query $query ) use ( $t, &$closure ) {

			// only run this hook once
			remove_filter( 'parse_query', $closure );

			foreach ( $t->parse_query_callbacks as $callback )
				call_user_func_array( $callback, array( $query ) );
		} );

		add_filter( 'redirect_canonical', function( $redirect_to ) use ( $t ) {
			if ( $t->disable_canonical )
				return null;

			return $redirect_to;
		});

		add_filter( 'body_class', function( $classes ) use ( $t ) {

			foreach ( $t->body_class_callbacks as $callback )
				$classes = call_user_func_array( $callback, array( $classes ) );

			return $classes;
		} );

		add_filter( 'wp_title', function( $title, $sep = '' ) use ( $t ) {

			foreach ( $t->title_callbacks as $callback )
				$title = call_user_func_array( $callback, array( $title, $sep ) );

			return $title;

		}, 10, 2 );

		add_action( 'admin_bar_menu', function() use ( $t ) {
			global $wp_admin_bar;

			foreach ( $t->admin_bar_callbacks as $callback )
				$title = call_user_func_array( $callback, array( $wp_admin_bar ) );
		} );
	}

	/**
	 * Set the template file to render this request
	 *
	 * @param string $template
	 */
	public function set_template( $template ) {

		$this->template = $template;
	}

	public function set_access_rule( $rule ) {
		$this->access_rule = $rule;
	}

	/**
	 * Add a callback for when the request is mached
	 *
	 * The callback will be called with the WP object
	 *
	 * @param function $callback
	 */
	public function add_request_callback( $callback ) {
		$this->request_callbacks[] = $callback;
	}

	/**
	 * Add a callback for when the WP_Query's parse_query is fired
	 *
	 * The callback will be called with the WP_Query object, before the query is done, after parse_args
	 *
	 * @param function $callback
	 */
	public function add_parse_query_callback( $callback ) {
		$this->parse_query_callbacks[] = $callback;
	}

	/**
	 * Add a callback for when the WP_Query is being set up
	 *
	 * The callback will be called with the WP_Query object, after the query is done, after parse_args
	 *
	 * @param function $callback
	 */
	public function add_query_callback( $callback ) {
		$this->query_callbacks[] = $callback;
	}

	/**
	 * Add a callback for when the wp_title hook is fired
	 *
	 * The callback will be called with the title of the page
	 *
	 * @param function $callback
	 */
	public function add_title_callback( $callback ) {
		$this->title_callbacks[] = $callback;
	}

	/**
	 * Add a callback for when the body_class hook is fired
	 *
	 * The callback will be called with the classes added so far
	 *
	 * @param function $callback
	 */
	public function add_body_class_callback( $callback ) {
		$this->body_class_callbacks[] = $callback;
	}

	/**
	 * Add a callback for the admin_bar hooks fire
	 *
	 * @param function $callback
	 */
	public function add_admin_bar_callback( $callback ) {
		$this->admin_bar_callbacks[] = $callback;
	}

	/**
	 * Set the request methods, e.g. PUT/POST
	 */
	public function set_request_methods( $methods ) {
		$this->request_methods = array_map( 'strtolower', $methods );
	}
}

/**
 * The main wrapper function for the HM_Rewrite API. Use this to add new rewrite rules
 *
 * Create a new rewrite with the arguments listed below. The only required argument is 'regex'
 *
 * @param  string 		$regex 		The rewrite regex, start / end delimter not required. Eg: '^people/([^/]+)/?'
 * @param  mixed 		$query 		The WP_Query args to be used on this "page"
 * @param  string 		$template 	The template file used to render the request. Not required, will use
 *                             		the template file for the WP_Query if not set. Relative to template_directory() or absolute.
 * @param  string 		$body_class A class to be added to body_class in the rendered template
 * @param  function 	$body_class_callback A callback that will be hooked into body_class
 * @param  function 	$request_callback A callback that will be called as soon as the request matching teh regex is hit.
 *                                     		called with the WP object
 * @param  function 	$parse_query_callback A callback taht will be hooked into 'parse_query'. Use this to modify query vars
 *                                         	  in the main WP_Query
 * @param  function 	$title_callback A callback that will be hooked into wp_title
 * @param  function 	$query_callback A callback taht will be called once the WP_Query has finished. Use to overrite any
 *                                   	annoying is_404, is_home etc that you custom query may not match to.
 * @param  function 	$access_rule An access rule for restriciton to logged-in-users only for example.
 * @param  array 		$request_methods An array of request methods, e.g. PUT, POST
 */
function hm_add_rewrite_rule( $args = array() ) {

	// backwards compat
	if ( count( func_get_args() ) > 1 && is_string( $args ) ) {
		$args = array();
		$args['regex'] = func_get_arg( 0 );
		$args['query'] = func_get_arg( 1 );

		if ( count( func_get_args() ) > 2 )
			$args['template'] = func_get_arg( 2 );

		if ( count( func_get_args() ) > 3 )
			$args = array_merge( $args, func_get_arg( 3 ) );
	}

	if ( ! empty( $args['rewrite'] ) )
		$args['regex'] = $args['rewrite'];

	$regex = $args['regex'];

	$rule = new HM_Rewrite_Rule( $regex, isset( $args['id'] ) ? $args['id'] : null );

	if ( ! empty( $args['template'] ) )
		$rule->set_template( $args['template'] );

	if ( ! empty( $args['body_class_callback'] ) )
		$rule->add_body_class_callback( $args['body_class_callback'] );

	if ( ! empty( $args['request_callback'] ) )
		$rule->add_request_callback( $args['request_callback'] );

	if ( ! empty( $args['parse_query_callback'] ) )
		$rule->add_parse_query_callback( $args['parse_query_callback'] );

	if ( ! empty( $args['title_callback'] ) )
		$rule->add_title_callback( $args['title_callback'] );

	if ( ! empty( $args['query_callback'] ) )
		$rule->add_query_callback( $args['query_callback'] );

	if ( ! empty( $args['access_rule'] ) )
		$rule->set_access_rule( $args['access_rule'] );

	if ( ! empty( $args['query'] ) )
		$rule->set_wp_query_args( $args['query'] );

	if ( ! empty( $args['permission'] ) )
		$rule->set_access_rule( $args['permission'] );

	if ( ! empty( $args['admin_bar_callback'] ) )
		$rule->add_admin_bar_callback( $args['admin_bar_callback'] );

	if ( ! empty( $args['disable_canonical'] ) )
		$rule->disable_canonical = true;

	// some convenenience properties. These are done here because they are not very nice
	if ( ! empty( $args['body_class'] ) )
		$rule->add_body_class_callback( function( $classes ) use ( $args ) {
			$classes[] = $args['body_class'];
			return $classes;
		} );

	if ( ! empty( $args['parse_query_properties'] ) )
		$rule->add_parse_query_callback( function( WP_Query $query ) use ( $args ) {
			$args['parse_query_properties'] = wp_parse_args( $args['parse_query_properties'] );
			foreach ( $args['parse_query_properties'] as $property => $value )
				$query->$property = $value;

		} );

	if ( ! empty( $args['post_query_properties'] ) )
		$rule->add_query_callback( function( WP_Query $query ) use ( $args ) {
			$args['post_query_properties'] = wp_parse_args( $args['post_query_properties'] );
			foreach ( $args['post_query_properties'] as $property => $value )
				$query->$property = $value;

		} );

	if ( ! empty( $args['request_methods'] ) ) {
		$rule->set_request_methods( $args['request_methods'] );
	}

	if ( ! empty( $args['request_method'] ) ) {
		$rule->set_request_methods( array( $args['request_method'] ) );
	}

	HM_Rewrite::add_rule( $rule );
}


/**
 * Add the custom rewrite rules to the main
 * rewrite rules array
 *
 * @param array $rules
 * @return array $rules
 */
add_filter( 'rewrite_rules_array', function( $rules ) {

	$new_rules = array();

	foreach ( HM_Rewrite::get_rules() as $rule )
		$new_rules[$rule->get_regex()] = $rule->get_wp_query_args();

	return array_merge( $new_rules, $rules );

} );

/**
 * Set the current rewrite rule
 *
 * @param object $request
 * @return null
 */
add_filter( 'parse_request', function( WP $request ) {
	
	$matched_regex = $request->matched_rule;

	HM_Rewrite::matched_regex( $matched_regex );

} );

/**
 * Add custom query vars from all rewrite rules automatically
 */
add_filter( 'query_vars', function( $query_vars ) {

	$new_vars = array();

	foreach ( HM_Rewrite::get_rules() as $rule )
		$query_vars = array_merge( $rule->get_public_query_var_exports(), $query_vars );


	return $query_vars;

} );