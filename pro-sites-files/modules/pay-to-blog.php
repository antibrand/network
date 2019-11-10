<?php

/*
Plugin Name: Pro Sites (Feature: Pay To Blog)
*/

class ProSites_Module_PayToBlog {

	static $user_label;
	static $user_description;

	// Module name for registering
	public static function get_name() {
		return __( 'Pay To Blog', 'psts' );
	}

	// Module description for registering
	public static function get_description() {
		return __( 'Allows you to completely disable a site both front end and back until paid.', 'psts' );
	}

	function __construct() {
		if ( ! is_admin() && is_main_site( get_current_blog_id() ) ) {
			return;
		}
		add_filter( 'psts_settings_filter', array( &$this, 'settings_process' ), 10, 2 );
		add_action( 'template_redirect', array( &$this, 'disable_front' ) );
		add_filter( 'psts_prevent_dismiss', '__return_true' );
		add_filter( 'psts_force_redirect', array( &$this, 'force_redirect' ) );
		add_filter( 'pre_option_psts_signed_up', array( &$this, 'force_redirect' ) );

		self::$user_label       = __( 'Pay to Blog', 'psts' );
		self::$user_description = __( 'Site disabled until payment is cleared', 'psts' );

		//checkout message, show before gateways
		add_filter( 'psts_checkout_output', array( &$this, 'checkout_screen' ), 9, 2 );
	}

	function checkout_screen( $content, $blog_id ) {
		global $psts;

		if ( ! $blog_id ) {
			return $content;
		}

		//show top part of content if its not a pro blog
		if ( ! is_pro_site( $blog_id ) ) {
			$content .= $psts->get_setting( 'ptb_checkout_msg' );
		}

		return $content;
	}

	function disable_front() {
		global $psts, $blog_id;

		if ( is_admin() ) {
			return;
		}

		//Whether to disable Site or not
		$disable_site = ! $psts->is_pro_site( $blog_id ) && $psts->get_expire( $blog_id );

		//Check if free site is enabled and
		if ( $psts->get_setting( 'ptb_front_disable' ) && $disable_site ) {

			//send temporary headers
			header( 'HTTP/1.1 503 Service Temporarily Unavailable' );
			header( 'Status: 503 Service Temporarily Unavailable' );
			header( 'Retry-After: 86400' );

			//load template if exists
			if ( file_exists( WP_CONTENT_DIR . '/ptb-template.php' ) ) {
				require_once( WP_CONTENT_DIR . '/ptb-template.php' );
				exit;
			} else {
				$content = $psts->get_setting( 'ptb_front_msg' );
				if ( is_user_logged_in() && current_user_can( 'edit_pages' ) ) {
					$content .= '<p><a href="' . $psts->checkout_url( $blog_id ) . '">' . __( 'Re-enable now &raquo;', 'psts' ) . '</a></p>';
				}
				wp_die( $content );
			}
		}
	}

	/**
	 * Should force redirect on expire?
	 *
	 * @param bool $value Should redirect?
	 *
	 * @return int
	 */
	function force_redirect( $value ) {

		// If it is a free site or an active Pro Site.
		if ( ProSites_Helper_ProSite::is_free_site() || is_pro_site( false, 1 ) ) {
			return 0;
		} else {
			return 1;
		}
	}

	function settings_process( $settings, $active_tab ) {

		if ( 'paytoblog' == $active_tab ) {
			$settings['ptb_front_disable'] = isset( $settings['ptb_front_disable'] ) ? $settings['ptb_front_disable'] : 0;
		}

		return $settings;
	}

	function settings() {
		global $psts; ?>

		<div class="inside">
			<table class="form-table">
				<tr valign="top">
					<th scope="row"
					    class="psts-help-div psts-checkout-message"><?php echo __( 'Checkout Message', 'psts' ) . $psts->help_text( __( 'Required - This message is displayed on the checkout page if the site is unpaid. HTML Allowed', 'psts' ) ); ?></th>
					<td>
						<textarea name="psts[ptb_checkout_msg]" rows="5" wrap="soft"
						          style="width: 95%"><?php echo esc_textarea( $psts->get_setting( 'ptb_checkout_msg' ) ); ?></textarea>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php _e( 'Disable Front End', 'psts' ); ?></th>
					<td>
						<label><input type="checkbox" name="psts[ptb_front_disable]"
						              value="1"<?php checked( $psts->get_setting( 'ptb_front_disable' ) ); ?> /> <?php _e( 'Disable', 'psts' ); ?>
						</label></td>
				</tr>
				<tr valign="top">
					<th scope="row"
					    class="psts-help-div psts-frontend-restricted"><?php echo __( 'Front End Restricted Message', 'psts' ) . $psts->help_text( __( 'Required - This message is displayed on front end of the site if it is unpaid and disabling the front end is enabled. HTML Allowed', 'psts' ) ); ?></th>
					<td>
						<textarea name="psts[ptb_front_msg]" rows="5" wrap="soft"
						          style="width: 95%"><?php echo esc_textarea( $psts->get_setting( 'ptb_front_msg' ) ); ?></textarea>
					</td>
				</tr>
			</table>
		</div>
		<!--		</div>-->
		<?php
	}

	public static function is_included( $level_id ) {
		switch ( $level_id ) {
			default:
				return false;
		}
	}

	/**
	 * Returns the staring pro level as pro widget is available for all sites
	 */
	public static function required_level() {
		global $psts;

		$levels = ( array ) get_site_option( 'psts_levels' );

		return ! empty( $levels ) ? key( $levels ) : false;

	}
}
