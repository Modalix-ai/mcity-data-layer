<?php
/**
 * בדיקת עשן לקוד שכותב לוורדפרס, מול דמה של וורדפרס, WP-CLI ו-ACF בזיכרון.
 * תופסת שגיאות ריצה (משתנה לא מוגדר, שם פונקציה שגוי, לוגיקת ניסיון/כתיבה). לא תחליף ל-T2: היא לא יודעת איך ACF ווורדפרס מתנהגים באמת.
 *
 * הרצה (מתוך src/migration/loader):  php tests/loader-smoke-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CLI', true );
define( 'WPMU_PLUGIN_DIR', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

// ---------- דמה של WP-CLI ----------
class WP_CLI_Fail extends Exception {}
class WP_CLI {
	public static $log = array();
	public static function log( $m ) { self::$log[] = $m; }
	public static function warning( $m ) { self::$log[] = 'WARNING ' . $m; }
	public static function success( $m ) { self::$log[] = 'SUCCESS ' . $m; }
	public static function error( $m ) { throw new WP_CLI_Fail( $m ); }
	public static function add_command( $n, $c ) { self::$log[] = "ADD $n"; }
}
class WP_Error { public $m; function __construct( $c = '', $m = '' ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

// ---------- דמה של מסד נתונים ----------
class FakeWpdb {
	public $users = 'rca_users', $usermeta = 'rca_usermeta', $posts = 'rca_posts', $postmeta = 'rca_postmeta', $terms = 'rca_terms',
		$term_taxonomy = 'rca_term_taxonomy', $term_relationships = 'rca_term_relationships', $termmeta = 'rca_termmeta', $options = 'rca_options';
	public $queries = array();
	public $engine = 'InnoDB';
	function query( $q ) { $this->queries[] = $q; return 1; }
	function prepare( $sql, ...$a ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $a ); }
	function get_var( $q ) { return 0; }
	function get_results( $q, $t = 'OBJECT' ) {
		if ( false !== strpos( $q, 'information_schema' ) ) {
			return array_map( function ( $t ) { return array( 't' => $t, 'e' => $this->engine, 'approx_rows' => 1 ); }, array( 'rca_users', 'rca_usermeta', 'rca_posts' ) );
		}
		return array();
	}
}
$wpdb = new FakeWpdb();

// ---------- דמה של וורדפרס ----------
$GLOBALS['S'] = array( 'users' => array(), 'meta' => array(), 'next' => 100, 'filters' => array(), 'terms_rel' => array(), 'posts' => array() );
function home_url() { return 'https://mcityportal.ussl.co.il'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_sql( $s ) { return addslashes( $s ); }
function add_filter( $tag, $cb, $p = 10 ) { $GLOBALS['S']['filters'][ $tag ][] = $cb; }
function apply_filters_ref( $tag, $v ) { foreach ( $GLOBALS['S']['filters'][ $tag ] ?? array() as $cb ) { $r = $cb( $v ); if ( null !== $r ) { return $r; } } return null; }
function __return_false() { return false; }
function wp_mail( $to, $s, $m ) { $r = apply_filters_ref( 'pre_wp_mail', null ); return null !== $r ? $r : false; }
function wp_cache_flush() { $GLOBALS['S']['flushed'] = true; }
function wp_defer_term_counting( $b ) {}
function wp_generate_password( $n = 12, $s = true, $e = false ) { return str_repeat( 'x', $n ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f | JSON_UNESCAPED_UNICODE ); }
function maybe_unserialize( $v ) { return $v; }
function get_term_by( $f, $slug, $tax ) { return in_array( $slug, array( 'modiin', 'ashdod', 'north', 'beer-sheva' ), true ) ? (object) array( 'term_id' => crc32( $slug ) % 100 + 1 ) : false; }
function get_users( $a ) { foreach ( $GLOBALS['S']['meta'] as $id => $m ) { if ( ( $m[ $a['meta_key'] ] ?? null ) === $a['meta_value'] ) { return array( $id ); } } return array(); }
function wp_insert_user( $d ) {
	$S = &$GLOBALS['S'];
	if ( isset( $d['ID'] ) ) { $id = $d['ID']; $S['users'][ $id ] = array_merge( $S['users'][ $id ], $d ); return $id; }
	if ( empty( $d['user_login'] ) ) { return new WP_Error( 'x', 'empty login' ); }
	foreach ( $S['users'] as $u ) { if ( $u['user_login'] === $d['user_login'] ) { return new WP_Error( 'x', 'login exists' ); } }
	$id = $S['next']++;
	$S['users'][ $id ] = $d;
	$S['meta'][ $id ]  = array();
	return $id;
}
function update_user_meta( $id, $k, $v ) { $GLOBALS['S']['meta'][ $id ][ $k ] = $v; }
function get_user_meta( $id, $k, $single = false ) { return $GLOBALS['S']['meta'][ $id ][ $k ] ?? ''; }
function get_userdata( $id ) { $u = $GLOBALS['S']['users'][ $id ]; return (object) array_merge( array( 'user_nicename' => 'x' ), $u ); }
function update_field( $key, $value, $post ) {
	$names = array_flip( MCL_FIELD_KEYS );
	$name  = $names[ $key ] ?? null;
	if ( 'author_category' === $name ) { $name = '_author_category'; }
	$id = (int) str_replace( 'user_', '', $post );
	if ( 'mcity_cities' === $name ) { $value = array_map( 'intval', $value ); } // מדמה את הסיכון: ACF ממיר למספרים
	$GLOBALS['S']['meta'][ $id ][ $name ] = $value;
	return true;
}
function get_field( $key, $post ) { $names = array_flip( MCL_FIELD_KEYS ); return get_user_meta( (int) str_replace( 'user_', '', $post ), $names[ $key ] ?? '' ); }
function acf_get_field( $k ) { return in_array( $k, MCL_FIELD_KEYS, true ) ? array( 'key' => $k, 'name' => array_flip( MCL_FIELD_KEYS )[ $k ], 'type' => 'text' ) : false; }
function acf_get_fields( $g ) { $o = array(); for ( $i = 0; $i < 14; $i++ ) { $o[] = array( 'key' => 'f' . $i, 'required' => 1 ); } return $o; }
function acf_validate_value( $v, $f, $i ) { return '' !== $v; }
function acf_reset_validation_errors() {}
function acf_get_validation_errors() { return array(); }
function get_terms( $a ) { return array( 11, 12 ); }
function wp_get_object_terms( $id, $tax, $a = array() ) { return array( 11, 12 ); }
function wp_set_object_terms( $id, $t, $tax ) { return $t; }
function get_objects_in_term( $t, $tax ) { return array(); }
function get_post( $id ) { return null; }
function wp_insert_post( $a ) { return 9001; }
function wp_roles() { return (object) array( 'roles' => array( 'author' => array(), 'contributor' => array(), 'editor' => array() ) ); }
function wp_count_terms( $a ) { return 5; }
function taxonomy_exists( $t ) { return true; }
function count_users() { return array( 'avail_roles' => array( 'author' => 3 ) ); }
function wp_using_ext_object_cache() { return false; }

require __DIR__ . '/../mcity-loader.php';

$fail = 0; $n = 0;
function ok( $c, $m ) { global $fail, $n; $n++; if ( ! $c ) { $fail++; echo "FAIL: $m\n"; } }
function fails( $fn ) { try { $fn(); return false; } catch ( WP_CLI_Fail $e ) { return $e->getMessage(); } }

// קובץ קלט קטן: מומחה, כותב, מעגל, hold
$tmp = tempnam( sys_get_temp_dir(), 'mcl' );
$recs = array(
	array( 'key' => 'expert:1', 'kind' => 'expert', 'legacy_id' => 1, 'login' => 'mc_expert_1', 'role' => 'author', 'display_name' => 'א', 'email' => 'a@x.com', 'email_status' => 'unique', 'phone' => '0501234567', 'mc_active' => '1', 'cities' => array( 'modiin', 'nowhere' ), 'hold' => array(), 'flags' => array() ),
	array( 'key' => 'writer:1', 'kind' => 'contributor', 'legacy_id' => 1, 'login' => 'mc_writer_1', 'role' => 'contributor', 'display_name' => 'ב', 'email' => '', 'hold' => array() ),
	array( 'key' => 'expert:2', 'kind' => 'circle', 'login' => 'mc_expert_2', 'role' => 'author', 'display_name' => 'מעגל', 'hold' => array() ),
	array( 'key' => 'expert:3', 'kind' => 'expert', 'login' => 'mc_expert_3', 'role' => 'author', 'display_name' => 'ג', 'hold' => array( 'not_an_expert' ) ),
);
file_put_contents( $tmp, implode( "\n", array_map( 'json_encode', $recs ) ) . "\n" );
$cli = new MCity_Command();

// מארח שגוי או חסר
ok( false !== fails( function () use ( $cli, $tmp ) { $cli->load_users( array(), array( 'file' => $tmp ) ); } ), 'missing --allow-host stops' );
ok( false !== fails( function () use ( $cli, $tmp ) { $cli->load_users( array(), array( 'file' => $tmp, 'allow-host' => 'example.com' ) ); } ), 'wrong host stops' );

// ריצת ניסיון: ROLLBACK, והדוח נכון
$cli->load_users( array(), array( 'file' => $tmp, 'allow-host' => 'mcityportal.ussl.co.il' ) );
$q = $wpdb->queries;
ok( in_array( 'START TRANSACTION', $q, true ) && in_array( 'ROLLBACK', $q, true ) && ! in_array( 'COMMIT', $q, true ), 'dry-run rolls back' );
$report = json_decode( implode( "\n", array_filter( WP_CLI::$log, function ( $l ) { return '{' === ( $l[0] ?? '' ); } ) ), true );
ok( 2 === $report['stats']['created'] && 0 === $report['stats']['errors'], 'created 2 (expert + contributor)' );
ok( 1 === ( $report['skipped']['circle_pending_X20'] ?? 0 ) && 1 === ( $report['skipped']['hold:not_an_expert'] ?? 0 ), 'skips counted' );
ok( array( 'nowhere' => true ) === $report['city_slugs_missing'], 'missing city slug reported' );
ok( true === ( $GLOBALS['S']['flushed'] ?? false ), 'cache flushed after end' );

// מחרוזות בערים אחרי שה-ACF המיר למספרים
$eid = array_search( 'expert:1', array_column( $GLOBALS['S']['meta'], '_mc_legacy_key' ) );
$ids = array_keys( $GLOBALS['S']['meta'] );
$expert_id = null;
foreach ( $GLOBALS['S']['meta'] as $id => $m ) { if ( ( $m['_mc_legacy_key'] ?? '' ) === 'expert:1' ) { $expert_id = $id; } }
ok( null !== $expert_id && mcl_is_string_id_array( $GLOBALS['S']['meta'][ $expert_id ]['mcity_cities'] ), 'cities forced to strings when ACF casts to int' );
ok( '0501234567' === $GLOBALS['S']['meta'][ $expert_id ]['phone'], 'phone string preserved' );

// כתיבה אמיתית ואז הרצה חוזרת: אידמפוטנטי
$wpdb->queries = array();
$cli->load_users( array(), array( 'file' => $tmp, 'allow-host' => 'mcityportal.ussl.co.il', 'commit' => true ) );
ok( in_array( 'COMMIT', $wpdb->queries, true ), '--commit commits' );
$cnt = count( $GLOBALS['S']['users'] );
$cli->load_users( array(), array( 'file' => $tmp, 'allow-host' => 'mcityportal.ussl.co.il', 'commit' => true ) );
ok( count( $GLOBALS['S']['users'] ) === $cnt, 'second run creates no duplicates' );

// לא InnoDB: ניסיון מסרב
$wpdb->engine = 'MyISAM';
ok( false !== fails( function () use ( $cli, $tmp ) { $cli->load_users( array(), array( 'file' => $tmp, 'allow-host' => 'mcityportal.ussl.co.il' ) ); } ), 'dry-run refuses MyISAM' );
$wpdb->engine = 'InnoDB';

// probe: קורא ולא כותב
$wpdb->queries = array();
$cli->probe( array(), array( 'allow-host' => 'mcityportal.ussl.co.il' ) );
ok( ! array_filter( $wpdb->queries, function ( $x ) { return preg_match( '/^(INSERT|UPDATE|DELETE|START|COMMIT|ROLLBACK)/i', $x ); } ), 'probe issues no write or transaction queries' );

// T2 על הדמה: חייב לרוץ עד הסוף ולבטל תמיד
$wpdb->queries = array();
try { $cli->t2( array(), array( 'allow-host' => 'mcityportal.ussl.co.il' ) ); } catch ( WP_CLI_Fail $e ) { echo 'T2 stopped: ' . $e->getMessage() . "\n"; }
ok( in_array( 'ROLLBACK', $wpdb->queries, true ) && ! in_array( 'COMMIT', $wpdb->queries, true ), 'T2 always rolls back and never commits' );
$t2log = implode( "\n", WP_CLI::$log );
if ( getenv( 'SHOW_T2' ) ) { echo $t2log, "\n"; }
ok( false !== strpos( $t2log, 'T2-1 users without email' ) && false !== strpos( $t2log, 'T2-8 mail blocked' ), 'T2 reached its last tests' );

echo $fail ? "$fail of $n checks FAILED\n" : "OK: $n checks passed\n";
exit( $fail ? 1 : 0 );
