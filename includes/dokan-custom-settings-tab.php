<?php

namespace Russian_Post\Includes;

/**
 * Adds an 'Доставка почтой России' tab to the Dokan settings navigation menu.
 *
 * @param array $menu_items
 *
 * @return array
 */
function add_post_rf_tab( $menu_items ) {
	$menu_items['post_rf'] = [
		'title'      => __( 'Доставка почтой России', 'russian_post' ),
		'icon'       => '<i class="fa fa-truck"></i>',
		'url'        => dokan_get_navigation_url( 'settings/post_rf' ),
		'pos'        => 90,
		'permission' => 'dokan_view_store_settings_menu',
	];


	return $menu_items;
}

add_filter( 'dokan_get_dashboard_settings_nav', 'Russian_Post\Includes\add_post_rf_tab' );

/**
 * Sets the title for the 'Доставка почтой России' settings tab.
 *
 * @param string $title
 * @param string $tab
 *
 * @return string Title for tab with slug $tab
 */
function set_post_rf_tab_title( $title, $tab ) {
	if ( 'post_rf' === $tab ) {
		$title = __( 'Доставка почтой России', 'russian_post' );
	}

	return $title;
}

add_filter( 'dokan_dashboard_settings_heading_title', 'Russian_Post\Includes\set_post_rf_tab_title', 10, 2 );

/**
 * Sets the help text for the 'Доставка почтой России' settings tab.
 *
 * @param string $help_text
 * @param string $tab
 *
 * @return string Help text for tab with slug $tab
 */
function set_post_rf_tab_help_text( $help_text, $tab ) {
	if ( 'post_rf' === $tab ) {
		$help_text = __( 'Настройте доставку почтой России.', 'russian_post' );
	}

	return $help_text;
}

add_filter( 'dokan_dashboard_settings_helper_text', 'Russian_Post\Includes\set_post_rf_tab_help_text', 10, 2 );

/**
 * Outputs the content for the 'Доставка почтой России' settings tab.
 *
 * @param array $query_vars WP query vars
 */
function output_help_tab_content( $query_vars ) {
	if ( isset( $query_vars['settings'] ) && 'post_rf' === $query_vars['settings'] ) {
		if ( ! current_user_can( 'dokan_view_store_settings_menu' ) ) {
			dokan_get_template_part( 'global/dokan-error', '', [
				'deleted' => false,
				'message' => __( 'You have no permission to view this page', 'dokan-lite' )
			] );
		} else {
			$user_id     = get_current_user_id();
			$point_saved = get_user_meta( $user_id, 'post_rf_point', true );
			?>
            <form method="post" id="settings-form" action="" class="dokan-form-horizontal">
				<?php wp_nonce_field( 'dokan_post_rf_settings_nonce' ); ?>

                <div class="dokan-form-group">
                    <label class="dokan-w3 dokan-control-label" for="point">
						<?php esc_html_e( 'Пункт почты РФ', 'russian_post' ); ?>
                    </label>
                    <div class="dokan-w5">
						<?php
						require_once "class-shipping-russian-post.php";
						require_once "class-russian-post-api.php";
						$post_api = new \Russian_Post\Includes\Russian_Post_Api();
						$points   = $post_api->get_allowed_points();
						?>
                        <select name="point" id="rf-point-selector">
							<?php foreach ( $points as $point ):
								$address = $point['operator-postcode'] . ' - ' . $point['ops-address']; ?>
                                <option value="<?php echo esc_attr($address); ?>" <?php selected( $address, $point_saved ) ?>><?= $address ?></option>
							<?php endforeach; ?>
                        </select>
                        <p class="help-block"> <?php esc_html_e( 'Выберите удобный для себя пункт отправки', 'russian_post' ); ?></p>
                    </div>

                </div>

                <div class="dokan-form-group">
                    <div class="dokan-w4 ajax_prev dokan-text-left" style="margin-left: 25%">
                        <input type="submit" name="dokan_update_post_rf_settings"
                               class="dokan-btn dokan-btn-danger dokan-btn-theme"
                               value="<?php esc_attr_e( 'Update Settings', 'russian_post' ); ?>">
                    </div>
                </div>
            </form>

            <style>
                #settings-form p.help-block {
                    margin-bottom: 0;
                }
            </style>
			<?php
		}
	}
}

add_action( 'dokan_render_settings_content', 'Russian_Post\Includes\output_help_tab_content' );

/**
 * Saves the settings on the 'Доставка почтой России' tab.
 *
 * Hooked with priority 5 to run before WeDevs\Dokan\Dashboard\Templates::ajax_settings()
 */
function save_post_rf_settings() {
	$user_id   = dokan_get_current_user_id();
	$post_data = wp_unslash( $_POST );
	$nonce     = isset( $post_data['_wpnonce'] ) ? $post_data['_wpnonce'] : '';

	// Bail if another settings tab is being saved
	if ( ! wp_verify_nonce( $nonce, 'dokan_post_rf_settings_nonce' ) ) {
		return;
	}

	$point = sanitize_text_field( $post_data['point'] );

	update_user_meta( $user_id, 'post_rf_point', $point );

	wp_send_json_success( [
		'msg' => __( 'Your information has been saved successfully', 'russian_post' ),
	] );
}

add_action( 'wp_ajax_dokan_settings', 'Russian_Post\Includes\save_post_rf_settings', 5 );
