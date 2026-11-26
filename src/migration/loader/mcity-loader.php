<?php
/**
 * טוען משתמשים ל-MCity (טיוטה, לא רץ עדיין באף אתר). פקודות WP-CLI, בלי התקנת תוסף:
 *
 *   wp --require=/path/to/loader/mcity-loader.php mcity probe
 *   wp --require=/path/to/loader/mcity-loader.php mcity load-users --file=users.jsonl --allow-host=mcityportal.ussl.co.il
 *   wp --require=/path/to/loader/mcity-loader.php mcity t2 --allow-host=mcityportal.ussl.co.il
 *
 * עקרונות בטיחות (מחייבים):
 *  - ברירת מחדל: הרצת ניסיון. הכול נכתב בתוך טרנזקציה של InnoDB ומתבטל בסוף (ROLLBACK). כתיבה אמיתית רק עם --commit.
 *  - --allow-host חובה, ומשווה לדומיין של האתר. כך אי אפשר להריץ בטעות על האתר של המעצב או על פרודקשן.
 *  - לא מוחק כלום: אין wp_delete_*, אין DELETE, אין TRUNCATE.
 *  - לא שולח מייל: pre_wp_mail חוסם כל שליחה בזמן הריצה.
 *  - אידמפוטנטי: משתמש קיים מזוהה לפי usermeta _mc_legacy_key ומתעדכן, לא נוצר שוב.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/mcity-mapping.php';

class MCity_Loader_Common {

	/** מסרב לרוץ אם הדומיין של האתר שונה מ---allow-host. */
	public static function guard_host( $assoc ) {
		$allow = isset( $assoc['allow-host'] ) ? $assoc['allow-host'] : '';
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $allow ) {
			WP_CLI::error( 'חובה --allow-host=<דומיין האתר>. מדומיין האתר הנוכחי: ' . $host );
		}
		if ( strtolower( $allow ) !== strtolower( (string) $host ) ) {
			WP_CLI::error( sprintf( 'האתר הנוכחי הוא %s ולא %s. לא רץ.', $host, $allow ) );
		}
	}

	/** חוסם כל שליחת מייל, וסופר ניסיונות. */
	public static function block_mail() {
		$GLOBALS['mcl_mail_attempts'] = 0;
		add_filter(
			'pre_wp_mail',
			static function () {
				$GLOBALS['mcl_mail_attempts']++;
				return true;
			},
			PHP_INT_MAX
		);
		add_filter( 'send_password_change_email', '__return_false', PHP_INT_MAX );
		add_filter( 'send_email_change_email', '__return_false', PHP_INT_MAX );
	}

	/** טבלאות שנוגעים בהן ושחייבות להיות InnoDB כדי ש-ROLLBACK יעבוד. מחזיר רשימת טבלאות שאינן InnoDB. */
	public static function non_innodb_tables() {
		global $wpdb;
		$tables = array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options );
		$in     = "'" . implode( "','", array_map( 'esc_sql', $tables ) ) . "'";
		$rows   = $wpdb->get_results( "SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)", ARRAY_A );
		$bad    = array();
		foreach ( $rows as $r ) {
			if ( 'InnoDB' !== $r['e'] ) {
				$bad[] = $r['t'] . '=' . $r['e'];
			}
		}
		return $bad;
	}

	public static function begin() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	public static function end( $commit ) {
		global $wpdb;
		$wpdb->query( $commit ? 'COMMIT' : 'ROLLBACK' );
		// מטמון אובייקטים (אם יש מטמון חיצוני) עלול להחזיק ערכים שבוטלו
		wp_cache_flush();
	}

	/** slug עיר ← מזהה מונח בקטגוריה. null אם אין. */
	public static function city_term_id( $slug ) {
		$t = get_term_by( 'slug', $slug, 'category' );
		return $t ? (int) $t->term_id : null;
	}

	public static function find_user_by_key( $key ) {
		$ids = get_users( array( 'meta_key' => '_mc_legacy_key', 'meta_value' => $key, 'fields' => 'ID', 'number' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return $ids ? (int) $ids[0] : 0;
	}

	public static function read_jsonl( $path, $limit = 0 ) {
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( 'הקובץ לא נקרא: ' . $path );
		}
		$rows = array();
		$fh   = fopen( $path, 'r' );
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$rows[] = json_decode( $line, true );
			if ( $limit && count( $rows ) >= $limit ) {
				break;
			}
		}
		fclose( $fh );
		return $rows;
	}
}

class MCity_Loader_Command {

	/** טוען משתמשים. ההגדרות של הפקודה (OPTIONS) נמצאות ב-MCity_Command::load_users בסוף הקובץ. */
	public function run( $args, $assoc ) {
		MCity_Loader_Common::guard_host( $assoc );
		MCity_Loader_Common::block_mail();
		if ( ! function_exists( 'update_field' ) ) {
			WP_CLI::error( 'ACF לא פעיל.' );
		}
		$commit = ! empty( $assoc['commit'] );
		if ( ! $commit ) {
			$bad = MCity_Loader_Common::non_innodb_tables();
			if ( $bad ) {
				WP_CLI::error( 'אי אפשר להריץ ניסיון בטוח, טבלאות שאינן InnoDB: ' . implode( ', ', $bad ) );
			}
		}
		$rows = MCity_Loader_Common::read_jsonl( $assoc['file'], isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 0 );

		$st = array( 'seen' => count( $rows ), 'created' => 0, 'updated' => 0, 'errors' => 0 );
		$notes = array();
		$skips = array();
		$city_missing = array();
		$errors = array();

		wp_defer_term_counting( true );
		MCity_Loader_Common::begin();
		foreach ( $rows as $rec ) {
			$plan = mcl_plan_user( $rec );
			if ( 'skip' === $plan['action'] ) {
				$skips[ $plan['reason'] ] = ( isset( $skips[ $plan['reason'] ] ) ? $skips[ $plan['reason'] ] : 0 ) + 1;
				continue;
			}
			foreach ( $plan['notes'] as $n ) {
				$notes[ $n ] = ( isset( $notes[ $n ] ) ? $notes[ $n ] : 0 ) + 1;
			}
			$res = $this->write_one( $plan, $city_missing );
			if ( is_wp_error( $res ) ) {
				$st['errors']++;
				$errors[] = $plan['meta']['_mc_legacy_key'] . ': ' . $res->get_error_message();
				continue;
			}
			$st[ $res['status'] ]++;
		}
		wp_defer_term_counting( false );
		MCity_Loader_Common::end( $commit );

		$report = array( 'mode' => $commit ? 'COMMIT' : 'DRY-RUN (rolled back)', 'stats' => $st, 'skipped' => $skips, 'notes' => $notes, 'city_slugs_missing' => $city_missing, 'errors' => array_slice( $errors, 0, 50 ), 'mail_attempts_blocked' => (int) $GLOBALS['mcl_mail_attempts'] );
		WP_CLI::log( wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		if ( ! empty( $assoc['report'] ) ) {
			file_put_contents( $assoc['report'], wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		}
		if ( $st['errors'] ) {
			WP_CLI::warning( $st['errors'] . ' שגיאות. ראו את הדוח.' );
		} else {
			WP_CLI::success( $commit ? 'נכתב.' : 'ריצת ניסיון הסתיימה, לא נכתב כלום.' );
		}
	}

	/** @return array|WP_Error {status: created|updated, id} */
	private function write_one( array $plan, array &$city_missing ) {
		$key      = $plan['meta']['_mc_legacy_key'];
		$existing = MCity_Loader_Common::find_user_by_key( $key );
		$user     = $plan['user'];

		if ( $existing ) {
			$user['ID'] = $existing; // עדכון: בלי user_pass, הסיסמה לא משתנה
			$id         = wp_insert_user( $user );
			$status     = 'updated';
		} else {
			$user['user_pass'] = wp_generate_password( 24, true, true );
			$id                = wp_insert_user( $user );
			$status            = 'created';
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		foreach ( $plan['meta'] as $k => $v ) {
			update_user_meta( $id, $k, $v );
		}
		foreach ( $plan['acf'] as $name => $value ) {
			update_field( MCL_FIELD_KEYS[ $name ], $value, 'user_' . $id );
		}

		// ערים
		if ( isset( $plan['city_slugs'] ) && $plan['city_slugs'] ) {
			$ids = array();
			foreach ( $plan['city_slugs'] as $slug ) {
				$tid = MCity_Loader_Common::city_term_id( $slug );
				if ( $tid ) {
					$ids[] = $tid;
				} else {
					$city_missing[ $slug ] = true;
				}
			}
			if ( $ids ) {
				$value = mcl_city_value( $ids );
				update_field( MCL_FIELD_KEYS['mcity_cities'], $value, 'user_' . $id );
				// ACF עלול להמיר למספרים. החוזה דורש מחרוזות (שאילתת LIKE), ולכן מוודאים
				$stored = get_user_meta( $id, 'mcity_cities', true );
				if ( ! mcl_is_string_id_array( maybe_unserialize( $stored ) ) ) {
					update_user_meta( $id, 'mcity_cities', $value );
				}
			}
		}
		return array( 'status' => $status, 'id' => $id );
	}
}

class MCity_Probe_Command {

	/** בדיקת סביבה לקריאה בלבד. */
	public function run( $args, $assoc ) {
		global $wpdb;
		MCity_Loader_Common::guard_host( $assoc );
		$out = array();

		$out['db_version']         = $wpdb->get_var( 'SELECT VERSION()' );
		$out['sql_mode']           = $wpdb->get_var( 'SELECT @@sql_mode' );
		$out['max_allowed_packet'] = $wpdb->get_var( 'SELECT @@max_allowed_packet' );
		$vars                      = array();
		foreach ( $wpdb->get_results( "SHOW VARIABLES WHERE Variable_name LIKE 'innodb_ft%' OR Variable_name LIKE 'ft_%' OR Variable_name IN ('character_set_server','collation_server')", ARRAY_N ) as $r ) {
			$vars[ $r[0] ] = $r[1];
		}
		$out['fulltext_variables'] = $vars;

		$tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->users, $wpdb->usermeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->options );
		$in     = "'" . implode( "','", array_map( 'esc_sql', $tables ) ) . "'";
		$out['engines'] = $wpdb->get_results( "SELECT TABLE_NAME AS t, ENGINE AS e, TABLE_ROWS AS approx_rows FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)", ARRAY_A );

		$out['php']     = PHP_VERSION;
		$out['php_ext'] = array();
		foreach ( array( 'sqlite3', 'pdo_sqlite', 'mbstring', 'intl', 'imagick', 'gd', 'zip', 'iconv' ) as $e ) {
			$out['php_ext'][ $e ] = extension_loaded( $e );
		}
		$out['php_limits']                = array( 'memory_limit' => ini_get( 'memory_limit' ), 'max_execution_time_cli' => ini_get( 'max_execution_time' ) );
		$out['external_object_cache']     = wp_using_ext_object_cache();

		$out['taxonomies'] = array();
		foreach ( array( 'category', 'section', 'cat-2', 'author_category', 'video_place' ) as $tx ) {
			$out['taxonomies'][ $tx ] = taxonomy_exists( $tx ) ? (int) wp_count_terms( array( 'taxonomy' => $tx, 'hide_empty' => false ) ) : 'missing';
		}
		$domains = json_decode( (string) @file_get_contents( dirname( __DIR__ ) . '/mappings/domains.json' ), true );
		$slugs   = array();
		if ( is_array( $domains ) ) {
			foreach ( $domains as $k => $d ) {
				if ( is_array( $d ) && ! empty( $d['slug'] ) && isset( $d['kind'] ) && 'city' === $d['kind'] ) {
					$slugs[ $d['slug'] ] = true;
				}
			}
		}
		$out['city_slugs_missing_as_category_terms'] = array();
		foreach ( array_keys( $slugs ) as $s ) {
			if ( ! MCity_Loader_Common::city_term_id( $s ) ) {
				$out['city_slugs_missing_as_category_terms'][] = $s;
			}
		}
		$out['city_slugs_checked'] = count( $slugs );

		$out['roles']          = array_keys( wp_roles()->roles );
		$out['users_by_role']  = count_users()['avail_roles'];
		$out['acf_field_keys'] = array();
		if ( function_exists( 'acf_get_field' ) ) {
			foreach ( MCL_FIELD_KEYS as $name => $k ) {
				$f = acf_get_field( $k );
				$out['acf_field_keys'][ $name ] = $f ? $f['name'] . ' (' . $f['type'] . ')' : 'MISSING';
			}
		} else {
			$out['acf_field_keys'] = 'ACF לא פעיל';
		}
		$out['mu_plugin_dir_exists'] = is_dir( WPMU_PLUGIN_DIR );

		WP_CLI::log( wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		WP_CLI::success( 'קריאה בלבד, לא נכתב כלום.' );
	}
}

require_once __DIR__ . '/mcity-t2.php';

/**
 * פקודת האב `wp mcity`. פקודה אחת עם תתי-פקודות (ולא כמה פקודות נפרדות), כדי שלא תהיה תלות בסדר רישום של פקודת אב.
 */
class MCity_Command {

	/**
	 * בדיקת סביבה לקריאה בלבד: מסד נתונים, FULLTEXT, מנוע טבלאות, הרחבות PHP, טקסונומיות, תפקידים ושדות ACF. לא כותב כלום.
	 *
	 * ## OPTIONS
	 *
	 * --allow-host=<host>
	 * : חובה. דומיין האתר שמותר לרוץ עליו.
	 *
	 * @when after_wp_load
	 */
	public function probe( $args, $assoc ) {
		( new MCity_Probe_Command() )->run( $args, $assoc );
	}

	/**
	 * טוען משתמשים (מומחים וכותבים) מ-users.jsonl. ברירת מחדל: הרצת ניסיון עם ביטול בסוף.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : users.jsonl מפלט ההגירה (מחוץ לריפו).
	 *
	 * --allow-host=<host>
	 * : חובה. דומיין האתר שמותר לרוץ עליו.
	 *
	 * [--limit=<n>]
	 * : מספר רשומות מקסימלי (לבדיקה).
	 *
	 * [--commit]
	 * : כתיבה אמיתית. בלי הדגל, הכול מתבטל בסוף.
	 *
	 * [--report=<path>]
	 * : כתיבת דוח JSON לקובץ (מחוץ לריפו).
	 *
	 * @subcommand load-users
	 * @when after_wp_load
	 */
	public function load_users( $args, $assoc ) {
		( new MCity_Loader_Command() )->run( $args, $assoc );
	}

	/**
	 * T2: בדיקות כתיבה בתוך טרנזקציה שמתבטלת תמיד. לא משאיר כלום באתר.
	 *
	 * ## OPTIONS
	 *
	 * --allow-host=<host>
	 * : חובה. דומיין האתר שמותר לרוץ עליו.
	 *
	 * @when after_wp_load
	 */
	public function t2( $args, $assoc ) {
		( new MCity_T2_Command() )->run( $args, $assoc );
	}
}

WP_CLI::add_command( 'mcity', 'MCity_Command' );
