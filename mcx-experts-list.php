<?php
/**
 * Plugin Name: MCity | רשימת מומחים פעילים (mcx)
 * Description: מחזיר את כל המומחים הפעילים, כולל מומחה בלי כתבות. כללי "פעיל" זהים לכללי המעצב (mc_active, mc_subscription_end). שורטקוד: [mcx_experts_list].
 * Version:     0.1.0
 * Author:      modalix ai
 *
 * התקנה: להעתיק את הקובץ כולו ל-wp-content/mu-plugins/ (או כתוסף רגיל).
 * אם מעדיפים functions.php של תבנית הבת: להדביק מהשורה "if ( ! defined( 'ABSPATH' ) )" ומטה, בלי כותרת התוסף.
 *
 * הקוד לא משנה שום שדה, לא כותב למסד, ולא נוגע בשורטקודים הקיימים.
 * שימוש בחזית בלבד. הוא קורא מ-usermeta את המפתחות שכבר קיימים: mc_active, mc_subscription_end, mcity_cities, profession, address, phone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * מזהי משתמשים שלא מוצגים לעולם ברשימת מומחים.
 * ברירת המחדל זהה להגנה המקודדת של המעצב: 1, 6, 7, והמשתמש portvbaq_admin.
 * אפשר לשנות דרך הפילטר mcx_experts_excluded_ids.
 *
 * @return int[]
 */
function mcx_experts_excluded_ids() {
	$ids   = array( 1, 6, 7 );
	$admin = get_user_by( 'login', 'portvbaq_admin' );
	if ( $admin ) {
		$ids[] = (int) $admin->ID;
	}
	$ids = apply_filters( 'mcx_experts_excluded_ids', $ids );
	return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
}

/**
 * מזהי המומחים הלא פעילים. אותו כלל של mc_inactive_experts() אצל המעצב:
 *  - mc_active = '0'
 *  - או mc_subscription_end לא ריק וקטן מהיום (תאריך בפורמט Ymd, לפי current_time('Ymd')).
 * מי שאין לו mc_active בכלל נחשב פעיל (ברירת המחדל של השדה).
 * שאילתה אחת, והתוצאה נשמרת לבקשה הנוכחית.
 *
 * @return int[]
 */
function mcx_inactive_expert_ids() {
	global $wpdb;
	static $cache = array();

	$today = current_time( 'Ymd' );
	if ( isset( $cache[ $today ] ) ) {
		return $cache[ $today ];
	}

	// השוואת מחרוזות בפורמט Ymd (8 ספרות) שקולה להשוואת תאריכים.
	$sql = $wpdb->prepare(
		"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
		 WHERE ( meta_key = 'mc_active' AND meta_value = '0' )
		    OR ( meta_key = 'mc_subscription_end' AND meta_value <> '' AND meta_value < %s )",
		$today
	);

	$cache[ $today ] = array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	return $cache[ $today ];
}

/**
 * ממיר ערך עיר למזהה מונח.
 *  - ריק / 0   : בלי סינון עיר (0)
 *  - מספר      : מזהה המונח
 *  - סלאג      : מחפש מונח בקטגוריות
 *  - 'auto'    : הערך מהפילטר mcx_experts_current_city (המעצב מחבר אליו את mc_expert_city)
 *  - לא נמצא   : מחזיר -1, והרשימה תהיה ריקה (לא "כל המומחים")
 *
 * @param mixed $city
 * @return int
 */
function mcx_resolve_city_id( $city ) {
	if ( 'auto' === $city ) {
		$city = apply_filters( 'mcx_experts_current_city', 0 );
	}
	if ( $city instanceof WP_Term ) {
		return (int) $city->term_id;
	}
	if ( '' === $city || null === $city || false === $city || 0 === $city || '0' === $city ) {
		return 0;
	}
	if ( is_numeric( $city ) ) {
		return (int) $city;
	}
	if ( is_string( $city ) ) {
		$term = get_term_by( 'slug', sanitize_title( $city ), 'category' );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
	}
	return -1;
}

/**
 * הפונקציה המרכזית: כל המומחים הפעילים, כולל מומחה בלי כתבות.
 *
 * פרמטרים (הכול אופציונלי):
 *  city       מזהה/סלאג של עיר, או 'auto'. בלי ערך = כל המומחים הפעילים (האתר הכחול).
 *  per_page   כמה בעמוד. 0 או שלילי = הכול.
 *  paged      מספר עמוד (1 ומעלה).
 *  orderby    name | registered | id
 *  order      asc | desc
 *  has_posts  true = רק מומחה עם כתבה מפורסמת (ההתנהגות הישנה של all_users_grid).
 *
 * @param array $args
 * @return array { users: WP_User[], total: int, pages: int }
 */
function mcx_get_active_experts( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'city'      => 0,
			'per_page'  => 24,
			'paged'     => 1,
			'orderby'   => 'name',
			'order'     => 'asc',
			'has_posts' => false,
		)
	);

	$empty   = array( 'users' => array(), 'total' => 0, 'pages' => 0 );
	$city_id = mcx_resolve_city_id( $args['city'] );
	if ( $city_id < 0 ) {
		return $empty;
	}

	$orderby_map = array(
		'name'       => 'display_name',
		'registered' => 'registered',
		'id'         => 'ID',
	);
	$orderby = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'display_name';
	$order   = ( 'DESC' === strtoupper( (string) $args['order'] ) ) ? 'DESC' : 'ASC';

	$query = array(
		'role'        => 'author', // "מומחה" = תפקיד author (שם התצוגה שונה)
		'exclude'     => array_values( array_unique( array_merge( mcx_inactive_expert_ids(), mcx_experts_excluded_ids() ) ) ),
		'orderby'     => $orderby,
		'order'       => $order,
		'count_total' => true,
	);

	$per_page = (int) $args['per_page'];
	if ( $per_page > 0 ) {
		$query['number'] = $per_page;
		$query['paged']  = max( 1, (int) $args['paged'] );
	}

	if ( $city_id > 0 ) {
		// mcity_cities הוא מערך מסודר של מזהים כמחרוזות, ולכן LIKE על "5" (עם מרכאות) לא תופס את "15".
		$query['meta_query'] = array(
			array(
				'key'     => 'mcity_cities',
				'value'   => '"' . $city_id . '"',
				'compare' => 'LIKE',
			),
		);
	}

	if ( $args['has_posts'] ) {
		$query['has_published_posts'] = array( 'post' );
	}

	$query = apply_filters( 'mcx_experts_query_args', $query, $args );

	$user_query = new WP_User_Query( $query );
	$users      = (array) $user_query->get_results();
	$total      = (int) $user_query->get_total();
	$pages      = ( $per_page > 0 ) ? (int) ceil( $total / $per_page ) : ( $total > 0 ? 1 : 0 );

	return array(
		'users' => $users,
		'total' => $total,
		'pages' => $pages,
	);
}

/**
 * ערך שדה של מומחה מתוך usermeta (ACF שומר שדות משתמש בלי קידומת).
 *
 * @param int    $user_id
 * @param string $key
 * @return string
 */
function mcx_expert_field( $user_id, $key ) {
	$value = get_user_meta( $user_id, $key, true );
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) );
	}
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * כרטיס מומחה אחד. מחלקות CSS יציבות, כדי שהמעצב יעצב אותן.
 * אפשר להחליף את כל ה-HTML דרך הפילטר mcx_expert_card_html.
 *
 * @param WP_User $user
 * @return string
 */
function mcx_render_expert_card( $user ) {
	$id         = (int) $user->ID;
	$profession = mcx_expert_field( $id, 'profession' );
	$place      = mcx_expert_field( $id, 'address' ); // שדה חופשי שמשמש כעיר
	$phone      = mcx_expert_field( $id, 'phone' );
	$url        = get_author_posts_url( $id ); // עובר דרך author_link של המעצב, כולל כתובת העיר

	$html  = '<article class="mcx-expert-card" data-expert-id="' . esc_attr( $id ) . '">';
	$html .= '<a class="mcx-expert-card__link" href="' . esc_url( $url ) . '">';
	$html .= '<span class="mcx-expert-card__photo">' . get_avatar( $id, 160, '', $user->display_name ) . '</span>';
	$html .= '<span class="mcx-expert-card__name">' . esc_html( $user->display_name ) . '</span>';
	$html .= '</a>';
	if ( '' !== $profession ) {
		$html .= '<div class="mcx-expert-card__profession">' . esc_html( $profession ) . '</div>';
	}
	if ( '' !== $place ) {
		$html .= '<div class="mcx-expert-card__place">' . esc_html( $place ) . '</div>';
	}
	if ( '' !== $phone ) {
		$tel   = preg_replace( '/[^0-9+]/', '', $phone );
		$html .= '<div class="mcx-expert-card__phone"><a href="' . esc_url( 'tel:' . $tel ) . '">' . esc_html( $phone ) . '</a></div>';
	}
	$html .= '</article>';

	return apply_filters( 'mcx_expert_card_html', $html, $user );
}

/**
 * [mcx_experts_list city="" per_page="24" orderby="name" order="asc" has_posts="0" class=""]
 *  city: מזהה או סלאג של עיר, או auto. בלי ערך = כולם.
 * מספור עמודים: ?mx_page=2
 */
function mcx_experts_list_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'city'      => '',
			'per_page'  => 24,
			'orderby'   => 'name',
			'order'     => 'asc',
			'has_posts' => '0',
			'class'     => '',
		),
		$atts,
		'mcx_experts_list'
	);

	$paged = isset( $_GET['mx_page'] ) ? max( 1, absint( wp_unslash( $_GET['mx_page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$result = mcx_get_active_experts(
		array(
			'city'      => $atts['city'],
			'per_page'  => (int) $atts['per_page'],
			'paged'     => $paged,
			'orderby'   => $atts['orderby'],
			'order'     => $atts['order'],
			'has_posts' => in_array( strtolower( (string) $atts['has_posts'] ), array( '1', 'yes', 'true' ), true ),
		)
	);

	$class = trim( 'mcx-experts-list ' . sanitize_html_class( $atts['class'] ) );

	if ( empty( $result['users'] ) ) {
		return '<div class="' . esc_attr( $class ) . ' mcx-experts-list--empty"></div>';
	}

	$html = '<div class="' . esc_attr( $class ) . '">';
	foreach ( $result['users'] as $user ) {
		$html .= mcx_render_expert_card( $user );
	}
	$html .= '</div>';

	if ( $result['pages'] > 1 ) {
		$html .= '<nav class="mcx-experts-pager">' . paginate_links(
			array(
				'base'    => add_query_arg( 'mx_page', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $result['pages'],
				'type'    => 'list',
			)
		) . '</nav>';
	}

	return $html;
}
add_shortcode( 'mcx_experts_list', 'mcx_experts_list_shortcode' );
