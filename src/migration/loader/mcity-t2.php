<?php
/**
 * T2: בדיקות כתיבה בעותק, כולן בתוך טרנזקציה שמתבטלת תמיד (אין --commit). נטענת מ-mcity-loader.php.
 *
 *   wp --require=/path/to/loader/mcity-loader.php mcity t2 --allow-host=mcityportal.ussl.co.il
 *
 * מה נבדק ולמה: docs/14-acf-export-analysis.md סעיף 4 והחלטות 94 ו-97. אין בדיקה שמוחקת. הרשומות הזמניות נעלמות ב-ROLLBACK.
 * מגבלה: מונה AUTO_INCREMENT של InnoDB לא מתבטל, ולכן יישאר פער במזהי משתמשים ופוסטים (בלי השפעה).
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class MCity_T2_Command {

	private $results = array();

	private function rec( $status, $id, $msg ) {
		$this->results[] = array( $status, $id, $msg );
		WP_CLI::log( sprintf( '[%s] %s: %s', $status, $id, $msg ) );
	}

	private function validate( $value, $field_key ) {
		if ( ! function_exists( 'acf_validate_value' ) ) {
			return 'acf_validate_value לא זמין';
		}
		$field = acf_get_field( $field_key );
		if ( ! $field ) {
			return 'שדה לא נמצא';
		}
		acf_reset_validation_errors();
		$ok   = acf_validate_value( $value, $field, 'acf[' . $field_key . ']' );
		$errs = acf_get_validation_errors();
		acf_reset_validation_errors();
		return $ok ? 'OK' : 'נדחה: ' . wp_json_encode( array_column( (array) $errs, 'message' ), JSON_UNESCAPED_UNICODE );
	}

	/** בדיקות כתיבה בתוך טרנזקציה שמתבטלת בסוף. ההגדרות של הפקודה ב-MCity_Command::t2. */
	public function run( $args, $assoc ) {
		global $wpdb;
		MCity_Loader_Common::guard_host( $assoc );
		MCity_Loader_Common::block_mail();
		if ( ! function_exists( 'update_field' ) ) {
			WP_CLI::error( 'ACF לא פעיל.' );
		}
		$bad = MCity_Loader_Common::non_innodb_tables();
		if ( $bad ) {
			WP_CLI::error( 'אי אפשר להבטיח ביטול, טבלאות שאינן InnoDB: ' . implode( ', ', $bad ) );
		}
		$this->rec( 'PASS', 'T2-0 preflight', 'כל הטבלאות InnoDB, ACF פעיל, הדומיין תואם' );

		foreach ( MCL_FIELD_KEYS as $name => $key ) {
			if ( ! acf_get_field( $key ) ) {
				$this->rec( 'FAIL', 'T2-0 field ' . $name, 'מפתח השדה לא נמצא: ' . $key );
			}
		}

		$sfx = substr( md5( uniqid( '', true ) ), 0, 6 );
		MCity_Loader_Common::begin();
		$exception = null;
		try {
			$this->run_tests( $sfx );
		} catch ( \Throwable $e ) {
			$exception = $e;
			$this->rec( 'FAIL', 'T2 exception', $e->getMessage() );
		}
		MCity_Loader_Common::end( false ); // תמיד ביטול

		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", 'mcl_t2_' . $sfx . '%' ) );
		$this->rec( 0 === $left ? 'PASS' : 'FAIL', 'T2-end rollback', 'משתמשי בדיקה שנשארו אחרי הביטול: ' . $left );

		$fails = count( array_filter( $this->results, static function ( $r ) { return 'FAIL' === $r[0]; } ) );
		$passes = count( array_filter( $this->results, static function ( $r ) { return 'PASS' === $r[0]; } ) );
		WP_CLI::log( sprintf( 'סיכום: %d PASS, %d FAIL, %d INFO', $passes, $fails, count( $this->results ) - $passes - $fails ) );
		if ( $fails || $exception ) {
			WP_CLI::error( 'יש כשלונות. שלחו את הפלט המלא.' );
		}
		WP_CLI::success( 'הסתיים. שלחו את הפלט המלא. לא נשאר כלום באתר.' );
	}

	private function run_tests( $sfx ) {
		global $wpdb;
		$login = function ( $n ) use ( $sfx ) {
			return 'mcl_t2_' . $sfx . '_' . $n;
		};

		// T2-1: שני משתמשים בלי אימייל (חיוני לכותבים ולמומחים עם אימייל משותף)
		$a = wp_insert_user( array( 'user_login' => $login( 'a' ), 'user_pass' => wp_generate_password(), 'user_email' => '', 'role' => 'author' ) );
		$b = wp_insert_user( array( 'user_login' => $login( 'b' ), 'user_pass' => wp_generate_password(), 'user_email' => '', 'role' => 'author' ) );
		$this->rec( ( is_int( $a ) && is_int( $b ) ) ? 'PASS' : 'FAIL', 'T2-1 users without email', ( is_int( $a ) && is_int( $b ) ) ? 'שני משתמשים בלי אימייל נוצרו' : wp_json_encode( array( is_wp_error( $a ) ? $a->get_error_message() : $a, is_wp_error( $b ) ? $b->get_error_message() : $b ) ) );
		if ( ! is_int( $a ) ) {
			return;
		}

		// T2-9: nicename בעברית
		$nice = 'אסתר-גנן-' . $sfx;
		$c    = wp_insert_user( array( 'user_login' => $login( 'c' ), 'user_pass' => wp_generate_password(), 'user_nicename' => $nice, 'role' => 'author' ) );
		$got  = is_int( $c ) ? get_userdata( $c )->user_nicename : '';
		$this->rec( $got === $nice ? 'PASS' : 'INFO', 'T2-9 hebrew nicename', 'ביקשנו ' . $nice . ' קיבלנו ' . $got . ( $got === $nice ? '' : ' (ייתכן קידוד אחוזים, ללמוד מזה למדיניות ה-slug X19)' ) );

		// T2-10: תפקידים
		$roles = wp_roles()->roles;
		$this->rec( isset( $roles['author'] ) ? 'PASS' : 'FAIL', 'T2-10 role author', isset( $roles['author'] ) ? 'קיים' : 'חסר' );
		$this->rec( 'INFO', 'T2-10 role mc_writer', isset( $roles['mc_writer'] ) ? 'קיים' : 'לא קיים (החלטה 92: המעצב יוצר, או שהטוען משתמש ב-contributor)' );

		// T2-2: _author_category
		$tids = get_terms( array( 'taxonomy' => 'author_category', 'hide_empty' => false, 'number' => 2, 'fields' => 'ids' ) );
		if ( is_array( $tids ) && count( $tids ) >= 2 ) {
			update_field( MCL_FIELD_KEYS['author_category'], $tids, 'user_' . $a );
			$raw  = get_user_meta( $a, '_author_category', true );
			$ref  = get_user_meta( $a, '__author_category', true );
			$rel  = wp_get_object_terms( $a, 'author_category', array( 'fields' => 'ids' ) );
			$same = is_array( $rel ) && ! array_diff( array_map( 'intval', $tids ), array_map( 'intval', $rel ) );
			$this->rec( $same ? 'PASS' : 'FAIL', 'T2-2 _author_category terms', 'קשרי מונחים נכתבו: ' . wp_json_encode( $rel ) );
			$this->rec( 'INFO', 'T2-2 _author_category meta', 'meta גולמי ' . wp_json_encode( $raw ) . ' | מפתח הפניה __author_category: ' . wp_json_encode( $ref ) );
			$gf = get_field( MCL_FIELD_KEYS['author_category'], 'user_' . $a );
			$this->rec( 'INFO', 'T2-2 get_field', wp_json_encode( $gf ) );
		} else {
			$this->rec( 'INFO', 'T2-2 _author_category', 'דולג: פחות משני מונחים בטקסונומיה author_category' );
		}

		// T2-3: mcity_cities כמחרוזות
		$cids = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 2, 'fields' => 'ids' ) );
		if ( is_array( $cids ) && count( $cids ) >= 2 ) {
			$value = mcl_city_value( $cids );
			update_field( MCL_FIELD_KEYS['mcity_cities'], $value, 'user_' . $a );
			$stored = maybe_unserialize( get_user_meta( $a, 'mcity_cities', true ) );
			$shape  = mcl_is_string_id_array( $stored );
			$this->rec( $shape ? 'PASS' : 'INFO', 'T2-3 mcity_cities after update_field', 'צורה שנשמרה: ' . wp_json_encode( $stored ) . ( $shape ? ' (מחרוזות)' : ' (ACF המיר, הטוען כופה מחרוזות)' ) );
			if ( ! $shape ) {
				update_user_meta( $a, 'mcity_cities', $value );
				$stored = maybe_unserialize( get_user_meta( $a, 'mcity_cities', true ) );
				$this->rec( mcl_is_string_id_array( $stored ) ? 'PASS' : 'FAIL', 'T2-3 forced strings', wp_json_encode( $stored ) );
			}
			$like = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'mcity_cities' AND meta_value LIKE %s", $a, '%"' . $cids[0] . '"%' ) );
			$this->rec( 1 === $like ? 'PASS' : 'FAIL', 'T2-3 LIKE query', 'שאילתת LIKE של המעצב מוצאת את המומחה: ' . $like );
			$rel = wp_get_object_terms( $a, 'category', array( 'fields' => 'ids' ) );
			$this->rec( 'INFO', 'T2-3 category terms on user', 'save_terms=1 כתב קשרי מונחים לקטגוריה על מזהה משתמש: ' . wp_json_encode( $rel ) );
			$in_term = get_objects_in_term( $cids[0], 'category' );
			$this->rec( 'INFO', 'T2-3 id collision', 'מזהה המשתמש ' . $a . ( in_array( (string) $a, array_map( 'strval', $in_term ), true ) ? ' מופיע' : ' לא מופיע' ) . ' ב-get_objects_in_term של הקטגוריה. פוסט עם אותו מזהה: ' . ( get_post( $a ) ? 'קיים (התנגשות אמיתית)' : 'לא קיים' ) );
		} else {
			$this->rec( 'INFO', 'T2-3 mcity_cities', 'דולג: פחות משתי קטגוריות' );
		}

		// T2-4: טלפון
		$pk = MCL_FIELD_KEYS['phone'];
		update_field( $pk, '0541234567', 'user_' . $a );
		$ph = get_user_meta( $a, 'phone', true );
		$this->rec( '0541234567' === $ph ? 'PASS' : 'FAIL', 'T2-4 phone leading zero', 'נשמר: ' . wp_json_encode( $ph ) . ' | get_field: ' . wp_json_encode( get_field( $pk, 'user_' . $a ) ) );
		foreach ( array( '0541234567', '054-123-4567', '054 123 4567', '+972541234567', '03-1234567' ) as $v ) {
			$this->rec( 'INFO', 'T2-4 validate ' . $v, $this->validate( $v, $pk ) );
		}

		// T2-5: תאריך סיום מנוי
		$sk = MCL_FIELD_KEYS['mc_subscription_end'];
		update_field( $sk, '20301231', 'user_' . $a );
		$raw = get_user_meta( $a, 'mc_subscription_end', true );
		$this->rec( '20301231' === $raw ? 'PASS' : 'FAIL', 'T2-5 mc_subscription_end Ymd', 'נשמר ' . wp_json_encode( $raw ) . ' | get_field ' . wp_json_encode( get_field( $sk, 'user_' . $a ) ) );

		// T2-7: מומחה פעיל
		$ak = MCL_FIELD_KEYS['mc_active'];
		update_field( $ak, '0', 'user_' . $a );
		$r0 = get_user_meta( $a, 'mc_active', true );
		update_field( $ak, '1', 'user_' . $a );
		$r1 = get_user_meta( $a, 'mc_active', true );
		$this->rec( ( '0' === (string) $r0 && '1' === (string) $r1 ) ? 'PASS' : 'FAIL', 'T2-7 mc_active', "'0' נשמר כ-" . wp_json_encode( $r0 ) . ", '1' נשמר כ-" . wp_json_encode( $r1 ) );

		// T2-6: באנרי חובה
		$fields = function_exists( 'acf_get_fields' ) ? acf_get_fields( 'group_mc_expert_banners' ) : array();
		$req    = array_filter( (array) $fields, static function ( $f ) { return ! empty( $f['required'] ); } );
		$this->rec( 14 === count( $req ) ? 'PASS' : 'INFO', 'T2-6 required banners', count( $req ) . ' שדות חובה מתוך ' . count( (array) $fields ) );
		if ( $fields ) {
			$first = $fields[0];
			$this->rec( 'INFO', 'T2-6 validate empty banner', $this->validate( '', $first['key'] ) . ' (נדחה = הטופס יכשל כצפוי, החלטה 97)' );
		}
		$this->rec( 'PASS', 'T2-6 programmatic write without banners', 'המשתמשים שנוצרו למעלה נכתבו בלי אף באנר: הכתיבה התכנותית עוקפת את required' );

		// T2-8: חסימת מייל
		$before = (int) $GLOBALS['mcl_mail_attempts'];
		$sent   = wp_mail( 'nobody@example.invalid', 'mcl t2', 'x' );
		$this->rec( ( $sent && (int) $GLOBALS['mcl_mail_attempts'] === $before + 1 ) ? 'PASS' : 'FAIL', 'T2-8 mail blocked', 'wp_mail חזר true והמונה עלה (המייל נחסם ולא נשלח)' );

		// T2-11: מדור הכתבה (רדיו) מול כמה מדורים
		$sids = get_terms( array( 'taxonomy' => 'section', 'hide_empty' => false, 'number' => 2, 'fields' => 'ids' ) );
		if ( is_array( $sids ) && count( $sids ) >= 2 ) {
			$pid = wp_insert_post( array( 'post_title' => 'mcl t2 ' . $sfx, 'post_status' => 'draft', 'post_type' => 'post' ) );
			if ( is_int( $pid ) && $pid > 0 ) {
				wp_set_object_terms( $pid, array_map( 'intval', $sids ), 'section' );
				$n  = wp_get_object_terms( $pid, 'section', array( 'fields' => 'ids' ) );
				$gf = get_field( MCL_FIELD_KEYS['post_section'], $pid );
				$this->rec( count( $n ) >= 2 ? 'PASS' : 'FAIL', 'T2-11 two sections on one post', 'ב-DB ' . count( $n ) . ' מדורים. get_field (רדיו) מחזיר ' . wp_json_encode( $gf ) . '. העורך יציג רק אחד' );
			} else {
				$this->rec( 'FAIL', 'T2-11', 'יצירת פוסט בדיקה נכשלה' );
			}
		} else {
			$this->rec( 'INFO', 'T2-11 post_section', 'דולג: פחות משני מונחים בטקסונומיה section' );
		}
	}
}

