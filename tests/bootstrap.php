<?php
/**
 * In-memory WordPress simulation for the migrator tests.
 *
 * Implements the small part of WordPress the exporter and importer use:
 * tables in arrays, a pattern based $wpdb, uploads in a temp folder.
 *
 * @package PuppyPressMigrator
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'PPM_DIR', dirname( __DIR__ ) . '/' );
define( 'PPM_URL', 'https://example.org/wp-content/plugins/puppypress-migrator/' );
define( 'PPM_VERSION', 'test' );
define( 'PPM_FILE', PPM_DIR . 'puppypress-migrator.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 === strpos( $class, 'PuppyPressMigrator\\' ) ) {
			require PPM_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( 'PuppyPressMigrator\\' ) ) ) . '.php';
		}
	}
);

/*
 * ---------------------------------------------------------------------
 * Site state
 * ---------------------------------------------------------------------
 */

/**
 * Creates an empty site.
 *
 * @param string $home Home URL.
 * @param array  $taxonomies Registered taxonomies.
 */
function fake_site( $home, array $taxonomies ) {
	$dir = sys_get_temp_dir() . '/ppm-test-' . md5( $home . microtime() );
	mkdir( $dir . '/uploads', 0777, true );

	$GLOBALS['site'] = array(
		'home'       => $home,
		'uploads'    => $dir . '/uploads',
		'tables'     => array(
			'posts'              => array(),
			'postmeta'           => array(),
			'terms'              => array(),
			'term_taxonomy'      => array(),
			'term_relationships' => array(),
			'termmeta'           => array(),
			'comments'           => array(),
			'commentmeta'        => array(),
		),
		'auto'       => array(),
		'options'    => array( 'sticky_posts' => array(), 'sidebars_widgets' => array() ),
		'theme_mods' => array(),
		'users'      => array( 1 => array( 'ID' => 1, 'user_login' => 'admin', 'user_email' => 'admin@' . parse_url( $home, PHP_URL_HOST ), 'display_name' => 'Admin', 'first_name' => '', 'last_name' => '', 'user_url' => '', 'roles' => array( 'administrator' ) ) ),
		'taxonomies' => $taxonomies,
		'post_types' => array( 'post', 'page', 'attachment', 'nav_menu_item', 'stud-dogs', 'puppies', 'approvals', 'breeding-license' ),
	);

	$GLOBALS['wp_registered_sidebars'] = array( 'sidebar-main' => array() );
}

function fake_id( $table ) {
	$GLOBALS['site']['auto'][ $table ] = ( isset( $GLOBALS['site']['auto'][ $table ] ) ? $GLOBALS['site']['auto'][ $table ] : 0 ) + 1;
	return $GLOBALS['site']['auto'][ $table ];
}

function &fake_table( $name ) {
	return $GLOBALS['site']['tables'][ $name ];
}

/*
 * ---------------------------------------------------------------------
 * $wpdb
 * ---------------------------------------------------------------------
 */

class Fake_WPDB {
	public $posts = 'wp_posts', $postmeta = 'wp_postmeta', $terms = 'wp_terms', $term_taxonomy = 'wp_term_taxonomy', $term_relationships = 'wp_term_relationships', $termmeta = 'wp_termmeta', $comments = 'wp_comments', $commentmeta = 'wp_commentmeta';

	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( '%s', "'%s'", $query );
		return vsprintf( $query, array_map( static function ( $a ) { return str_replace( "'", "\\'", (string) $a ); }, $args ) );
	}

	private function list_of( $sql, $pattern ) {
		if ( ! preg_match( $pattern, $sql, $m ) ) {
			throw new RuntimeException( 'Unsupported query: ' . $sql );
		}
		return array_map( static function ( $v ) { return trim( $v, " '" ); }, explode( ',', $m[1] ) );
	}

	public function get_results( $sql ) {
		$t = $GLOBALS['site']['tables'];

		if ( false !== strpos( $sql, 'FROM wp_posts WHERE' ) ) {
			$rows = array();
			foreach ( $t['posts'] as $post ) {
				if ( preg_match( "/post_type IN \(([^)]*)\) AND post_status IN \(([^)]*)\)/", $sql, $m ) ) {
					$types    = array_map( static function ( $v ) { return trim( $v, " '" ); }, explode( ',', $m[1] ) );
					$statuses = array_map( static function ( $v ) { return trim( $v, " '" ); }, explode( ',', $m[2] ) );
					$ok       = in_array( $post['post_type'], $types, true ) && in_array( $post['post_status'], $statuses, true );
				} elseif ( preg_match( "/post_type = 'attachment' AND \( ID IN \(([^)]*)\) OR post_parent IN \(([^)]*)\) \)/", $sql, $m ) ) {
					$ok = 'attachment' === $post['post_type'] && ( in_array( (string) $post['ID'], explode( ',', $m[1] ), true ) || in_array( (string) $post['post_parent'], explode( ',', $m[2] ), true ) );
				} elseif ( false !== strpos( $sql, "post_type = 'attachment'" ) ) {
					$ok = 'attachment' === $post['post_type'];
				} else {
					throw new RuntimeException( 'Unsupported query: ' . $sql );
				}
				if ( $ok ) {
					$rows[] = $post;
				}
			}
			return $rows;
		}

		if ( false !== strpos( $sql, 'FROM wp_postmeta WHERE post_id IN' ) ) {
			$ids = $this->list_of( $sql, '/post_id IN \(([^)]*)\)/' );
			return array_values( array_filter( $t['postmeta'], static function ( $r ) use ( $ids ) { return in_array( (string) $r['post_id'], $ids, true ); } ) );
		}

		if ( false !== strpos( $sql, 'FROM wp_term_relationships' ) ) {
			$ids  = $this->list_of( $sql, '/object_id IN \(([^)]*)\)/' );
			$rows = array();
			foreach ( $t['term_relationships'] as $rel ) {
				if ( in_array( (string) $rel['object_id'], $ids, true ) ) {
					$tt     = $t['term_taxonomy'][ $rel['term_taxonomy_id'] ];
					$rows[] = array( 'object_id' => $rel['object_id'], 'term_id' => $tt['term_id'], 'taxonomy' => $tt['taxonomy'] );
				}
			}
			return $rows;
		}

		if ( false !== strpos( $sql, 'FROM wp_terms t INNER JOIN' ) ) {
			$taxonomies = $this->list_of( $sql, '/tt\.taxonomy IN \(([^)]*)\)/' );
			$rows       = array();
			foreach ( $t['term_taxonomy'] as $tt ) {
				if ( in_array( $tt['taxonomy'], $taxonomies, true ) ) {
					$rows[] = $t['terms'][ $tt['term_id'] ] + $tt;
				}
			}
			usort( $rows, static function ( $a, $b ) { return $a['term_id'] - $b['term_id']; } );
			return $rows;
		}

		if ( false !== strpos( $sql, 'FROM wp_termmeta WHERE term_id IN' ) ) {
			$ids = $this->list_of( $sql, '/term_id IN \(([^)]*)\)/' );
			return array_values( array_filter( $t['termmeta'], static function ( $r ) use ( $ids ) { return in_array( (string) $r['term_id'], $ids, true ); } ) );
		}

		if ( false !== strpos( $sql, 'FROM wp_comments WHERE' ) ) {
			$ids = $this->list_of( $sql, '/comment_post_ID IN \(([^)]*)\)/' );
			return array_values( array_filter( $t['comments'], static function ( $r ) use ( $ids ) { return in_array( (string) $r['comment_post_ID'], $ids, true ) && in_array( $r['comment_approved'], array( '0', '1' ), true ); } ) );
		}

		if ( false !== strpos( $sql, 'FROM wp_commentmeta WHERE comment_id IN' ) ) {
			$ids = $this->list_of( $sql, '/comment_id IN \(([^)]*)\)/' );
			return array_values( array_filter( $t['commentmeta'], static function ( $r ) use ( $ids ) { return in_array( (string) $r['comment_id'], $ids, true ); } ) );
		}

		if ( preg_match( "/SELECT (\w+) AS new_id, meta_value FROM wp_(\w+) WHERE meta_key = '([^']+)' AND meta_value LIKE '(.*)%'/", $sql, $m ) ) {
			$prefix = stripcslashes( $m[4] );
			$rows   = array();
			foreach ( $t[ $m[2] ] as $r ) {
				if ( $m[3] === $r['meta_key'] && 0 === strpos( $r['meta_value'], $prefix ) ) {
					$rows[] = array( 'new_id' => $r[ $m[1] ], 'meta_value' => $r['meta_value'] );
				}
			}
			return $rows;
		}

		throw new RuntimeException( 'Unsupported query: ' . $sql );
	}

	public function update( $table, $data, $where ) {
		if ( 'wp_posts' !== $table ) {
			throw new RuntimeException( 'Unsupported update' );
		}
		foreach ( $data as $key => $value ) {
			$GLOBALS['site']['tables']['posts'][ $where['ID'] ][ $key ] = $value;
		}
		return 1;
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

/*
 * ---------------------------------------------------------------------
 * WordPress API subset
 * ---------------------------------------------------------------------
 */

class WP_Error {
	private $code, $message, $data;
	public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_data( $code = '' ) { return $this->code === $code || '' === $code ? $this->data : null; }
}

function __( $t ) { return $t; }
$GLOBALS['fake_filters'] = array();
function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['fake_filters'][ $hook ] ) ) {
		return call_user_func( $GLOBALS['fake_filters'][ $hook ], $value, ...$args );
	}
	return $value;
}
function add_action() {}
function add_filter( $hook, $callback ) { $GLOBALS['fake_filters'][ $hook ] = $callback; }
function remove_all_filters( $hook ) { unset( $GLOBALS['fake_filters'][ $hook ] ); }
function esc_url_raw( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags | JSON_INVALID_UTF8_SUBSTITUTE ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0777, true ); }
function wp_generate_password( $length = 12 ) { return substr( bin2hex( random_bytes( $length ) ), 0, $length ); }
function home_url() { return $GLOBALS['site']['home']; }
function site_url() { return $GLOBALS['site']['home']; }
function get_bloginfo( $what ) { return 'name' === $what ? 'Test Club' : '6.8'; }
function get_stylesheet() { return 'extra-child'; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['site']['uploads'], 'baseurl' => $GLOBALS['site']['home'] . '/wp-content/uploads' ); }
function size_format( $bytes ) { return $bytes . ' B'; }
function sanitize_file_name( $name ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_raise_memory_limit() {}
function wp_list_pluck( $list, $field ) { $out = array(); foreach ( $list as $k => $item ) { $item = (array) $item; $out[ $k ] = $item[ $field ]; } return $out; }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function kses_remove_filters() {}
function wp_defer_term_counting() {}
function wp_defer_comment_counting() {}
function clean_post_cache() {}
function get_current_user_id() { return 1; }
function get_role( $role ) { return in_array( $role, array( 'administrator', 'editor', 'author' ), true ) ? (object) array() : null; }

function is_serialized( $data ) {
	if ( ! is_string( $data ) ) { return false; }
	$data = trim( $data );
	if ( 'N;' === $data ) { return true; }
	return (bool) preg_match( '/^([adObis]):/', $data ) && in_array( substr( $data, -1 ), array( ';', '}' ), true );
}
function maybe_serialize( $data ) { return ( is_array( $data ) || is_object( $data ) || is_serialized( $data ) ) ? serialize( $data ) : $data; }

function validate_file( $file ) {
	if ( '..' === $file || false !== strpos( $file, '../' ) || false !== strpos( $file, './' ) ) { return 1; }
	if ( ':' === substr( $file, 1, 1 ) ) { return 2; }
	return 0;
}
function wp_get_mime_types() { return array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'pdf' => 'application/pdf', 'webp' => 'image/webp' ); }
function wp_check_filetype( $name, $mimes ) {
	foreach ( $mimes as $exts => $mime ) {
		if ( preg_match( '!\.(' . $exts . ')$!i', $name, $m ) ) { return array( 'ext' => strtolower( $m[1] ), 'type' => $mime ); }
	}
	return array( 'ext' => false, 'type' => false );
}

// Options and theme mods.
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['site']['options'] ) ? $GLOBALS['site']['options'][ $name ] : $default; }
function update_option( $name, $value ) { $GLOBALS['site']['options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['site']['options'][ $name ] ); return true; }
function get_theme_mod( $name, $default = false ) { return isset( $GLOBALS['site']['theme_mods'][ $name ] ) ? $GLOBALS['site']['theme_mods'][ $name ] : $default; }
function set_theme_mod( $name, $value ) { $GLOBALS['site']['theme_mods'][ $name ] = $value; }

// Users.
function get_userdata( $id ) { return isset( $GLOBALS['site']['users'][ $id ] ) ? (object) $GLOBALS['site']['users'][ $id ] : false; }
function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['site']['users'] as $u ) {
		if ( ( 'login' === $field && $u['user_login'] === $value ) || ( 'email' === $field && $u['user_email'] === $value ) ) { return (object) $u; }
	}
	return false;
}
function get_users() { return array( 1 ); }
function wp_insert_user( $data ) {
	$id = max( array_keys( $GLOBALS['site']['users'] ) ) + 1;
	$GLOBALS['site']['users'][ $id ] = array( 'ID' => $id, 'roles' => array( $data['role'] ) ) + $data;
	return $id;
}

// Types.
function get_post_types() { return array_combine( $GLOBALS['site']['post_types'], $GLOBALS['site']['post_types'] ); }
function get_taxonomies() { return array_combine( $GLOBALS['site']['taxonomies'], $GLOBALS['site']['taxonomies'] ); }
function taxonomy_exists( $t ) { return in_array( $t, $GLOBALS['site']['taxonomies'], true ); }

// Posts.
function wp_insert_post( $arr, $wp_error = false ) {
	$arr = wp_unslash( $arr );
	$id  = fake_id( 'posts' );
	$GLOBALS['site']['tables']['posts'][ $id ] = array_merge(
		array( 'post_parent' => 0, 'post_modified' => '2026-10-31 00:00:00', 'post_modified_gmt' => '2026-10-31 00:00:00', 'guid' => home_url() . '/?p=' . $id, 'post_mime_type' => '', 'post_password' => '' ),
		$arr,
		array( 'ID' => $id )
	);
	return $id;
}
function get_post_meta( $id, $key, $single = false ) {
	$values = fake_meta( $id, $key );
	return $single ? ( $values ? $values[0] : '' ) : $values;
}
function get_post( $id ) { return isset( $GLOBALS['site']['tables']['posts'][ $id ] ) ? (object) $GLOBALS['site']['tables']['posts'][ $id ] : null; }
function fake_add_meta( $table, $column, $id, $key, $value ) {
	$GLOBALS['site']['tables'][ $table ][ fake_id( $table ) ] = array( $column => $id, 'meta_key' => $key, 'meta_value' => maybe_serialize( wp_unslash( $value ) ) );
	return true;
}
function add_post_meta( $id, $key, $value ) { return fake_add_meta( 'postmeta', 'post_id', $id, $key, $value ); }
function add_term_meta( $id, $key, $value ) { return fake_add_meta( 'termmeta', 'term_id', $id, $key, $value ); }
function add_comment_meta( $id, $key, $value ) { return fake_add_meta( 'commentmeta', 'comment_id', $id, $key, $value ); }
function metadata_exists( $type, $id, $key ) {
	foreach ( $GLOBALS['site']['tables'][ $type . 'meta' ] as $r ) {
		if ( (int) $r[ $type . '_id' ] === (int) $id && $r['meta_key'] === $key ) { return true; }
	}
	return false;
}

// Terms.
function fake_term( $name, $taxonomy, $slug, $parent = 0, $description = '' ) {
	$id = fake_id( 'terms' );
	$GLOBALS['site']['tables']['terms'][ $id ]         = array( 'term_id' => $id, 'name' => $name, 'slug' => $slug, 'term_group' => 0 );
	$GLOBALS['site']['tables']['term_taxonomy'][ $id ] = array( 'term_taxonomy_id' => $id, 'term_id' => $id, 'taxonomy' => $taxonomy, 'description' => $description, 'parent' => $parent, 'count' => 0 );
	return $id;
}
function get_term_by( $field, $value, $taxonomy ) {
	foreach ( $GLOBALS['site']['tables']['term_taxonomy'] as $tt ) {
		$term = $GLOBALS['site']['tables']['terms'][ $tt['term_id'] ];
		if ( $tt['taxonomy'] === $taxonomy && $term[ $field ] === $value ) { return (object) ( $term + $tt ); }
	}
	return false;
}
function wp_insert_term( $name, $taxonomy, $args ) {
	return array( 'term_id' => fake_term( wp_unslash( $name ), $taxonomy, $args['slug'], $args['parent'], wp_unslash( $args['description'] ) ) );
}
function wp_set_object_terms( $object_id, $term_ids, $taxonomy ) {
	foreach ( $term_ids as $term_id ) {
		$GLOBALS['site']['tables']['term_relationships'][] = array( 'object_id' => $object_id, 'term_taxonomy_id' => $term_id );
	}
}
function get_terms( $args ) {
	$ids = array();
	foreach ( $GLOBALS['site']['tables']['term_taxonomy'] as $tt ) {
		if ( $tt['taxonomy'] === $args['taxonomy'] ) { $ids[] = $tt['term_taxonomy_id']; }
	}
	return $ids;
}
function wp_update_term_count_now( $tt_ids ) {
	foreach ( $tt_ids as $tt_id ) {
		$count = 0;
		foreach ( $GLOBALS['site']['tables']['term_relationships'] as $rel ) {
			$count += (int) ( $rel['term_taxonomy_id'] === $tt_id );
		}
		$GLOBALS['site']['tables']['term_taxonomy'][ $tt_id ]['count'] = $count;
	}
}

// Comments.
function wp_insert_comment( $data ) {
	$data = wp_unslash( $data );
	$id   = fake_id( 'comments' );
	$GLOBALS['site']['tables']['comments'][ $id ] = array( 'comment_ID' => $id ) + $data;
	return $id;
}

// Read helpers for assertions.
function fake_meta( $post_id, $key ) {
	$values = array();
	foreach ( $GLOBALS['site']['tables']['postmeta'] as $r ) {
		if ( (int) $r['post_id'] === (int) $post_id && $r['meta_key'] === $key ) { $values[] = $r['meta_value']; }
	}
	return $values;
}
function fake_term_meta( $term_id, $key ) {
	foreach ( $GLOBALS['site']['tables']['termmeta'] as $r ) {
		if ( (int) $r['term_id'] === (int) $term_id && $r['meta_key'] === $key ) { return $r['meta_value']; }
	}
	return null;
}
function fake_post_by_title( $title, $type = null ) {
	foreach ( $GLOBALS['site']['tables']['posts'] as $p ) {
		if ( $p['post_title'] === $title && ( null === $type || $type === $p['post_type'] ) ) { return $p; }
	}
	return null;
}
