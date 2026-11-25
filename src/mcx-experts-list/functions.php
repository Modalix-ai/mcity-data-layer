<?php
/**
 * Theme functions and definitions.
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Enqueue the child theme styles
 */
add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'hello-elementor-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( 'hello-elementor-theme-style' ),
        HELLO_ELEMENTOR_CHILD_VERSION
    );
}, 20 );

/**
 * Enable ACF shortcodes
 */
add_action( 'acf/init', function() {
    if ( function_exists( 'acf_update_setting' ) ) {
        acf_update_setting( 'enable_shortcode', true );
    }
} );

/**
 * Shortcode: [user_acf field="profession"]
 */
add_action( 'init', function() {
    if ( ! shortcode_exists( 'user_acf' ) ) {
        add_shortcode( 'user_acf', function( $atts ) {
            $atts = shortcode_atts( array(
                'field' => 'profession',
            ), $atts );

            $user_id = 0;

            if ( class_exists( '\Elementor\Plugin' ) ) {
                $loop_user_id = apply_filters( 'elementor/loop/post_id', 0 );
                if ( $loop_user_id && get_userdata( $loop_user_id ) ) {
                    $user_id = $loop_user_id;
                }
            }

            if ( ! $user_id ) {
                global $user;
                if ( isset( $user->ID ) ) {
                    $user_id = $user->ID;
                }
            }

            if ( ! $user_id ) {
                $author_id = get_the_author_meta( 'ID' );
                if ( $author_id ) {
                    $user_id = $author_id;
                }
            }

            if ( $user_id && function_exists( 'get_field' ) ) {
                $value = get_field( $atts['field'], 'user_' . $user_id );
                if ( ! empty( $value ) ) {
                    return is_array( $value ) ? implode( ', ', $value ) : $value;
                }
            }

            return '';
        } );
    }
} );

/**
 * Register the author_category taxonomy
 */
add_action( 'init', function() {
    if ( ! taxonomy_exists( 'author_category' ) ) {
        register_taxonomy( 'author_category', array( 'user', 'post' ), array(
            'labels' => array(
                'name'              => 'Author Categories',
                'singular_name'     => 'Author Category',
                'menu_name'         => 'Author Categories',
                'search_items'      => 'Search Categories',
                'all_items'         => 'All Categories',
                'edit_item'         => 'Edit Category',
                'update_item'       => 'Update Category',
                'add_new_item'      => 'Add New Category',
                'new_item_name'     => 'New Category Name',
            ),
            'public'            => true,
            'publicly_queryable' => true,
            'show_ui'           => true,
            'show_in_menu'      => false,
            'show_in_nav_menus' => true,
            'show_admin_column' => false, // Disabled to avoid a conflict with the columns hook
            'hierarchical'      => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'index', 'with_front' => false ), // היה author-category, הפניה 301 ב-Redirection
        ) );
    }
}, 0 );

/**
 * Menu item under "Users"
 */
add_action( 'admin_menu', function() {
    add_users_page(
        'Author Categories',
        'Author Categories',
        'manage_categories',
        'edit-tags.php?taxonomy=author_category'
    );
} );

/**
 * Menu highlighting
 */
add_filter( 'parent_file', function( $parent_file ) {
    global $submenu_file, $current_screen;
    if ( isset( $current_screen->taxonomy ) && $current_screen->taxonomy === 'author_category' ) {
        $parent_file  = 'users.php';
        $submenu_file = 'edit-tags.php?taxonomy=author_category';
    }
    return $parent_file;
} );

/**
 * Columns in the users table (with a safe check)
 */
add_filter( 'manage_users_columns', function( $columns ) {
    $columns['author_category'] = 'Author Categories';
    return $columns;
} );

add_filter( 'manage_users_custom_column', function( $output, $column_name, $user_id ) {
    if ( 'author_category' === $column_name ) {
        if ( taxonomy_exists( 'author_category' ) ) {
            $terms = wp_get_object_terms( $user_id, 'author_category' );

            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                $term_names = array();
                foreach ( $terms as $term ) {
                    $term_names[] = esc_html( $term->name );
                }
                return implode( ', ', $term_names );
            }
        }
        return '<span style="color: #999;">—</span>';
    }

    return $output;
}, 10, 3 );



/**
 * Shortcode that shows ALL users in a 3-column grid, excluding portvbaq_admin
 * Usage: [all_users_grid]
 */
add_action( 'init', function() {
    if ( ! shortcode_exists( 'all_users_grid' ) ) {
        add_shortcode( 'all_users_grid', function( $atts ) {

            // הגדרת ערכי ברירת מחדל וקבלת פרמטרים מהשורטקוד
            $atts = shortcode_atts( array(
                'limit'  => -1, // -1 מציג את כל המשתמשים (ללא הגבלה)
                'offset' => 0,  // דילוג על X משתמשים ראשונים
            ), $atts, 'all_users_grid' );

            // החרגת המשתמש portvbaq_admin
            $excluded_user = get_user_by( 'login', 'portvbaq_admin' );
            $exclude_ids   = array();

            if ( $excluded_user ) {
                $exclude_ids[] = $excluded_user->ID;
            }

            // שאילתת המשתמשים עם הגבלה, קיזוז וסינון פוסטים מפורסמים
            $users_args = array(
                'exclude'              => $exclude_ids,
                'orderby'              => 'display_name',
                'order'                => 'ASC',
                'has_published_posts' => array( 'post' ), // הצגת משתמשים שיש להם פוסטים מפורסמים בלבד
                'offset'               => intval( $atts['offset'] ),
            );

            // אם הוגדרה הגבלה (Limit) שאינה -1
            if ( intval( $atts['limit'] ) > 0 ) {
                $users_args['number'] = intval( $atts['limit'] );
            }

            $c = is_page() ? mc_expert_city() : null; // תת-עמוד /all_expert/{עיר}/: רק מומחי העיר
            if ( $c ) $users_args['meta_query'] = array( array( 'key' => 'mcity_cities', 'value' => '"' . $c->term_id . '"', 'compare' => 'LIKE' ) );
            $users = get_users( $users_args );

            if ( empty( $users ) ) {
                return '<p>לא נמצאו משתמשים.</p>';
            }

            ob_start();
            ?>
            <div class="custom-user-grid">
                <?php foreach ( $users as $user ) : 
                    $avatar      = get_avatar_url( $user->ID, array( 'size' => 300 ) );
                    $author_link = get_author_posts_url( $user->ID );
                    
                    $profession  = function_exists( 'get_field' ) ? get_field( 'profession', 'user_' . $user->ID ) : ''; 
                    if ( empty( $profession ) ) {
                        $profession = get_user_meta( $user->ID, 'profession', true );
                    }
                ?>
                    <div class="custom-user-card">
                        <a href="<?php echo esc_url( $author_link ); ?>" class="custom-user-avatar-wrap">
                            <img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
                        </a>
                        <h3 class="custom-user-name">
                            <a href="<?php echo esc_url( $author_link ); ?>">
                                <?php echo esc_html( $user->display_name ); ?>
                            </a>
                        </h3>
                        <?php if ( ! empty( $profession ) ) : ?>
                            <div class="custom-user-profession"><?php echo esc_html( $profession ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <style>
                .custom-user-grid {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 20px 20px;
                    direction: rtl;
                    width: 100%;
                    justify-items: center;
                }
                .custom-user-card {
                    display: flex;
                    flex-direction: column;
                    align-items: flex-start;
                    text-align: right;
                    background: transparent;
                    width: 100%;
                    max-width: 370px;
                }
                .custom-user-avatar-wrap {
                    display: block;
                    width: 100%;
                    aspect-ratio: 4 / 2.5;
                    border-radius: 2em;
                    overflow: hidden;
                    margin-bottom: 8px;
                }
                .custom-user-avatar-wrap img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                    transition: transform 0.3s ease;
                }
                .custom-user-card:hover .custom-user-avatar-wrap img {
                    transform: scale(1.04);
                }
                .custom-user-name {
                    font-family: "futurism";
                    font-size: 1.4vw;
                    font-weight: 600;
                    line-height: 1.2;
                    margin: 0 0 3px 0;
                    color: #000000;
                }
                .custom-user-name a {
                    text-decoration: none;
                    color: inherit;
                }
                .custom-user-profession {
                    font-family: "polin";
                    font-size: 1vw;
                    color: #000000;
                    line-height: 1.2;
                }

                @media (max-width: 576px) {
                    .custom-user-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                        gap: 20px 15px;
                    }
                    .custom-user-avatar-wrap {
                        border-radius: 24px;
                    }
                    .custom-user-card {
                        max-width: 100%;
                    }
                    .custom-user-name {
                        font-size: 20px;
                    }
                    .custom-user-profession {
                        font-size: 16px;
                    }
                }
            </style>
            <?php
            return ob_get_clean();
        } );
    }
} );

///////////////////////////////////


// רשימת סינון לפי "קטגוריות נוספות" בעמוד כל הפוסטים
add_action( 'restrict_manage_posts', function ( $post_type ) {
    if ( $post_type !== 'post' ) return;

    $tax = 'cat-2'; // ← החליפי למפתח שהעתקת
    $taxonomy = get_taxonomy( $tax );
    if ( ! $taxonomy ) return;

    wp_dropdown_categories( [
        'show_option_all' => 'כל ה' . $taxonomy->labels->name,
        'taxonomy'        => $tax,
        'name'            => 'extra_tax_filter',
        'value_field'     => 'slug',
        'selected'        => isset( $_GET['extra_tax_filter'] ) ? sanitize_text_field( $_GET['extra_tax_filter'] ) : '',
        'hierarchical'    => true,
        'show_count'      => true,
        'hide_empty'      => false,
        'orderby'         => 'name',
    ] );
} );

// הפעלת הסינון בפועל
add_action( 'pre_get_posts', function ( $query ) {
    global $pagenow;
    if ( ! is_admin() || $pagenow !== 'edit.php' || ! $query->is_main_query() ) return;
    if ( empty( $_GET['extra_tax_filter'] ) ) return;

    $query->set( 'tax_query', [ [
        'taxonomy' => 'cat-2', // ← אותו מפתח גם כאן
        'field'    => 'slug',
        'terms'    => sanitize_text_field( $_GET['extra_tax_filter'] ),
        'include_children' => true,
    ] ] );
} );


// // // // // // // // // // // 
function acf_profession_shortcode() {
    $user_id = get_the_author_meta('ID');
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    return esc_html( get_field('profession', 'user_' . $user_id) );
}
add_shortcode( 'acf_profession', 'acf_profession_shortcode' );

// לינק לכרטיס ביקור של כותב הכתבה (לכפתור "לפרטים" בנטוורקינג)
function acf_business_card_shortcode() {
    $user_id = get_the_author_meta('ID');
    return $user_id ? esc_url( get_field('url_business_card', 'user_' . $user_id) ) : '';
}
add_shortcode( 'acf_business_card', 'acf_business_card_shortcode' );




// // // // // // // // // // // // // // // // // // // // // //

add_action( 'init', function() {
    if ( ! shortcode_exists( 'users_by_taxonomy' ) ) {
        add_shortcode( 'users_by_taxonomy', function( $atts ) {

            $atts = shortcode_atts( array(
                'taxonomy' => 'author_category', 
                'term'     => '',                
                'limit'    => 4,                 
                'offset'   => 0,  
            ), $atts, 'users_by_taxonomy' );

            if ( empty( $atts['term'] ) ) {
                return '<p-----</p>';
            }

            // החרגת המנהל
            $excluded_user = get_user_by( 'login', 'portvbaq_admin' );
            $exclude_ids   = array();
            if ( $excluded_user ) {
                $exclude_ids[] = $excluded_user->ID;
            }

            $term_obj = get_term_by( 'slug', $atts['term'], sanitize_text_field( $atts['taxonomy'] ) );
            if ( ! $term_obj ) {
                return '<p>הקטגוריה/טרם המבוקש לא נמצאו.</p>';
            }

            $user_ids = get_objects_in_term( $term_obj->term_id, sanitize_text_field( $atts['taxonomy'] ) );
            
            if ( empty( $user_ids ) ) {
                return '<p>לא נמצאו משתמשים המשויכים לטרם זה.</p>';
            }

            if ( ! empty( $exclude_ids ) ) {
                $user_ids = array_diff( $user_ids, $exclude_ids );
            }

            if ( empty( $user_ids ) ) {
                return '<p>לא נמצאו משתמשים בקטגוריה זו.</p>';
            }

            $users_args = array(
                'include' => $user_ids,
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'offset'  => intval( $atts['offset'] ),
            );

            if ( intval( $atts['limit'] ) > 0 ) {
                $users_args['number'] = intval( $atts['limit'] );
            }

            $users = get_users( $users_args );

            if ( empty( $users ) ) {
                return '<p>לא נמצאו משתמשים בקטגוריה זו.</p>';
            }

            ob_start();
            ?>
            <div class="custom-user-grid">
                <?php foreach ( $users as $user ) : 
                    $avatar      = get_avatar_url( $user->ID, array( 'size' => 300 ) );
                    $author_link = get_author_posts_url( $user->ID );
                    
                    // שליפת מקצוע
                    $profession = function_exists( 'get_field' ) ? get_field( 'profession', 'user_' . $user->ID ) : ''; 
                    if ( empty( $profession ) ) {
                        $profession = get_user_meta( $user->ID, 'profession', true );
                    }

                    // שליפת טלפון (ACF או מטא רגיל)
                    $phone = function_exists( 'get_field' ) ? get_field( 'phone', 'user_' . $user->ID ) : '';
                    if ( empty( $phone ) ) {
                        $phone = get_user_meta( $user->ID, 'phone', true );
                    }

                    // שליפת כתובת (ACF או מטא רגיל)
                    $address = function_exists( 'get_field' ) ? get_field( 'address', 'user_' . $user->ID ) : '';
                    if ( empty( $address ) ) {
                        $address = get_user_meta( $user->ID, 'address', true );
                    }
                ?>
                    <div class="custom-user-card">
                        <a href="<?php echo esc_url( $author_link ); ?>" class="custom-user-avatar-wrap">
                            <img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
                        </a>
                        <h3 class="custom-user-name">
                            <a href="<?php echo esc_url( $author_link ); ?>">
                                <?php echo esc_html( $user->display_name ); ?>
                            </a>
                        </h3>
                        <?php if ( ! empty( $profession ) ) : ?>
                            <div class="custom-user-profession"><?php echo esc_html( $profession ); ?></div>
                        <?php endif; ?>
                        
                        <?php if ( ! empty( $address ) || ! empty( $phone ) ) : ?>
                            <div class="custom-user-meta-row">
                                <?php if ( ! empty( $address ) ) : ?>
                                    <span class="custom-user-address"><?php echo esc_html( $address ); ?></span>
                                <?php endif; ?>

                                <?php if ( ! empty( $address ) && ! empty( $phone ) ) : ?>
                                    <span class="custom-user-meta-sep">&nbsp;&nbsp;|&nbsp;&nbsp;</span>
                                <?php endif; ?>

                                <?php if ( ! empty( $phone ) ) : ?>
                                    <span class="custom-user-phone">
                                        <a href="tel:<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( $phone ); ?></a>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <style>
                .custom-user-grid {
                    display: grid;
                    grid-template-columns: repeat(4, minmax(0, 1fr));
                    gap: 20px 20px;
                    direction: rtl;
                    width: 100%;
                    justify-items: center;
                }
                .custom-user-card {
                    display: flex;
                    flex-direction: column;
                    align-items: flex-start;
                    text-align: right;
                    background: transparent;
                    width: 100%;
                    max-width: 370px;
                }
                .custom-user-avatar-wrap {
                    display: block;
                    width: 100%;
                    aspect-ratio: 4 / 2.5;
                    border-radius: 2em;
                    overflow: hidden;
                    margin-bottom: 8px;
                }
                .custom-user-avatar-wrap img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                    transition: transform 0.3s ease;
                }
                .custom-user-card:hover .custom-user-avatar-wrap img {
                    transform: scale(1.04);
                }
                .custom-user-name {
                    font-family: "futurism";
                    font-size: 1.4vw;
                    font-weight: 600;
                    line-height: 1.2;
                    margin: 0 0 3px 0;
                    color: #ffffff;
                }
                .custom-user-name a {
                    text-decoration: none;
                    color: inherit;
                }
                .custom-user-profession {
                    font-family: "polin";
                    font-size: 1vw;
                    color: #ffffff;
                    line-height: 1.2;
                    margin-bottom: 3px;
                }
                .custom-user-meta-row {
                    font-family: "polin";
                    font-size: 0.9vw;
                    color: #ffffff;
                    line-height: 1.2;
                    margin-top: 2px;
                    display: flex;
                    flex-wrap: nowrap;
                    align-items: center;
                    justify-content: center;
                    white-space: nowrap;
                }
                .custom-user-address,
                .custom-user-meta-sep,
                .custom-user-phone {
                    display: inline-block;
                }
                .custom-user-phone a {
                    text-decoration: none;
                    color: inherit;
                }
                @media (max-width: 576px) {
                    .custom-user-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                        gap: 20px 15px;
                    }
                    .custom-user-avatar-wrap {
                        border-radius: 24px;
                    }
                    .custom-user-card {
                        max-width: 100%;
                    }
                    .custom-user-name {
                        font-size: 20px;
                    }
                    .custom-user-profession {
                        font-size: 16px;
                    }
                    .custom-user-meta-row {
                        font-size: 14px; flex-wrap: wrap;
                    }
                }
            </style>
            <?php
            return ob_get_clean();
        } );
    }
} );





////////////////////////////////////////////////////////////////
add_action( 'init', function() {
    if ( ! shortcode_exists( 'users_grid_custom' ) ) {
        add_shortcode( 'users_grid_custom', function( $atts ) {

            $atts = shortcode_atts( array(
                'taxonomy'  => 'author_category', // טקסונומיה ראשית
                'term'      => '',                // טרם ראשי (חובה)
                'taxonomy2' => 'category',        // טקסונומיה שנייה (למשל עיר - אופציונלי)
                'term2'     => '',                // טרם שני (למשל ashdod - אופציונלי)
                'limit'     => 4,                 // מספר כרטיסים להצגה
                'offset'    => 0,                 // דילוג על כרטיסים
            ), $atts, 'users_grid_custom' );

            if ( empty( $atts['term'] ) ) {
                return '<p>נא לציין טרם (term) בשורטקוד.</p>';
            }

            // החרגת המנהל
            $excluded_user = get_user_by( 'login', 'portvbaq_admin' );
            $exclude_ids   = array();
            if ( $excluded_user ) {
                $exclude_ids[] = $excluded_user->ID;
            }

            // שליפת מזהי המשתמשים המשויכים לטרם הראשי
            $term_obj = get_term_by( 'slug', $atts['term'], sanitize_text_field( $atts['taxonomy'] ) );
            if ( ! $term_obj ) {
                return '<p>הקטגוריה/טרם המבוקש לא נמצאו.</p>';
            }

            $user_ids = get_objects_in_term( $term_obj->term_id, sanitize_text_field( $atts['taxonomy'] ) );
            
            if ( empty( $user_ids ) || ! is_array( $user_ids ) ) {
                return '<p>לא נמצאו משתמשים המשויכים לטרם זה.</p>';
            }

            // אם הוגדרה גם קטגוריה שנייה (למשל עיר - term2), נבצע חיתוך (Intersection)
            if ( ! empty( $atts['term2'] ) ) {
                $term_obj2 = get_term_by( 'slug', $atts['term2'], sanitize_text_field( $atts['taxonomy2'] ) );
                if ( $term_obj2 ) {
                    $user_ids2 = get_objects_in_term( $term_obj2->term_id, sanitize_text_field( $atts['taxonomy2'] ) );
                    if ( ! empty( $user_ids2 ) && is_array( $user_ids2 ) ) {
                        $user_ids = array_intersect( $user_ids, $user_ids2 );
                    } else {
                        return '<p>לא נמצאו משתמשים התואמים את שתי הקטגוריות.</p>';
                    }
                } else {
                    return '<p>הקטגוריה השנייה (עיר) לא נמצאה.</p>';
                }
            }

            if ( empty( $user_ids ) ) {
                return '<p>לא נמצאו משתמשים.</p>';
            }

            // החרגת מנהל האתר
            if ( ! empty( $exclude_ids ) ) {
                $user_ids = array_diff( $user_ids, $exclude_ids );
            }

            if ( empty( $user_ids ) ) {
                return '<p>לא נמצאו משתמשים בקטגוריה זו.</p>';
            }

            // איפוס מפתחות המערך למניעת בעיות בשליפה
            $user_ids = array_values( array_unique( $user_ids ) );

            $users_args = array(
                'include' => $user_ids,
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'offset'  => intval( $atts['offset'] ),
            );

            if ( intval( $atts['limit'] ) > 0 ) {
                $users_args['number'] = intval( $atts['limit'] );
            }

            $users = get_users( $users_args );

            if ( empty( $users ) ) {
                return '<p>לא נמצאו משתמשים בקטגוריה זו.</p>';
            }

            ob_start();
            ?>
            <div class="mcity-users-custom-grid">
                <?php foreach ( $users as $user ) : 
                    $avatar      = get_avatar_url( $user->ID, array( 'size' => 400 ) );
                    $author_link = get_author_posts_url( $user->ID );
                    
                    // מקצוע
                    $profession  = function_exists( 'get_field' ) ? get_field( 'profession', 'user_' . $user->ID ) : ''; 
                    if ( empty( $profession ) ) {
                        $profession = get_user_meta( $user->ID, 'profession', true );
                    }

                    // טלפון
                    $phone = function_exists( 'get_field' ) ? get_field( 'phone', 'user_' . $user->ID ) : '';
                    if ( empty( $phone ) ) {
                        $phone = get_user_meta( $user->ID, 'phone', true );
                    }

                    // כתובת
                    $address = function_exists( 'get_field' ) ? get_field( 'address', 'user_' . $user->ID ) : '';
                    if ( empty( $address ) ) {
                        $address = get_user_meta( $user->ID, 'address', true );
                    }
                ?>
                    <div class="mcity-user-card-item">
                        <!-- תמונה מימין -->
                        <a href="<?php echo esc_url( $author_link ); ?>" class="mcity-user-avatar-wrap">
                            <img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
                        </a>

                        <!-- טקסט משמאל -->
                        <div class="mcity-user-info">
                            <h3 class="mcity-user-name">
                                <a href="<?php echo esc_url( $author_link ); ?>">
                                    <?php echo esc_html( $user->display_name ); ?>
                                </a>
                            </h3>
                            <?php if ( ! empty( $profession ) ) : ?>
                                <div class="mcity-user-profession"><?php echo esc_html( $profession ); ?></div>
                            <?php endif; ?>

                            <?php if ( ! empty( $address ) || ! empty( $phone ) ) : ?>
                                <div class="mcity-user-meta-row">
                                    <?php if ( ! empty( $address ) ) : ?>
                                        <span class="mcity-user-address"><?php echo esc_html( $address ); ?></span>
                                    <?php endif; ?>

                                    <?php if ( ! empty( $address ) && ! empty( $phone ) ) : ?>
                                        <span class="mcity-user-meta-sep">&nbsp;&nbsp;</span>
                                    <?php endif; ?>

                                    <?php if ( ! empty( $phone ) ) : ?>
                                        <span class="mcity-user-phone">
                                            <a href="tel:<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( $phone ); ?></a>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <style>
                .mcity-users-custom-grid {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr) !important;
                    gap: 8px;
                    margin: 15px 0;
                    width: 100%;
                }
                @media (max-width: 1024px) {
                    .mcity-users-custom-grid {
                        grid-template-columns: repeat(3, 1fr) !important;
                    }
                }
                @media (max-width: 768px) {
                    .mcity-users-custom-grid {
                        grid-template-columns: repeat(2, 1fr) !important;
                    }
                }
                .mcity-user-card-item {
                    background: #111;
                    border-radius: 1em;
                    overflow: hidden;
                    color: #ffffff;
                    padding: 12px;
                    display: flex;
                    flex-direction: row-reverse;
                    align-items: center;
                    justify-content: space-between;
                    direction: rtl;
                }
                .mcity-user-avatar-wrap {
                    width: 54%;
                    flex-shrink: 0;
                    margin-left: 12px;
                }
                .mcity-user-avatar-wrap img {
                    width: 100%;
                    height: 80px;
                    border-radius: 1em;
                    object-fit: cover;
                    display: block;
                }
                .mcity-user-info {
                    width: 60%;
                    direction: rtl;
                    text-align: right;
                    overflow: hidden;
                    padding-left: 8px;
                }
                .mcity-user-name {
                    font-size: 15px;
                    line-height: 1.3;
                    margin: 0 0 3px 0;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .mcity-user-name a {
                    color: #ffffff !important;
                    text-decoration: none;
                }
                .mcity-user-profession {
                    font-size: 13px;
                    color: #ffffff;
                    margin: 0 0 3px 0;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .mcity-user-meta-row {
                    font-size: 12px;
                    color: #cccccc;
                    line-height: 1.2;
                    margin-top: 2px;
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    font-weight: normal;
                }
                .mcity-user-address,
                .mcity-user-meta-sep,
                .mcity-user-phone {
                    display: inline-block;
                    font-weight: normal;
                }
                .mcity-user-phone a {
                    color: inherit;
                    text-decoration: none;
                    font-weight: normal;
                }
            </style>
            <?php
            return ob_get_clean();
        } );
    }
} );


///////////// users videos /////////////////////////

add_action( 'init', function() {
    if ( ! shortcode_exists( 'user_videos_grid' ) ) {
        add_shortcode( 'user_videos_grid', function() {
            
            $user_id = get_queried_object_id();
            if ( ! $user_id ) {
                return '';
            }

            // שליפת הסרטונים שנבחרו בפרופיל המשתמש
            $selected_videos = get_field( 'user_videos', 'user_' . $user_id );

            if ( empty( $selected_videos ) ) {
                return '<p>לא נמצאו סרטונים למשתמש זה.</p>';
            }

            $videos_data = array();

            foreach ( $selected_videos as $video_post ) {
                $post_id   = is_object( $video_post ) ? $video_post->ID : $video_post;
                $title     = get_the_title( $post_id );
                $thumb_url = get_the_post_thumbnail_url( $post_id, 'medium' );
                
                // שליפת כתובת הוידאו מהפוסט (שדה ACF בשם video_url)
                $video_url = get_field( 'video_link', $post_id );
                
                $embed_html = '';

                if ( ! empty( $video_url ) ) {
                    // המרת כתובת יוטיוב ל-Embed תקין
                    if ( preg_match('/(youtube\.com|youtu\.be)\/(watch\?v=|embed\/|v\/)?([a-zA-Z0-9_-]{11})/i', $video_url, $matches) ) {
                        $youtube_id = $matches[3];
                        $embed_html = '<iframe src="https://www.youtube.com/embed/' . $youtube_id . '?autoplay=0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen style="width:100%; height:100%; border:0;"></iframe>';
                    } 
                    // המרת כתובת וימאו ל-Embed תקין
                    elseif ( preg_match('/vimeo\.com\/([0-9]+)/i', $video_url, $matches) ) {
                        $vimeo_id = $matches[1];
                        $embed_html = '<iframe src="https://player.vimeo.com/video/' . $vimeo_id . '?autoplay=0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen style="width:100%; height:100%; border:0;"></iframe>';
                    } 
                    else {
                        $embed_html = '<div style="padding:40px; text-align:center; color:#fff;">פורמט וידאו אינו נתמך</div>';
                    }
                } else {
                    $embed_html = '<div style="padding:40px; text-align:center; color:#fff;">לא הוגדר קישור לסרטון זה</div>';
                }

                $videos_data[] = array(
                    'title'  => $title,
                    'thumb'  => $thumb_url ? $thumb_url : '',
                    'embed'  => $embed_html
                );
            }

            $first_video = $videos_data[0];

            ob_start();
            ?>
            <div class="mcity-playlist-container" dir="rtl">
                <!-- צד ימין: הנגן הראשי -->
                <div class="mcity-playlist-main-player">
                    <h3 class="mcity-main-video-title"><?php echo esc_html( $first_video['title'] ); ?></h3>
                    <div class="mcity-player-wrapper">
                        <div class="mcity-player-inner" style="width:100%; height:100%;">
                            <?php echo $first_video['embed']; ?>
                        </div>
                    </div>
                </div>

                <!-- צד שמאל: רשימת הסרטונים -->
                <div class="mcity-playlist-sidebar">
                    <div class="mcity-playlist-items">
                        <?php foreach ( $videos_data as $index => $vid ) : 
                            $active_class = ( $index === 0 ) ? ' active' : '';
                        ?>
                            <div class="mcity-playlist-item<?php echo $active_class; ?>" data-embed='<?php echo esc_attr( $vid['embed'] ); ?>' data-title="<?php echo esc_attr( $vid['title'] ); ?>">
                                <?php if ( $vid['thumb'] ) : ?>
                                    <div class="mcity-item-thumb">
                                        <img src="<?php echo esc_url( $vid['thumb'] ); ?>" alt="<?php echo esc_attr( $vid['title'] ); ?>">
                                        <span class="mcity-play-icon">▶</span>
                                    </div>
                                <?php endif; ?>
                                <div class="mcity-item-info">
                                    <h4><?php echo esc_html( $vid['title'] ); ?></h4>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <style>
/* מיכל ראשי */
.mcity-playlist-container {
    display: flex !important;
    flex-direction: row !important;
    gap: 20px !important;
    background: #121212 !important;
    border-radius: 2em !important;
    padding: 20px !important;
    color: #fff !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

.mcity-playlist-main-player {
    flex: 2 !important;
    display: flex !important;
    flex-direction: column !important;
}

.mcity-player-wrapper {
    position: relative !important;
    width: 100% !important;
    aspect-ratio: 16 / 9 !important;
    background: #000 !important;
    border-radius: 2em !important;
    overflow: hidden !important;
    bottom: 21px;
}

.mcity-player-wrapper iframe {
    width: 100% !important;
    height: 100% !important;
    border: none !important;
    display: block !important;
}

.mcity-main-video-title {
    font-size: 18px !important;
    margin: 0px 0 0 0 !important;
    color: #fff !important;
    padding-bottom: 0px;
    position: relative;
    bottom: 33px;
}
/* סרגל צד של הפלייליסט */
.mcity-playlist-sidebar {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    border-radius: 2em  !important;
    /*background: #fff !important;*/
    overflow: hidden !important;
}

/* כותרת רשימת השמעה עליונה על רקע כחול */
.mcity-playlist-sidebar::before {
    content: "רשימת השמעה" !important;
    display: block !important;
    background-color: #043558 !important;
    color: #ffffff !important;
    padding: 12px 16px !important;
    font-size: 15px !important;
    font-weight: bold !important;
    flex-shrink: 0 !important;
}

/* אזור גלילה ייעודי לרשימת הסרטונים */
.mcity-playlist-items-wrapper,
.mcity-playlist-items {
    max-height: 500px !important;
    overflow-y: scroll !important; /* אילוץ הצגת פס גלילה */
    padding: 0px !important;
    display: flex !important;
    flex-direction: column !important;
    gap:0px !important;
    background: #fff!important;
}

/* עיצוב מתקדם ובולט לפס הגלילה */
.mcity-playlist-sidebar::-webkit-scrollbar,
.mcity-playlist-items-wrapper::-webkit-scrollbar,
.mcity-playlist-items::-webkit-scrollbar {
    width: 10px !important; /* רוחב מודגש לפס הגלילה */
    display: block !important;
}

.mcity-playlist-sidebar::-webkit-scrollbar-track,
.mcity-playlist-items-wrapper::-webkit-scrollbar-track,
.mcity-playlist-items::-webkit-scrollbar-track {
    background: #eeeded !important;
}

.mcity-playlist-sidebar::-webkit-scrollbar-thumb,
.mcity-playlist-items-wrapper::-webkit-scrollbar-thumb,
.mcity-playlist-items::-webkit-scrollbar-thumb {
    background: #9c9c9c !important; /* פס גלילה בצבע תכלת-כחול בולט ויפה */
    border-radius: 0px !important;
}

.mcity-playlist-sidebar::-webkit-scrollbar-thumb:hover,
.mcity-playlist-items-wrapper::-webkit-scrollbar-thumb:hover,
.mcity-playlist-items::-webkit-scrollbar-thumb:hover {
    background: #5e5f5f !important;
}

/* פריט וידאו בודד ברשימה - ללא הובר */
.mcity-playlist-item {
    display: flex !important;
    gap: 12px !important;
    background: #fff !important;
    padding: 8px !important;
    border-radius: 0em !important;
    cursor: pointer !important;
    border-bottom: 1px solid #3333331a !important;
    border-top: 1px solid #3333331a !important;
    align-items: center !important;
}

.mcity-playlist-item:hover {
    background: #f3f3f3 !important;
}

.mcity-playlist-item.active {
    border-color: #1b4d8e5b !important;
    background: #e1e1e1 !important;
}

.mcity-item-thumb {
    position: relative !important;
    width: 110px !important;
    aspect-ratio: 16 / 9 !important;
    flex-shrink: 0 !important;
    border-radius: 0px !important;
    overflow: hidden !important;
    background: #000 !important;
}

.mcity-item-thumb img {
    width: 100% !important;
    height: 100% !important;
    object-fit: cover !important;
    display: block !important;
}

.mcity-play-icon {
    position: absolute !important;
    top: 50% !important;
    left: 50% !important;
    transform: translate(-50%, -50%) !important;
    background: rgba(0,0,0,0.6) !important;
    color: #fff !important;
    font-size: 12px !important;
    padding: 4px 8px !important;
    border-radius: 50% !important;
    opacity: 0.8 !important;
}

/* הקטנת גודל הפונט של שמות הסרטונים ברשימה */
.mcity-item-info h4,
.mcity-playlist-item h4,
.mcity-playlist-item span {
    font-size: 12px !important;
    color: #070707 !important;
    line-height: 1.2 !important;
}

@media (max-width: 768px) {
    .mcity-playlist-container {
        flex-direction: column !important;
        padding: 0px!important;;
        border-radius: 24px;
        gap: 10px !important;
    }
    .elementor-888 .elementor-element.elementor-element-d4e7d36 {
    --display: flex;
    --padding-top: 1vw;
    --padding-bottom: 1vw;
    --padding-left: 0vw;
    --padding-right:  0vw;
}
    .mcity-playlist-items-wrapper, 
    .mcity-playlist-items {
    max-height: 270px !important;
    overflow-y: scroll !important; /* אילוץ הצגת פס גלילה */
    }
    .mcity-player-wrapper {
    position: relative !important;
    bottom: 0px;
}
    .mcity-main-video-title {
    font-size: 15px !important;
    padding-right: 20px;
    bottom: 0px;
    padding-bottom:18px;
}
}
</style>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const items = document.querySelectorAll('.mcity-playlist-item');
                    const playerInner = document.querySelector('.mcity-player-inner');
                    const mainTitle = document.querySelector('.mcity-main-video-title');

                    if (!playerInner) return;

                    items.forEach(item => {
                        item.addEventListener('click', function() {
                            items.forEach(i => i.classList.remove('active'));
                            this.classList.add('active');

                            const embedHtml = this.getAttribute('data-embed');
                            const videoTitle = this.getAttribute('data-title');

                            if (mainTitle) {
                                mainTitle.textContent = videoTitle;
                            }

                            if (playerInner) {
                                playerInner.innerHTML = embedHtml;
                            }
                        });
                    });
                });
            </script>
            <?php
            return ob_get_clean();
        } );
    }
});

///////////////////////////////////////////////////////


// סינון "כל המומחים" ברשימת הפוסטים, עם מספר הכתבות לכל מומחה
add_action( 'restrict_manage_posts', function ( $post_type ) {
    if ( 'post' !== $post_type ) {
        return;
    }
    $experts = get_users( array(
        'role__not_in' => array( 'administrator' ),
        'orderby'      => 'display_name',
    ) );
    if ( ! $experts ) {
        return;
    }
    $counts  = count_many_users_posts( wp_list_pluck( $experts, 'ID' ), 'post' );
    $current = isset( $_GET['author'] ) ? (int) $_GET['author'] : 0;
    echo '<select name="author">';
    echo '<option value="">כל המומחים</option>';
    $only = '-' . implode( ',-', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ); // author=-1,-6,... = בלי מנהלים
    printf( '<option value="%s"%s>כתבות מומחים בלבד (%d)</option>', esc_attr( $only ), selected( isset( $_GET['author'] ) ? sanitize_text_field( wp_unslash( $_GET['author'] ) ) : '', $only, false ), array_sum( $counts ) );
    foreach ( $experts as $expert ) {
        printf(
            '<option value="%d"%s>%s (%d)</option>',
            $expert->ID,
            selected( $current, $expert->ID, false ),
            esc_html( $expert->display_name ),
            (int) ( $counts[ $expert->ID ] ?? 0 )
        );
    }
    echo '</select>';
} );

////////////////////////////////////

add_action( 'init', function() {
    if ( ! shortcode_exists( 'users_by_two_taxonomies' ) ) {
        add_shortcode( 'users_by_two_taxonomies', function( $atts ) {

            $atts = shortcode_atts( array(
                'taxonomy1' => 'author_category', // טקסונומיה ראשונה (למשל סוג משתמש)
                'term1'     => '',                // טרם ראשון (למשל business)
                'taxonomy2' => 'category',        // טקסונומיה שנייה (למשל עיר / קטגוריה)
                'term2'     => '',                // טרם שני (למשל ashdod)
                'limit'     => 4,                 
                'offset'    => 0,  
            ), $atts, 'users_by_two_taxonomies' );

            // בדיקה ששני הטרמים הוזנו
            if ( empty( $atts['term1'] ) || empty( $atts['term2'] ) ) {
                return '<p>נא לציין את שני הטרמים בשורטקוד, לדוגמה: [users_by_two_taxonomies term1="business" term2="ashdod"]</p>';
            }

            // החרגת המנהל
            $excluded_user = get_user_by( 'login', 'portvbaq_admin' );
            $exclude_ids   = array();
            if ( $excluded_user ) {
                $exclude_ids[] = $excluded_user->ID;
            }

            // שליפת משתמשים לפי קטגוריה ראשונה
            $term_obj1 = get_term_by( 'slug', $atts['term1'], sanitize_text_field( $atts['taxonomy1'] ) );
            if ( ! $term_obj1 ) {
                return '<p>הקטגוריה הראשונה לא נמצאה.</p>';
            }
            $user_ids1 = get_objects_in_term( $term_obj1->term_id, sanitize_text_field( $atts['taxonomy1'] ) );

            // שליפת משתמשים לפי קטגוריה שנייה
            $term_obj2 = get_term_by( 'slug', $atts['term2'], sanitize_text_field( $atts['taxonomy2'] ) );
            if ( ! $term_obj2 ) {
                return '<p>הקטגוריה השנייה לא נמצאה.</p>';
            }
            $user_ids2 = get_objects_in_term( $term_obj2->term_id, sanitize_text_field( $atts['taxonomy2'] ) );

            if ( empty( $user_ids1 ) || empty( $user_ids2 ) ) {
                return '<p>לא נמצאו משתמשים המשויכים לשתי הקטגוריות יחד.</p>';
            }

            // חיתוך (Intersection) - משתמשים שמופיעים בשתי הקטגוריות גם יחד!
            $user_ids = array_intersect( $user_ids1, $user_ids2 );

            if ( empty( $user_ids ) ) {
                return '<p>לא נמצאו משתמשים בקטגוריות אלו.</p>';
            }

            // החרגת מנהל האתר
            if ( ! empty( $exclude_ids ) ) {
                $user_ids = array_diff( $user_ids, $exclude_ids );
            }

            if ( empty( $user_ids ) ) {
                return '<p>לא נמצאו משתמשים בקטגוריה זו.</p>';
            }

            $users_args = array(
                'include' => $user_ids,
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'offset'  => intval( $atts['offset'] ),
            );

            if ( intval( $atts['limit'] ) > 0 ) {
                $users_args['number'] = intval( $atts['limit'] );
            }

            $users = get_users( $users_args );

            if ( empty( $users ) ) {
                return '<p>לא נמצאו משתמשים בקטגוריה זו.</p>';
            }

            ob_start();
            ?>
            <div class="custom-user-grid">
                <?php foreach ( $users as $user ) : 
                    $avatar      = get_avatar_url( $user->ID, array( 'size' => 300 ) );
                    $author_link = get_author_posts_url( $user->ID );
                    
                    // שליפת מקצוע
                    $profession = function_exists( 'get_field' ) ? get_field( 'profession', 'user_' . $user->ID ) : ''; 
                    if ( empty( $profession ) ) {
                        $profession = get_user_meta( $user->ID, 'profession', true );
                    }

                    // שליפת טלפון
                    $phone = function_exists( 'get_field' ) ? get_field( 'phone', 'user_' . $user->ID ) : '';
                    if ( empty( $phone ) ) {
                        $phone = get_user_meta( $user->ID, 'phone', true );
                    }

                    // שליפת כתובת
                    $address = function_exists( 'get_field' ) ? get_field( 'address', 'user_' . $user->ID ) : '';
                    if ( empty( $address ) ) {
                        $address = get_user_meta( $user->ID, 'address', true );
                    }
                ?>
                    <div class="custom-user-card">
                        <a href="<?php echo esc_url( $author_link ); ?>" class="custom-user-avatar-wrap">
                            <img src="<?php echo esc_url( $avatar ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
                        </a>
                        <h3 class="custom-user-name">
                            <a href="<?php echo esc_url( $author_link ); ?>">
                                <?php echo esc_html( $user->display_name ); ?>
                            </a>
                        </h3>
                        <?php if ( ! empty( $profession ) ) : ?>
                            <div class="custom-user-profession"><?php echo esc_html( $profession ); ?></div>
                        <?php endif; ?>
                        
                        <?php if ( ! empty( $address ) || ! empty( $phone ) ) : ?>
                            <div class="custom-user-meta-row">
                                <?php if ( ! empty( $address ) ) : ?>
                                    <span class="custom-user-address"><?php echo esc_html( $address ); ?></span>
                                <?php endif; ?>

                                <?php if ( ! empty( $address ) && ! empty( $phone ) ) : ?>
                                    <span class="custom-user-meta-sep">&nbsp;&nbsp;|&nbsp;&nbsp;</span>
                                <?php endif; ?>

                                <?php if ( ! empty( $phone ) ) : ?>
                                    <span class="custom-user-phone">
                                        <a href="tel:<?php echo esc_attr( $phone ); ?>"><?php echo esc_html( $phone ); ?></a>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <style>
                .custom-user-grid {
                    display: grid;
                    grid-template-columns: repeat(4, minmax(0, 1fr));
                    gap: 20px 20px;
                    direction: rtl;
                    width: 100%;
                    justify-items: center;
                }
                .custom-user-card {
                    display: flex;
                    flex-direction: column;
                    align-items: flex-start;
                    text-align: right;
                    background: transparent;
                    width: 100%;
                    max-width: 370px;
                }
                .custom-user-avatar-wrap {
                    display: block;
                    width: 100%;
                    aspect-ratio: 4 / 2.5;
                    border-radius: 2em;
                    overflow: hidden;
                    margin-bottom: 8px;
                }
                .custom-user-avatar-wrap img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    display: block;
                    transition: transform 0.3s ease;
                }
                .custom-user-card:hover .custom-user-avatar-wrap img {
                    transform: scale(1.04);
                }
                .custom-user-name {
                    font-family: "futurism";
                    font-size: 1.4vw;
                    font-weight: 600;
                    line-height: 1.2;
                    margin: 0 0 3px 0;
                    color: #ffffff;
                }
                .custom-user-name a {
                    text-decoration: none;
                    color: inherit;
                }
                .custom-user-profession {
                    font-family: "polin";
                    font-size: 1vw;
                    color: #ffffff;
                    line-height: 1.2;
                    margin-bottom: 3px;
                }
                .custom-user-meta-row {
                    font-family: "polin";
                    font-size: 0.9vw;
                    color: #ffffff;
                    line-height: 1.2;
                    margin-top: 2px;
                    display: flex;
                    flex-wrap: nowrap;
                    align-items: center;
                    justify-content: center;
                    white-space: nowrap;
                }
                .custom-user-address,
                .custom-user-meta-sep,
                .custom-user-phone {
                    display: inline-block;
                }
                .custom-user-phone a {
                    text-decoration: none;
                    color: inherit;
                }
                @media (max-width: 576px) {
                    .custom-user-grid {
                        grid-template-columns: repeat(2, minmax(0, 1fr));
                        gap: 20px 15px;
                    }
                    .custom-user-avatar-wrap {
                        border-radius: 24px;
                    }
                    .custom-user-card {
                        max-width: 100%;
                    }
                    .custom-user-name {
                        font-size: 20px;
                    }
                    .custom-user-profession {
                        font-size: 16px;
                    }
                    .custom-user-meta-row {
                        font-size: 14px; flex-wrap: wrap;
                    }
                }
            </style>
            <?php
            return ob_get_clean();
        } );
    }
} );



///////////////////////////////
add_action( 'elementor/query/universal_city_loop', function( $query, $widget ) {
    $queried_object = get_queried_object();

    if ( $queried_object && isset( $queried_object->taxonomy ) && isset( $queried_object->term_id ) ) {
        $taxonomy = $queried_object->taxonomy;
        $term_id  = $queried_object->term_id;

        // בדף הבית של עיר: כל בלוק שומר על הנושא שלו בעיר הנוכחית (haifa-consumption → ashdod-consumption)
        $set = (array) $widget->get_settings( 'post_query_include_term_ids' );
        if ( $set && 'category' === $taxonomy && 0 === (int) $queried_object->parent ) {
            $term_id = array();
            foreach ( $set as $t ) { $a = get_ancestors( $t, 'category' ); $from = get_term( $a ? end( $a ) : $t )->slug; $n = get_term_by( 'slug', $queried_object->slug . substr( get_term( $t )->slug, strlen( $from ) ), 'category' ); if ( $n ) $term_id[] = $n->term_id; }
        }

        $query->set( 'tax_query', array(
            array(
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $term_id,
            ),
        ) );
    }
}, 10, 2 );

// נטוורקינג: כל מומחה פעם אחת (הכתבה האחרונה שלו). Loop Grid עם Query ID = mc_networking
add_action( 'elementor/query/mc_networking', function( $query ) {
    global $wpdb;
    $o = get_queried_object(); // בעיר (קטגוריות 5-18 וילדיהן): רק מומחים עם "שיוך לעיר" לעיר הזו
    $a = $o instanceof WP_Term ? get_ancestors( $o->term_id, 'category' ) : array();
    $city = $o instanceof WP_Term ? (int) ( $a ? end( $a ) : $o->term_id ) : 0;
    $in = ( $city >= 5 && $city <= 18 ) ? $wpdb->prepare( " AND post_author IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='mcity_cities' AND meta_value LIKE %s)", '%"' . $city . '"%' ) : '';
    $ids = $wpdb->get_col( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_author NOT IN (1,6)$in GROUP BY post_author ORDER BY MAX(post_date) DESC LIMIT 20" );
    $query->set( 'post__in', $ids ? $ids : array( 0 ) );
    $query->set( 'orderby', 'post__in' );
} );

// פירורי לחם בכחול: כתבה עם נושא ב"קטגוריות נוספות" (cat-2) מציגה את הנושא במקום "חדשות"
add_filter( 'wpseo_breadcrumb_links', function( $links ) {
    $t = is_singular( 'post' ) ? wp_get_post_terms( get_the_ID(), 'cat-2' ) : array();
    if ( ! $t || is_wp_error( $t ) || count( $links ) < 2 ) return $links;
    usort( $t, function( $a, $b ) { return count( get_ancestors( $b->term_id, 'cat-2' ) ) - count( get_ancestors( $a->term_id, 'cat-2' ) ); } );
    $mid = array();
    foreach ( array_merge( array_reverse( get_ancestors( $t[0]->term_id, 'cat-2' ) ), array( $t[0]->term_id ) ) as $id ) $mid[] = array( 'url' => get_term_link( $id, 'cat-2' ), 'text' => get_term( $id )->name );
    return array_merge( array( $links[0] ), $mid, array( end( $links ) ) );
} );

// עמוד מומחה: באתר הכחול /expert/{שם}/ = תבנית כחולה. באתר עיר /{עיר}/expert/{שם}/ = הדר העיר, פוטר 175 ותבנית מומחה אדומה (5726)
function mc_expert_city() {
	static $c = false;
	if ( false !== $c ) return $c;
	$s = get_query_var( 'mc_city' );
	if ( ! $s ) {
		$o = get_queried_object();
		if ( is_singular( 'post' ) && ! mc_post_is_national( get_queried_object_id() ) ) foreach ( wp_get_post_categories( get_queried_object_id(), array( 'fields' => 'all' ) ) as $t ) if ( $t->term_id >= 5 && $t->term_id <= 18 ) { $o = $t; break; }
		if ( isset( $o->taxonomy ) && in_array( $o->taxonomy, array( 'category', 'section', 'author_category' ), true ) ) { $a = get_ancestors( $o->term_id, $o->taxonomy ); $s = preg_replace( '/^business-/', '', get_term( $a ? end( $a ) : $o->term_id )->slug ); }
		if ( is_page() ) $s = get_post_field( 'post_name', get_queried_object_id() ); // המומחים שלנו בעיר: /all_expert/{עיר}/
	}
	$t = $s ? get_term_by( 'slug', $s, 'category' ) : null;
	return $c = ( $t && $t->term_id >= 5 && $t->term_id <= 18 ) ? $t : null;
}
add_action( 'init', function() {
	add_rewrite_tag( '%mc_city%', '([^/]+)' );
	add_rewrite_rule( '^([^/]+)/expert/([^/]+)/page/([0-9]+)/?$', 'index.php?author_name=$matches[2]&mc_city=$matches[1]&paged=$matches[3]', 'top' );
	add_rewrite_rule( '^([^/]+)/expert/([^/]+)/?$', 'index.php?author_name=$matches[2]&mc_city=$matches[1]', 'top' );
} );
add_filter( 'elementor/theme/get_location_templates/template_id', function( $id, $location ) {
	if ( ! is_author() || ! in_array( $location, array( 'header', 'footer', 'archive' ), true ) || ! ( $c = mc_expert_city() ) ) return $id;
	$h = array( 5 => 152, 6 => 120, 7 => 116, 8 => 144, 9 => 148, 10 => 131, 11 => 160, 12 => 127, 13 => 136, 14 => 101, 15 => 165, 16 => 140, 17 => 272, 18 => 156 );
	return 'footer' === $location ? 175 : ( 'archive' === $location ? 5726 : $h[ $c->term_id ] );
}, 10, 2 );
add_filter( 'author_link', function( $link, $id, $nicename ) {
	return ( is_admin() || ! ( $c = mc_expert_city() ) ) ? $link : home_url( "/{$c->slug}/expert/{$nicename}/" );
}, 10, 3 );
add_filter( 'wpseo_canonical', function( $u ) {
	return is_author() ? home_url( '/expert/' . get_queried_object()->user_nicename . '/' . ( get_query_var( 'paged' ) > 1 ? 'page/' . (int) get_query_var( 'paged' ) . '/' : '' ) ) : $u; // תמיד הכתובת הכחולה (גם כשהקישור השמור של Yoast נבנה בעיר)
} );

// כתובת עמוד מומחה: /expert/{שם} במקום /author/{שם} (הפניה 301 ב-Redirection)
add_action( 'init', function() { global $wp_rewrite; $wp_rewrite->author_base = 'expert'; } );

// מומחה לא פעיל (מתג "מומחה פעיל" כבוי, או "תאריך סיום מנוי" עבר): מוסתר מהאתר – רשימות, כתבות, עמוד מומחה ונבחרת המומחים. כלום לא נמחק; מחדשים מנוי וזה חוזר
function mc_inactive_experts() {
	static $ids = null;
	if ( null === $ids ) {
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE ( meta_key = 'mc_active' AND meta_value = '0' ) OR ( meta_key = 'mc_subscription_end' AND meta_value <> '' AND meta_value < %s )", current_time( 'Ymd' ) ) ) );
	}
	return $ids;
}
function mc_hide_inactive_here() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
	if ( is_admin() && ! ( wp_doing_ajax() && false === strpos( (string) wp_get_referer(), '/wp-admin/' ) ) ) return false;
	return (bool) mc_inactive_experts();
}
add_action( 'pre_get_posts', function( $q ) { // הכתבות שלו לא מופיעות בשום מקום
	if ( mc_hide_inactive_here() && ! ( $q->is_main_query() && $q->is_singular() ) ) $q->set( 'author__not_in', array_merge( (array) $q->get( 'author__not_in' ), mc_inactive_experts() ) ); // כתבה בודדת: לא 404 אלא הפניה (למטה)
} );
add_action( 'pre_get_users', function( $q ) { // הוא לא מופיע ברשימות המומחים
	if ( ! mc_hide_inactive_here() ) return;
	$in = array_filter( (array) $q->get( 'include' ) ); // כשיש include, וורדפרס מתעלם מ-exclude
	if ( $in ) $q->set( 'include', array_diff( array_map( 'intval', $in ), mc_inactive_experts() ) ?: array( 0 ) );
	else $q->set( 'exclude', array_merge( (array) $q->get( 'exclude' ), mc_inactive_experts() ) );
} );
add_action( 'template_redirect', function() { // עמוד המומחה והכתבות שלו מפנים הלאה
	if ( ! mc_hide_inactive_here() ) return;
	if ( is_author() && in_array( (int) get_queried_object_id(), mc_inactive_experts(), true ) ) {
		$c = function_exists( 'mc_expert_city' ) ? mc_expert_city() : null;
		wp_safe_redirect( home_url( $c ? "/all_expert/{$c->slug}/" : '/all_expert/' ), 302 ); exit;
	}
	if ( is_singular( 'post' ) && in_array( (int) get_post_field( 'post_author', get_queried_object_id() ), mc_inactive_experts(), true ) ) {
		$id = get_queried_object_id(); // הנושא הכי מדויק: קודם cat-2 (כחול), אחר כך קטגוריה (עיר), בלי Uncategorized
		$t = array_merge( (array) get_the_terms( $id, 'cat-2' ), array_filter( get_the_category( $id ), function( $c ) { return 1 !== (int) $c->term_id; } ) );
		$t = array_values( array_filter( $t, 'is_object' ) ); usort( $t, function( $a, $b ) { return ( 'cat-2' === $b->taxonomy ) - ( 'cat-2' === $a->taxonomy ) ?: $b->parent - $a->parent; } );
		wp_safe_redirect( $t ? get_term_link( $t[0] ) : home_url( '/' ), 302 ); exit;
	}
} );
add_filter( 'elementor/frontend/container/should_render', function( $show, $el ) { // כרטיס בנבחרת המומחים השבוע
	$d = $el->get_settings( '__dynamic__' );
	if ( ! $show || empty( $d['link'] ) || false === strpos( $d['link'], 'mx_expert_' ) || ! mc_hide_inactive_here() ) return $show;
	$l = $el->get_settings_for_display( 'link' );
	if ( ! empty( $l['url'] ) && preg_match( '#/(?:expert|author)/([^/]+)/?$#', $l['url'], $m ) && ( $u = get_user_by( 'slug', $m[1] ) ) && in_array( (int) $u->ID, mc_inactive_experts(), true ) ) return false;
	return $show;
}, 10, 2 );
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', function( $ids ) { // וגם לא במפת האתר
	return mc_inactive_experts() ? array_merge( (array) $ids, $GLOBALS['wpdb']->get_col( 'SELECT ID FROM ' . $GLOBALS['wpdb']->posts . ' WHERE post_author IN (' . implode( ',', mc_inactive_experts() ) . ')' ) ) : $ids;
} );
add_filter( 'wpseo_sitemap_exclude_author', function( $users ) {
	return array_filter( (array) $users, function( $u ) { return ! in_array( (int) $u->ID, mc_inactive_experts(), true ); } );
} );

// כותרת בגוגל: "כותרת הכתבה - נושא: עיר - MCITY" (למשל "שיפוץ בזול - חדשות: חיפה והקריות - MCITY"). המשתנה %%mc_topic%% ב-Yoast = הנושא הכי מדויק של הכתבה
add_action( 'wpseo_register_extra_replacements', function() {
	wpseo_register_var_replacement( 'mc_topic', function() {
		$id = get_queried_object_id();
		if ( ! $id || 'post' !== get_post_type( $id ) ) return '';
		$t = array_merge( (array) get_the_terms( $id, 'cat-2' ), array_filter( get_the_category( $id ), function( $c ) { return 1 !== (int) $c->term_id; } ) );
		$t = array_values( array_filter( $t, 'is_object' ) ); usort( $t, function( $a, $b ) { return ( 'cat-2' === $b->taxonomy ) - ( 'cat-2' === $a->taxonomy ) ?: $b->parent - $a->parent; } );
		return $t ? $t[0]->name : '';
	}, 'advanced', 'הנושא והעיר של הכתבה' );
} );

// עמודת "צפיות" ברשימת המשתמשים (ניהול): צפיות בעמוד המומחה ובכתבות שלו, מנתוני Burst. 30 יום / סה"כ מאז התקנת Burst, ולחיצה פותחת פירוט לפי כתבה
add_filter( 'manage_users_columns', function( $c ) { $c['mc_views'] = 'צפיות'; return $c; } );
add_filter( 'manage_users_custom_column', function( $out, $col, $uid ) {
	if ( 'mc_views' !== $col ) return $out;
	global $wpdb;
	$t = $wpdb->prefix . 'burst_statistics';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) return '—';
	$u     = get_userdata( $uid );
	$since = time() - 30 * DAY_IN_SECONDS;
	$name  = $wpdb->esc_like( $u->user_nicename );
	$page  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) total, COALESCE(SUM(time > %d),0) month FROM $t WHERE page_type = 'author' AND ( page_url LIKE %s OR page_url LIKE %s )", $since, '%/expert/' . $name . '/%', '%/author/' . $name . '/%' ) );
	$posts = $wpdb->get_results( $wpdb->prepare( "SELECT s.page_id, COUNT(*) total, COALESCE(SUM(s.time > %d),0) month FROM $t s JOIN {$wpdb->posts} p ON p.ID = s.page_id WHERE s.page_type = 'post' AND p.post_author = %d GROUP BY s.page_id ORDER BY total DESC", $since, $uid ) );
	$tot = (int) $page->total + array_sum( array_map( 'intval', wp_list_pluck( $posts, 'total' ) ) );
	$mon = (int) $page->month + array_sum( array_map( 'intval', wp_list_pluck( $posts, 'month' ) ) );
	if ( ! $tot ) return '0';
	$rows = '<li>עמוד המומחה: ' . (int) $page->total . ' (' . (int) $page->month . ' בחודש)</li>';
	foreach ( $posts as $p ) $rows .= '<li><a href="' . esc_url( get_permalink( $p->page_id ) ) . '" target="_blank">' . esc_html( get_the_title( $p->page_id ) ) . '</a>: ' . (int) $p->total . ' (' . (int) $p->month . ' בחודש)</li>';
	return '<details><summary><b>' . $mon . '</b> בחודש · ' . $tot . ' סה"כ</summary><ul style="margin:4px 0 0;font-size:12px">' . $rows . '</ul></details>';
}, 10, 3 );

// ניהול: "מומחים" בתפריט נפרד (מומחה = תפקיד author). "משתמשים" מציג רק את מי שאינו מומחה. רק בניהול, באתר אין שינוי
function mc_is_experts_screen() {
	global $pagenow;
	return ( 'users.php' === $pagenow && 'author' === ( $_GET['role'] ?? '' ) ) || ( 'user-new.php' === $pagenow && ! empty( $_GET['mc_expert'] ) );
}
add_action( 'init', function() {
	if ( ! is_admin() ) return;
	$r = wp_roles();
	if ( isset( $r->roles['author'] ) ) { $r->roles['author']['name'] = 'מומחה'; $r->role_names['author'] = 'מומחה'; }
} );
add_action( 'admin_menu', function() {
	add_menu_page( 'מומחים', 'מומחים', 'list_users', 'users.php?role=author', '', 'dashicons-businessperson', 69 );
	add_submenu_page( 'users.php?role=author', 'כל המומחים', 'כל המומחים', 'list_users', 'users.php?role=author' );
	add_submenu_page( 'users.php?role=author', 'הוספת מומחה', 'הוספת מומחה', 'create_users', 'user-new.php?mc_expert=1' );
} );
add_filter( 'parent_file', function( $f ) {
	global $pagenow, $submenu_file;
	$uid = (int) ( $_GET['user_id'] ?? 0 );
	if ( mc_is_experts_screen() || ( 'user-edit.php' === $pagenow && $uid && in_array( 'author', (array) get_userdata( $uid )->roles, true ) ) ) {
		$submenu_file = 'user-new.php' === $pagenow ? 'user-new.php?mc_expert=1' : 'users.php?role=author';
		return 'users.php?role=author';
	}
	return $f;
} );
add_filter( 'pre_option_default_role', function( $v ) { return ( is_admin() && ! empty( $_GET['mc_expert'] ) ) ? 'author' : $v; } );
add_action( 'admin_menu', function() { // כותרת העמוד "מומחים" – אחרי שהתפריט כבר נבנה, כדי לא לשנות את "משתמשים" בתפריט
	if ( ! mc_is_experts_screen() ) return;
	add_filter( 'gettext', function( $t, $text ) {
		if ( 'Users' === $text ) return 'מומחים';
		if ( 'Add New User' === $text || 'Add User' === $text ) return 'הוספת מומחה';
		return $t;
	}, 10, 2 );
}, 999 );
add_action( 'pre_get_users', function( $q ) {
	global $pagenow;
	if ( ! is_admin() || 'users.php' !== $pagenow || ! empty( $_GET['role'] ) || $q->get( 'role' ) || $q->get( 'role__in' ) ) return;
	$s = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $s || 'users' !== $s->id ) return;
	$q->set( 'role__not_in', array_merge( (array) $q->get( 'role__not_in' ), array( 'author' ) ) );
} );
add_filter( 'views_users', function( $v ) {
	if ( 'author' === ( $_GET['role'] ?? '' ) ) return array();
	unset( $v['author'] );
	$c = count_users();
	if ( isset( $v['all'] ) ) $v['all'] = preg_replace( '/\(\d+\)/', '(' . ( $c['total_users'] - ( $c['avail_roles']['author'] ?? 0 ) ) . ')', $v['all'] );
	return $v;
} );
add_filter( 'manage_users_columns', function( $c ) {
	if ( 'author' !== ( $_GET['role'] ?? '' ) ) return $c;
	return array_slice( $c, 0, 2, true ) + array( 'mc_prof' => 'מקצוע' ) + array_slice( $c, 2, null, true );
} );
add_filter( 'manage_users_custom_column', function( $o, $col, $uid ) {
	return 'mc_prof' === $col ? esc_html( (string) get_user_meta( $uid, 'profession', true ) ) : $o;
}, 10, 3 );

// שיוך לעיר במומחה: ברשימת "ערים" רק 14 הערים (קטגוריות 5–18), בלי נושאים ומדורים
add_filter( 'acf/fields/taxonomy/wp_list_categories/key=field_mcity_cities', function( $args ) { $args['include'] = range( 5, 18 ); $args['hierarchical'] = 0; return $args; } );
add_filter( 'acf/load_field/key=field_mcity_cities', function( $field ) { $field['add_term'] = 0; return $field; } ); // בלי כפתור "+" להוספת קטגוריה מתוך עריכת מומחה

// עמוד מומחה: 9 כתבות בכל טעינה (גם 'טען עוד'). Current Query לוקח את המספר מהשאילתה הראשית, ולכן קובעים כאן
add_action( 'pre_get_posts', function( $q ) { if ( ! is_admin() && $q->is_main_query() && $q->is_author() ) $q->set( 'posts_per_page', 9 ); } );

// עמוד מומחה: כל אתר מציג רק את הכתבות שלו. בעיר (/{עיר}/expert/{שם}/) רק כתבות העיר (כולל תתי-נושאים); בכחול רק כתבות ארציות (נושא cat-2 או חדשות)
add_action( 'pre_get_posts', function( $q ) {
	if ( is_admin() || ! $q->is_main_query() || ! $q->is_author() ) return;
	$c = ( $s = $q->get( 'mc_city' ) ) ? get_term_by( 'slug', $s, 'category' ) : null;
	$q->set( 'tax_query', ( $c && $c->term_id >= 5 && $c->term_id <= 18 ) ? array( array( 'taxonomy' => 'category', 'terms' => $c->term_id ) ) : array( 'relation' => 'OR', array( 'taxonomy' => 'cat-2', 'operator' => 'EXISTS' ), array( 'taxonomy' => 'category', 'terms' => 20 ) ) );
} );

// כתבה לפי האתר שממנו הגיעו: /{עיר}/{כתבה}/ = הכתבה באדום של העיר; /{כתבה}/ = כחול (כתבה ארצית) או העיר הראשונה (כתבה של ערים בלבד). כתבה אחת, קנוניקל אחד
function mc_post_cities( $pid ) { return array_values( array_filter( array_map( 'intval', wp_get_post_categories( $pid ) ), function( $i ) { return $i >= 5 && $i <= 18; } ) ); }
function mc_post_is_national( $pid ) { return in_category( 20, $pid ) || has_term( '', 'cat-2', $pid ); }
add_action( 'init', function() { // /{עיר}/{כתבה}/ – בלי להתנגש בתתי-הקטגוריות של העיר (/haifa/haifa-news/) ובמומחים
	foreach ( get_terms( array( 'taxonomy' => 'category', 'include' => range( 5, 18 ), 'hide_empty' => false ) ) as $c ) {
		$ex = array_merge( get_terms( array( 'taxonomy' => 'category', 'parent' => $c->term_id, 'hide_empty' => false, 'fields' => 'slugs' ) ), array( 'expert', 'page', 'feed', 'embed' ) );
		add_rewrite_rule( '^' . preg_quote( $c->slug, '#' ) . '/(?!(?:' . implode( '|', array_map( function( $s ) { return preg_quote( urldecode( $s ), '#' ); }, $ex ) ) . ')/?$)([^/]+)/?$', 'index.php?name=$matches[1]&mc_city=' . $c->slug, 'top' );
	}
}, 11 );
add_filter( 'post_link', function( $url, $post ) { // באתר עיר: קישור לכתבה של העיר נשאר בעיר
	if ( is_admin() || ! did_action( 'wp' ) || 'post' !== $post->post_type || ! ( $c = mc_expert_city() ) ) return $url;
	return in_array( (int) $c->term_id, mc_post_cities( $post->ID ), true ) ? home_url( '/' . $c->slug . '/' . $post->post_name . '/' ) : $url;
}, 10, 2 );
add_action( 'template_redirect', function() { // עיר שהכתבה לא שייכת אליה → לכתובת הראשית
	if ( ! is_singular( 'post' ) || ! ( $s = get_query_var( 'mc_city' ) ) ) return;
	$c = get_term_by( 'slug', $s, 'category' );
	if ( ! $c || ! in_array( (int) $c->term_id, mc_post_cities( get_queried_object_id() ), true ) ) { wp_safe_redirect( home_url( '/' . get_post_field( 'post_name', get_queried_object_id() ) . '/' ), 301 ); exit; }
} );
add_filter( 'elementor/theme/get_location_templates/template_id', function( $id, $location ) { // הדר, פוטר ותבנית כתבה לפי האתר
	if ( ! is_singular( 'post' ) || ! in_array( $location, array( 'header', 'footer', 'single' ), true ) ) return $id;
	if ( ! ( $c = mc_expert_city() ) ) return array( 'header' => 28, 'footer' => 169, 'single' => 1756 )[ $location ];
	$h = array( 5 => 152, 6 => 120, 7 => 116, 8 => 144, 9 => 148, 10 => 131, 11 => 160, 12 => 127, 13 => 136, 14 => 101, 15 => 165, 16 => 140, 17 => 272, 18 => 156 );
	return 'footer' === $location ? 175 : ( 'single' === $location ? 3147 : $h[ $c->term_id ] );
}, 10, 2 );
add_filter( 'wpseo_canonical', function( $u ) { // גוגל: כתבה אחת – תמיד הכתובת הראשית
	return is_singular( 'post' ) ? home_url( '/' . get_post_field( 'post_name', get_queried_object_id() ) . '/' ) : $u;
} );
add_filter( 'wpseo_opengraph_url', function( $u ) { // שיתוף: הקישור של האתר הנוכחי
	return is_singular( 'post' ) ? get_permalink( get_queried_object_id() ) : ( is_author() ? get_author_posts_url( get_queried_object_id() ) : $u ); // גם עמוד מומחה: הקישור של האתר הנוכחי
} );
add_action( 'elementor/query/mc_site_posts', function( $q ) { // "עוד כתבות": רק מהאתר הנוכחי
	$t = (array) $q->get( 'tax_query' );
	$t[] = ( $c = mc_expert_city() ) ? array( 'taxonomy' => 'category', 'terms' => $c->term_id ) : array( 'relation' => 'OR', array( 'taxonomy' => 'cat-2', 'operator' => 'EXISTS' ), array( 'taxonomy' => 'category', 'terms' => 20 ) );
	$q->set( 'tax_query', $t );
} );

// רק מומחים (תפקיד מומחה) מקבלים עמוד מומחה וקישור בשם הכותב. כל שאר המשתמשים (MCITY, מנהלים, עורכים) – בלי עמוד ובלי קישור
function mc_is_expert( $id ) { $u = get_userdata( $id ); return $u && in_array( 'author', (array) $u->roles, true ); }
add_filter( 'author_link', function( $link, $id ) { return ! mc_is_expert( $id ) ? '' : $link; }, 20, 2 );
add_action( 'template_redirect', function() { if ( is_author() && ! mc_is_expert( get_queried_object_id() ) ) { wp_safe_redirect( home_url( '/' ), 301 ); exit; } } );
add_filter( 'wpseo_sitemap_exclude_author', function( $users ) { return array_filter( (array) $users, function( $u ) { return mc_is_expert( is_object( $u ) ? $u->ID : $u ); } ); } );

// המומחים שלנו בעיר: /all_expert/{עיר}/ = עמוד "המומחים שלנו" (1576) עם העיר – בלי עמוד נפרד לכל עיר (14 העמודים הישנים בפח)
add_action( 'init', function() { add_rewrite_rule( '^all_expert/([^/]+)/?$', 'index.php?page_id=1576&mc_city=$matches[1]', 'top' ); } );
function mc_experts_city_page() { return ( is_page( 1576 ) && get_query_var( 'mc_city' ) ) ? mc_expert_city() : null; }
add_filter( 'redirect_canonical', function( $r ) { return ( is_page( 1576 ) && get_query_var( 'mc_city' ) ) ? false : $r; } );
add_action( 'template_redirect', function() { if ( is_page( 1576 ) && get_query_var( 'mc_city' ) && ! mc_expert_city() ) { wp_safe_redirect( home_url( '/all_expert/' ), 301 ); exit; } }, 5 );
add_filter( 'elementor/theme/get_location_templates/template_id', function( $id, $location ) {
	if ( ! in_array( $location, array( 'header', 'footer' ), true ) || ! ( $c = mc_experts_city_page() ) ) return $id;
	$h = array( 5 => 152, 6 => 120, 7 => 116, 8 => 144, 9 => 148, 10 => 131, 11 => 160, 12 => 127, 13 => 136, 14 => 101, 15 => 165, 16 => 140, 17 => 272, 18 => 156 );
	return 'footer' === $location ? 175 : $h[ $c->term_id ];
}, 10, 2 );
add_filter( 'the_title', function( $t, $id = 0 ) { return ( 1576 === (int) $id && ! is_admin() && ( $c = mc_experts_city_page() ) ) ? $t . ': ' . $c->name : $t; }, 9, 2 ); // לפני wptexturize, כדי שהמקף יהיה ארוך כמו קודם
foreach ( array( 'wpseo_title', 'wpseo_opengraph_title' ) as $f ) add_filter( $f, function( $t ) { return ( $c = mc_experts_city_page() ) ? preg_replace( '/' . preg_quote( get_post_field( 'post_title', 1576 ), '/' ) . '/u', get_post_field( 'post_title', 1576 ) . ': ' . $c->name, $t, 1 ) : $t; } );
foreach ( array( 'wpseo_canonical', 'wpseo_opengraph_url' ) as $f ) add_filter( $f, function( $u ) { return ( $c = mc_experts_city_page() ) ? home_url( '/all_expert/' . $c->slug . '/' ) : $u; } );

// רשימת מדורים: תבנית אדומה אחת לכל 14 הערים (red_city_sections) – הרשימה מציגה את מדורי העיר של העמוד הנוכחי; תבנית כחולה אחת ל-/madorim/artzi/. /section/ (העמוד הישן) → /madorim/artzi/
add_filter( 'get_terms_args', function( $a, $tax ) {
	if ( is_admin() || ! in_array( 'section', (array) $tax, true ) || empty( $a['child_of'] ) || ! is_tax( 'section' ) ) return $a;
	$q = get_queried_object();
	if ( $q && ! $q->parent && (int) $a['child_of'] === 209 ) $a['child_of'] = $q->term_id;
	return $a;
}, 10, 2 );
add_action( 'template_redirect', function() { if ( '/section' === untrailingslashit( strtok( $_SERVER['REQUEST_URI'] ?? '', '?' ) ) ) { wp_safe_redirect( home_url( '/madorim/artzi/' ), 301 ); exit; } }, 1 );

// מומחה נשאר מומחה: בעריכת מומחה, בהוספת מומחה ובשינוי תפקיד ברשימת המומחים – רשימת התפקידים מציגה רק 'מומחה'. משתמשים אחרים לא מושפעים
add_filter( 'editable_roles', function( $roles ) {
	global $pagenow;
	if ( ! is_admin() || ! isset( $roles['author'] ) ) return $roles;
	$uid = (int) ( $_REQUEST['user_id'] ?? 0 );
	$expert = ( 'user-edit.php' === $pagenow && $uid && mc_is_expert( $uid ) ) || ( function_exists( 'mc_is_experts_screen' ) && mc_is_experts_screen() );
	return $expert ? array( 'author' => $roles['author'] ) : $roles;
} );

// Article approval: the "כותבת תוכן" role (mc_writer) can only submit for review. The site manager gets an email and a "ממתינים לאישור" counter in the menu
add_action( 'init', function() {
	if ( ! get_role( 'mc_writer' ) ) add_role( 'mc_writer', 'כותבת תוכן', array( 'read' => true, 'edit_posts' => true, 'delete_posts' => true, 'upload_files' => true ) );
} );
add_action( 'transition_post_status', function( $new, $old, $post ) {
	if ( 'pending' !== $new || 'pending' === $old || 'post' !== $post->post_type ) return;
	$cats = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );
	$msg  = "נשלחה כתבה חדשה לאישור.\n\nכותרת: " . $post->post_title . "\nנשלחה ע\"י: " . get_the_author_meta( 'display_name', $post->post_author ) . "\nקטגוריות: " . implode( ', ', is_array( $cats ) ? $cats : array() ) . "\n\nלפתיחת הכתבה ואישור:\n" . admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
	wp_mail( get_option( 'admin_email' ), 'כתבה חדשה ממתינה לאישור: ' . $post->post_title, $msg );
}, 10, 3 );
add_action( 'admin_menu', function() {
	global $menu;
	if ( ! current_user_can( 'edit_others_posts' ) ) return;
	$n = (int) wp_count_posts( 'post' )->pending;
	$b = $n ? ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . $n . '</span></span>' : '';
	foreach ( (array) $menu as $i => $m ) { if ( 'edit.php' === ( $m[2] ?? '' ) ) $menu[ $i ][0] .= $b; }
	add_submenu_page( 'edit.php', 'ממתינים לאישור', 'ממתינים לאישור' . $b, 'edit_others_posts', 'edit.php?post_status=pending&post_type=post' );
}, 99 );