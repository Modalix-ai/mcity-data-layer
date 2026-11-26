<?php
/**
 * בדיקת המיפוי הטהור בלי וורדפרס.
 *
 * הרצה (מתוך src/migration/loader):
 *   php tests/mapping-test.php
 *   MC_USERS_JSONL=/path/users.jsonl php tests/mapping-test.php     # כולל ספירות מול פלט ההגירה האמיתי (מחוץ לריפו)
 */

require __DIR__ . '/../mcity-mapping.php';

$fail = 0;
$n    = 0;
function check( $cond, $msg ) {
	global $fail, $n;
	$n++;
	if ( ! $cond ) {
		$fail++;
		echo "FAIL: $msg\n";
	}
}

$expert = array(
	'key' => 'expert:16', 'kind' => 'expert', 'legacy_id' => 16, 'login' => 'mc_expert_16', 'nicename' => 'אסתר-גנן', 'role' => 'author',
	'display_name' => 'אסתר גנן', 'first_name' => null, 'last_name' => null, 'email' => 'St.Ganan@Gmail.com', 'email_status' => 'unique',
	'phone' => '0528469288', 'profession' => 'קלינאות תקשורת', 'address' => null, 'url_business_card' => null, 'facebook' => 'https://fb.example/x',
	'bio' => null, 'short_description' => 'קלינאית תקשורת', 'mc_active' => '1', 'mc_subscription_end' => '20280830',
	'cities' => array( 'modiin', 'ashdod', 'modiin' ), 'national' => false, 'author_categories' => array( array( 'field' => 'x' ) ),
	'avatar_file' => '/files_media/a.jpg', 'minisite' => array( 'url' => 'מוסדות' ), 'hold' => array(), 'flags' => array(),
);

// 1. מומחה רגיל
$p = mcl_plan_user( $expert );
check( 'load' === $p['action'], 'expert loads' );
check( 'st.ganan@gmail.com' === $p['user']['user_email'], 'email lowercased' );
check( 'mc_expert_16' === $p['user']['user_login'] && 'author' === $p['user']['role'], 'login and role' );
check( '0528469288' === $p['acf']['phone'] && is_string( $p['acf']['phone'] ), 'phone stays a string with leading zero' );
check( '1' === $p['acf']['mc_active'] && '20280830' === $p['acf']['mc_subscription_end'], 'active and subscription' );
check( array( 'ashdod', 'modiin' ) === $p['city_slugs'], 'cities unique and sorted' );
check( 'קלינאית תקשורת' === $p['user']['description'], 'description falls back to short_description when bio is empty' );
check( ! isset( $p['meta']['_mc_legacy_short_description'] ), 'short_description kept in meta only when bio exists' );
check( 'expert:16' === $p['meta']['_mc_legacy_key'] && '16' === $p['meta']['_mc_legacy_id'], 'legacy meta' );
check( isset( $p['meta']['facebook'] ) && isset( $p['meta']['_mc_legacy_minisite'] ) && isset( $p['meta']['_mc_legacy_avatar_file'] ), 'private meta kept' );
check( in_array( 'banners_not_written_X23', $p['notes'], true ) && in_array( 'author_category_unmapped', $p['notes'], true ), 'notes for pending decisions' );
check( ! array_key_exists( 'user_pass', $p['user'] ), 'mapping never sets a password (the loader generates it)' );
foreach ( array_keys( $p['acf'] ) as $name ) {
	check( isset( MCL_FIELD_KEYS[ $name ] ), "acf name $name has a field key" );
}

// 2. bio קיים: בו משתמשים, ו-short נשמר כמטא
$e2 = $expert;
$e2['bio'] = 'ביוגרפיה ארוכה';
$p = mcl_plan_user( $e2 );
check( 'ביוגרפיה ארוכה' === $p['user']['description'] && isset( $p['meta']['_mc_legacy_short_description'] ), 'bio wins, short kept in meta' );

// 3. אימייל משותף: בלי אימייל
$e3 = $expert;
$e3['email_status'] = 'shared';
$e3['email']        = null;
$p = mcl_plan_user( $e3 );
check( '' === $p['user']['user_email'] && in_array( 'no_email', $p['notes'], true ), 'shared email stays empty with a note' );
$e3['email_status'] = 'shared';
$e3['email']        = 'shared@example.com';
$p = mcl_plan_user( $e3 );
check( '' === $p['user']['user_email'], 'a non-unique email is never written even if present in the record' );

// 4. לא פעיל
$e4 = $expert;
$e4['mc_active'] = '0';
check( '0' === mcl_plan_user( $e4 )['acf']['mc_active'], 'inactive written as 0' );
$e4['mc_active'] = '';
check( '1' === mcl_plan_user( $e4 )['acf']['mc_active'], 'empty active means 1 (docs/10)' );

// 5. תאריך שגוי או חסר
$e5 = $expert;
$e5['mc_subscription_end'] = '2028-08-30';
$p = mcl_plan_user( $e5 );
check( ! isset( $p['acf']['mc_subscription_end'] ) && in_array( 'bad_subscription_end', $p['notes'], true ), 'bad date not written' );
$e5['mc_subscription_end'] = null;
check( ! isset( mcl_plan_user( $e5 )['acf']['mc_subscription_end'] ), 'no date is not invented (docs/10)' );

// 6. דילוגים
$h = $expert;
$h['hold'] = array( 'not_an_expert' );
check( 'skip' === mcl_plan_user( $h )['action'] && 'hold:not_an_expert' === mcl_plan_user( $h )['reason'], 'hold skipped' );
$c = $expert;
$c['kind'] = 'circle';
check( 'circle_pending_X20' === mcl_plan_user( $c )['reason'], 'circle skipped until X20' );
check( 'staff_mapped_manually' === mcl_plan_user( array( 'key' => 'staff:mcity', 'kind' => 'staff', 'hold' => array() ) )['reason'], 'staff skipped' );
check( 'skip' === mcl_plan_user( array( 'kind' => 'expert', 'hold' => array() ) )['action'], 'missing fields skipped' );

// 7. כותב
$w = array( 'key' => 'writer:8', 'kind' => 'contributor', 'legacy_id' => 8, 'login' => 'mc_writer_8', 'role' => 'contributor', 'display_name' => 'גילה עיני', 'email' => '', 'hold' => array() );
$p = mcl_plan_user( $w );
check( 'load' === $p['action'] && 'contributor' === $p['user']['role'] && empty( $p['acf'] ) && '' === $p['user']['user_email'], 'contributor without acf and email' );

// 8. ערכי ערים
check( array( '5', '15' ) === mcl_city_value( array( 15, 5, 5 ) ), 'city value: sorted unique strings' );
check( mcl_is_string_id_array( array( '5', '15' ) ) && ! mcl_is_string_id_array( array( 5, 15 ) ) && ! mcl_is_string_id_array( array() ), 'string id array detector' );

// 9. פלט אמיתי (אופציונלי)
$path = getenv( 'MC_USERS_JSONL' );
if ( $path && is_readable( $path ) ) {
	$loads = 0; $skips = array(); $logins = array(); $keys = array();
	foreach ( file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$rec = json_decode( $line, true );
		$pl  = mcl_plan_user( $rec );
		if ( 'load' === $pl['action'] ) {
			$loads++;
			check( empty( $rec['hold'] ), 'loaded record has no hold: ' . $rec['key'] );
			check( ! isset( $logins[ $pl['user']['user_login'] ] ), 'duplicate login ' . $pl['user']['user_login'] );
			check( ! isset( $keys[ $rec['key'] ] ), 'duplicate key ' . $rec['key'] );
			$logins[ $pl['user']['user_login'] ] = true;
			$keys[ $rec['key'] ]                 = true;
			if ( '' !== $pl['user']['user_email'] ) {
				check( filter_var( $pl['user']['user_email'], FILTER_VALIDATE_EMAIL ) !== false, 'invalid email ' . $rec['key'] );
			}
		} else {
			$skips[ preg_replace( '/:.*/', '', $pl['reason'] ) ] = ( $skips[ preg_replace( '/:.*/', '', $pl['reason'] ) ] ?? 0 ) + 1;
		}
	}
	ksort( $skips );
	echo "real output: load=$loads skips=" . json_encode( $skips ) . "\n";
	if ( getenv( 'MC_EXPECT_LOADS' ) ) {
		check( (int) getenv( 'MC_EXPECT_LOADS' ) === $loads, 'expected loads ' . getenv( 'MC_EXPECT_LOADS' ) . ' got ' . $loads );
	}
}

echo $fail ? "$fail of $n checks FAILED\n" : "OK: $n checks passed\n";
exit( $fail ? 1 : 0 );
