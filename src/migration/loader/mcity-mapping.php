<?php
/**
 * מיפוי טהור: רשומת JSONL ← מה שנכתב לוורדפרס. אין כאן קריאה או כתיבה לוורדפרס, ולכן אפשר לבדוק אותו עם php בלבד
 * (tests/mapping-test.php). הכתיבה בפועל נמצאת ב-mcity-loader.php.
 *
 * טיוטה, לא רצה באתר. כללים לפי docs/10 סעיף 4 והחלטות 90, 94, 97.
 */

if ( ! defined( 'MCL_FIELD_KEYS' ) ) {
	/** מפתחות שדות ACF מהייצוא של 2026-11-25 (docs/reference/acf-export-2026-11-25.json). כותבים לפי מפתח, לא לפי שם (החלטה 94). */
	define( 'MCL_FIELD_KEYS', array(
		'mc_active'           => 'field_mc_active',
		'mc_subscription_end' => 'field_mc_subscription_end',
		'profession'          => 'field_6ab56a03b892f',
		'phone'               => 'field_6ab56c13bb1a4',
		'address'             => 'field_6ab56c36bb1a5',
		'url_business_card'   => 'field_6ac3edc08d729',
		'author_category'     => 'field_6abaac3d3ca67', // השם בפועל _author_category (קו תחתון)
		'mcity_cities'        => 'field_mcity_cities',
		'post_section'        => 'field_6accb676ef11e',
	) );
}

/**
 * @param array $rec  שורה מ-users.jsonl
 * @return array {action: load|skip, reason, user: array, acf: array, meta: array, city_slugs: string[], notes: string[]}
 */
function mcl_plan_user( array $rec ) {
	$plan = array( 'action' => 'skip', 'reason' => '', 'user' => array(), 'acf' => array(), 'meta' => array(), 'city_slugs' => array(), 'notes' => array() );
	$kind = isset( $rec['kind'] ) ? $rec['kind'] : '';

	if ( ! empty( $rec['hold'] ) ) {
		$plan['reason'] = 'hold:' . implode( ',', (array) $rec['hold'] );
		return $plan;
	}
	if ( 'circle' === $kind ) {
		$plan['reason'] = 'circle_pending_X20'; // החלטה 90: מעגלים לא נכתבים עד שהמעצב יחליט איך הם מיוצגים
		return $plan;
	}
	if ( 'staff' === $kind ) {
		$plan['reason'] = 'staff_mapped_manually'; // חשבון צוות ממופה למשתמש קיים בהחלטה ידנית
		return $plan;
	}
	if ( ! in_array( $kind, array( 'expert', 'contributor' ), true ) ) {
		$plan['reason'] = 'unknown_kind:' . $kind;
		return $plan;
	}
	foreach ( array( 'key', 'login', 'role', 'display_name' ) as $req ) {
		if ( empty( $rec[ $req ] ) ) {
			$plan['reason'] = 'missing_' . $req;
			return $plan;
		}
	}

	$plan['action'] = 'load';
	$email          = ( isset( $rec['email_status'] ) && 'unique' === $rec['email_status'] && ! empty( $rec['email'] ) ) ? strtolower( trim( $rec['email'] ) ) : '';
	if ( 'expert' === $kind && '' === $email ) {
		$plan['notes'][] = 'no_email'; // בלי אימייל: המומחה לא יקבל שאלות (docs/10, החלטה 96)
	}

	$bio  = isset( $rec['bio'] ) ? trim( (string) $rec['bio'] ) : '';
	$desc = '' !== $bio ? $bio : ( isset( $rec['short_description'] ) ? trim( (string) $rec['short_description'] ) : '' );

	$plan['user'] = array_filter(
		array(
			'user_login'   => $rec['login'],
			'user_email'   => $email,
			'user_nicename' => isset( $rec['nicename'] ) ? $rec['nicename'] : '',
			'display_name' => $rec['display_name'],
			'first_name'   => isset( $rec['first_name'] ) ? $rec['first_name'] : '',
			'last_name'    => isset( $rec['last_name'] ) ? $rec['last_name'] : '',
			'role'         => $rec['role'],
			'description'  => $desc,
		),
		static function ( $v ) {
			return '' !== $v && null !== $v;
		}
	);
	// user_email ריק חייב להישלח כריק (לא נשמט) כדי שעדכון ינקה כתובת ישנה
	$plan['user']['user_email'] = $email;

	// מזהים טכניים. מפתח ייחודי כי expert:8 ו-writer:8 הם משתמשים שונים
	$plan['meta']['_mc_legacy_key']  = $rec['key'];
	$plan['meta']['_mc_legacy_kind'] = $kind;
	if ( isset( $rec['legacy_id'] ) ) {
		$plan['meta']['_mc_legacy_id'] = (string) $rec['legacy_id'];
	}

	if ( 'contributor' === $kind ) {
		return $plan;
	}

	// ---- מומחה ----
	$acf = array();
	$acf['mc_active'] = ( isset( $rec['mc_active'] ) && '0' === (string) $rec['mc_active'] ) ? '0' : '1';
	if ( ! empty( $rec['mc_subscription_end'] ) ) {
		if ( preg_match( '/^\d{8}$/', (string) $rec['mc_subscription_end'] ) ) {
			$acf['mc_subscription_end'] = (string) $rec['mc_subscription_end']; // Ymd, כמו return_format בשדה
		} else {
			$plan['notes'][] = 'bad_subscription_end';
		}
	}
	foreach ( array( 'profession', 'address', 'url_business_card' ) as $f ) {
		if ( ! empty( $rec[ $f ] ) ) {
			$acf[ $f ] = (string) $rec[ $f ];
		}
	}
	if ( ! empty( $rec['phone'] ) ) {
		$acf['phone'] = (string) $rec['phone']; // תמיד מחרוזת: השדה number, ואפס מוביל חייב להישמר (T2)
	}
	$plan['acf'] = $acf;

	if ( ! empty( $rec['facebook'] ) ) {
		$plan['meta']['facebook'] = (string) $rec['facebook']; // שדה של Yoast, מפתח usermeta בשם facebook (docs/12)
	}

	// ערים: slugים. הפיכה למזהי מונחים נעשית בטוען (צריך וורדפרס)
	$cities = isset( $rec['cities'] ) && is_array( $rec['cities'] ) ? array_values( array_unique( $rec['cities'] ) ) : array();
	sort( $cities );
	$plan['city_slugs'] = $cities;
	if ( empty( $cities ) ) {
		$plan['notes'][] = 'no_cities';
	}
	if ( ! empty( $rec['national'] ) ) {
		$plan['notes'][] = 'national';
	}
	if ( ! empty( $rec['author_categories'] ) ) {
		$plan['notes'][] = 'author_category_unmapped'; // טבלת התאמה תחום ישן ← author_category עוד לא נבנתה (docs/10 סעיף 4)
	}

	// נתונים שנשמרים כמטא פרטית עד שיוחלט איך מציגים אותם
	if ( ! empty( $rec['short_description'] ) && '' !== $bio ) {
		$plan['meta']['_mc_legacy_short_description'] = (string) $rec['short_description'];
	}
	if ( ! empty( $rec['avatar_file'] ) ) {
		$plan['meta']['_mc_legacy_avatar_file'] = (string) $rec['avatar_file']; // תמונות: ממתין לפורמטים מהמעצב
	}
	if ( ! empty( $rec['minisite'] ) ) {
		$plan['meta']['_mc_legacy_minisite'] = mcl_json( $rec['minisite'] ); // המיניסייטים עוד לא נבנו (X4)
	}
	// באנרי עמוד מומחה (14 שדות חובה) לא נכתבים עד החלטת X23. אין כאן ערך ברירת מחדל מומצא.
	$plan['notes'][] = 'banners_not_written_X23';

	return $plan;
}

/** JSON בעברית בלי בריחה, זהה בוורדפרס ובבדיקה המקומית. */
function mcl_json( $v ) {
	return function_exists( 'wp_json_encode' ) ? wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) : json_encode( $v, JSON_UNESCAPED_UNICODE );
}

/**
 * ערכי mcity_cities: מערך מסודר של מזהי מונחים כמחרוזות (החלטה 94, החוזה סעיף 2), כך ששאילתת LIKE על "5" עם מרכאות לא תופסת 15.
 *
 * @param int[] $term_ids
 * @return string[]
 */
function mcl_city_value( array $term_ids ) {
	$ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
	sort( $ids );
	return array_map( 'strval', $ids );
}

/** האם מה שנשמר ב-usermeta (אחרי unserialize) הוא מערך מחרוזות. */
function mcl_is_string_id_array( $stored ) {
	if ( ! is_array( $stored ) || empty( $stored ) ) {
		return false;
	}
	foreach ( $stored as $v ) {
		if ( ! is_string( $v ) ) {
			return false;
		}
	}
	return true;
}
