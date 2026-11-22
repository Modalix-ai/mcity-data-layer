<?php
/**
 * בדיקת בניית השאילתה בלי וורדפרס אמיתי (דמה של הפונקציות הנחוצות).
 * בודק את הלוגיקה שלנו: כללי הפעיל, סינון עיר, הפרמטרים. לא בודק SQL אמיתי של וורדפרס.
 *
 * הרצה:  php tests/args-test.php   (מתוך התיקייה mcx-experts-list)
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---- דמה של וורדפרס ----
class WP_Term { public $term_id; function __construct( $id ) { $this->term_id = $id; } }
class WP_Error {}
class WP_User_Query {
	public static $last = null;
	public static $count = 0;
	function __construct( $args ) { self::$last = $args; self::$count++; }
	function get_results() { return array(); }
	function get_total() { return 0; }
}
class FakeWpdb {
	public $usermeta = 'wp_usermeta';
	public $last_sql = '';
	public $ids = array( '11', '12' );
	function prepare( $sql, ...$args ) { return str_replace( '%s', "'" . $args[0] . "'", $sql ); }
	function get_col( $sql ) { $this->last_sql = $sql; return $this->ids; }
}
$wpdb = new FakeWpdb();

$GLOBALS['stub_today']  = '20261122';
$GLOBALS['stub_terms']  = array( 'haifa' => 9 );
$GLOBALS['stub_admin']  = (object) array( 'ID' => 3 );
$GLOBALS['stub_filter'] = array();

function current_time( $t ) { return $GLOBALS['stub_today']; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function apply_filters( $tag, $value ) { return isset( $GLOBALS['stub_filter'][ $tag ] ) ? $GLOBALS['stub_filter'][ $tag ]( $value ) : $value; }
function get_user_by( $f, $v ) { return $GLOBALS['stub_admin']; }
function get_term_by( $f, $slug, $tax ) { return isset( $GLOBALS['stub_terms'][ $slug ] ) ? new WP_Term( $GLOBALS['stub_terms'][ $slug ] ) : false; }
function sanitize_title( $s ) { return strtolower( $s ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_shortcode( $a, $b ) {}

require __DIR__ . '/../mcx-experts-list.php';

// ---- מסגרת בדיקות קטנה ----
$fails = 0;
function check( $name, $cond ) {
	global $fails;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $cond ) { $fails++; }
}

// 1. בלי עיר: כל המומחים הפעילים, בלי דרישת כתבות
mcx_get_active_experts();
$q = WP_User_Query::$last;
check( '1 תפקיד author', 'author' === $q['role'] );
check( '1 בלי meta_query (כל הערים)', ! isset( $q['meta_query'] ) );
check( '1 בלי דרישת כתבות מפורסמות', ! isset( $q['has_published_posts'] ) );
check( '1 מוחרגים: לא פעילים 11,12', in_array( 11, $q['exclude'], true ) && in_array( 12, $q['exclude'], true ) );
check( '1 מוחרגים: 1,6,7 ומשתמש portvbaq_admin (3)', array_diff( array( 1, 6, 7, 3 ), $q['exclude'] ) === array() );
check( '1 דפדוף ברירת מחדל 24, עמוד 1', 24 === $q['number'] && 1 === $q['paged'] );
check( '1 מיון לפי שם תצוגה, עולה', 'display_name' === $q['orderby'] && 'ASC' === $q['order'] );

// 2. כלל "לא פעיל" ב-SQL
check( '2 ה-SQL בודק mc_active = 0', false !== strpos( $wpdb->last_sql, "meta_key = 'mc_active' AND meta_value = '0'" ) );
check( '2 ה-SQL בודק מנוי שפג (לא ריק וקטן מהיום)', false !== strpos( $wpdb->last_sql, "meta_value <> '' AND meta_value < '20261122'" ) );

// 3. עיר לפי מזהה
mcx_get_active_experts( array( 'city' => 5 ) );
$q = WP_User_Query::$last;
check( '3 עיר 5: LIKE על "5" עם מרכאות', '"5"' === $q['meta_query'][0]['value'] && 'LIKE' === $q['meta_query'][0]['compare'] && 'mcity_cities' === $q['meta_query'][0]['key'] );

// 4. עיר לפי סלאג
mcx_get_active_experts( array( 'city' => 'haifa' ) );
check( '4 סלאג haifa מתורגם למזהה 9', '"9"' === WP_User_Query::$last['meta_query'][0]['value'] );

// 5. סלאג לא מוכר: רשימה ריקה, בלי שאילתת משתמשים
$before = WP_User_Query::$count;
$r      = mcx_get_active_experts( array( 'city' => 'nowhere' ) );
check( '5 סלאג לא מוכר מחזיר ריק ולא "כולם"', array() === $r['users'] && 0 === $r['total'] && $before === WP_User_Query::$count );

// 6. has_posts
mcx_get_active_experts( array( 'has_posts' => true ) );
check( '6 has_posts מוסיף has_published_posts', array( 'post' ) === WP_User_Query::$last['has_published_posts'] );

// 7. הכול בלי דפדוף
mcx_get_active_experts( array( 'per_page' => -1 ) );
check( '7 per_page=-1 בלי number', ! isset( WP_User_Query::$last['number'] ) );

// 8. מיון לא חוקי חוזר לברירת מחדל
mcx_get_active_experts( array( 'orderby' => 'user_pass; DROP', 'order' => 'x' ) );
check( '8 orderby/order לא חוקיים נופלים לברירת מחדל', 'display_name' === WP_User_Query::$last['orderby'] && 'ASC' === WP_User_Query::$last['order'] );

// 9. auto דרך הפילטר
$GLOBALS['stub_filter']['mcx_experts_current_city'] = function () { return 12; };
mcx_get_active_experts( array( 'city' => 'auto' ) );
check( '9 city=auto משתמש בפילטר', '"12"' === WP_User_Query::$last['meta_query'][0]['value'] );
$GLOBALS['stub_filter']['mcx_experts_current_city'] = function () { return 0; };
mcx_get_active_experts( array( 'city' => 'auto' ) );
check( '9 auto בלי עיר = כל המומחים', ! isset( WP_User_Query::$last['meta_query'] ) );

// 10. הפילטר של ההחרגות
$GLOBALS['stub_filter']['mcx_experts_excluded_ids'] = function ( $ids ) { $ids[] = 99; return $ids; };
mcx_get_active_experts();
check( '10 פילטר ההחרגות מוסיף מזהה', in_array( 99, WP_User_Query::$last['exclude'], true ) );

echo $fails ? "\n$fails FAILED\n" : "\nכל הבדיקות עברו\n";
exit( $fails ? 1 : 0 );
