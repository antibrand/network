<?php

if ( ! class_exists( 'ProSites_View_Front_Gateway' ) ) {
	class ProSites_View_Front_Gateway {

		public static function render_checkout( $render_data = array(), $blog_id = false, $domain = false ) {
			global $psts, $wpdb;

			// Try going stateless, or check the session
			if ( empty( $render_data ) ) {
				$render_data                     = array();
				$render_data['new_blog_details'] = ProSites_Helper_Session::session( 'new_blog_details' );
			}
			if ( ! isset( $render_data['upgraded_blog_details'] ) ) {
				$render_data['upgraded_blog_details'] = ProSites_Helper_Session::session( 'upgraded_blog_details' );
			}

			$content = $primary_args = $secondary_args = '';

			$gateways = ProSites_Helper_Gateway::get_gateways();
			if ( empty( $gateways ) ) {

			}
			$gateway_details = self::filter_usable_gateways( self::get_gateway_details( $gateways ) );

			$site_details = false;

			//Handle Subscription Cancel, call respective gateway function for the blog id
			if ( isset( $_GET['action'] ) && $_GET['action'] == 'cancel' && wp_verify_nonce( $_GET['_wpnonce'], 'psts-cancel' ) ) {

				//Get blog id
				$blog_id = ! empty( $_GET['bid'] ) ? $_GET['bid'] : '';

				//If no nonce, return
				if ( empty( $_GET['_wpnonce'] ) ) {
					return;
				}

				//If nonce not verified return, Verify Nonce
//				if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'psts-cancel' ) ) {
//					return;
//				}

				//If there is blog id
				if ( ! empty( $blog_id ) ) {

					//Get gateway details
					$sql     = $wpdb->prepare( "SELECT `gateway` FROM {$wpdb->base_prefix}pro_sites WHERE blog_ID = %s", $blog_id );
					$result  = $wpdb->get_row( $sql );
					$gateway = ! empty( $result->gateway ) ? strtolower( $result->gateway ) : '';
					// Fix for PayPal gateway. No other gateways have name as "trial".
					$gateway = 'trial' === $gateway ? 'paypal' : $gateway;
					if ( ! empty( $gateway ) ) {
						//Check if a respective gateway class exists, and call cancel subscription function
						if ( ! empty( $gateways[ $gateway ] ) && method_exists( $gateways[ $gateway ]['class'], 'cancel_subscription' ) ) {
							call_user_func( $gateways[ $gateway ]['class'] . '::cancel_subscription', $blog_id, true );
						}

						do_action( 'psts_cancel_subscription_' . $gateway, $blog_id, true );
					}

					$site_details = $result;
				}
			}

			// Add existing account filter
			add_filter( 'prosites_render_checkout_page', 'ProSites_View_Front_Gateway::prepend_plan_details', 10, 3 );

			$primary_gateway = isset( $gateway_details['primary'] ) ? $gateway_details['primary'] : false;

			//Check if a secondary gateway is enabled
			$secondary_gateway = ! empty( $gateway_details['secondary'] ) && $gateway_details['secondary'] !== 'none' ? $gateway_details['secondary'] : '';

			//Check if manual gateway is enabled
			$manual_gateway = isset( $gateway_details['manual'] ) && ! empty( $gateways[ $gateway_details['manual'] ] ) ? $gateway_details['manual'] : '';

			// Force manual if no gateways are defined
			if ( empty( $primary_gateway ) ) {
				if ( empty( $manual_gateway ) ) {
					$primary_gateway = 'manual';
				} else {
					$primary_gateway = 'manual';
					$manual_gateway  = '';
				}
			}

			$site_details = ! empty( $blog_id ) ? ProSites_Helper_ProSite::get_blog_info( $blog_id ) : '';

			/**
			 * Process forms
			 */
			if ( ! empty( $primary_gateway ) && isset( $gateways[ $primary_gateway ] ) &&
			     method_exists( $gateways[ $primary_gateway ]['class'], 'process_on_render' ) && call_user_func( $gateways[ $primary_gateway ]['class'] . '::process_on_render' ) &&
			     method_exists( $gateways[ $primary_gateway ]['class'], 'process_checkout_form' )
			) {
				$primary_args = call_user_func( $gateways[ $primary_gateway ]['class'] . '::process_checkout_form', $render_data, $blog_id, $domain );
			}
			if ( ! empty( $secondary_gateway ) && isset( $gateways[ $secondary_gateway ] ) &&
			     method_exists( $gateways[ $secondary_gateway ]['class'], 'process_on_render' ) && call_user_func( $gateways[ $secondary_gateway ]['class'] . '::process_on_render' ) &&
			     method_exists( $gateways[ $secondary_gateway ]['class'], 'process_checkout_form' )
			) {
				$secondary_args = call_user_func( $gateways[ $secondary_gateway ]['class'] . '::process_checkout_form', $render_data, $blog_id, $domain );
			}
			if ( ! empty( $manual_gateway ) && method_exists( $gateways[ $manual_gateway ]['class'], 'process_checkout_form' ) ) {
				$manual_args = call_user_func( $gateways[ $manual_gateway ]['class'] . '::process_checkout_form', $render_data, $blog_id, $domain );
			}

			// If site modified, apply this filter... has to happen after form processing.
			$render_data['plan_updated'] = ProSites_Helper_Session::session( 'plan_updated' );
			if ( isset( $render_data['plan_updated'] ) ) {
				if ( ! empty( $render_data['plan_updated']['gateway'] ) && $render_data['plan_updated']['gateway'] == 'manual' ) {
					add_filter( 'prosites_render_checkout_page', 'ProSites_Gateway_Manual::render_account_modified', 10, 3 );
				} else {
					add_filter( 'prosites_render_checkout_page', 'ProSites_View_Front_Gateway::render_account_modified', 10, 3 );
				}
			}

			$tabbed       = 'tabbed' == $psts->get_setting( 'pricing_gateways_style', 'tabbed' ) ? true : false;
			$hidden_class = empty( $_POST ) ? 'hidden' : '';

			/**
			 * @todo Deal with upgraded_blog_details session
			 */
			$hidden_class = ( isset( $render_data['new_blog_details']['blogname'] ) ) || isset( $render_data['upgraded_blog_details'] ) ? '' : $hidden_class;
			$hidden_class = ( ! empty( $render_data['new_blog_details']['site_activated'] ) || ! empty( $render_data['upgraded_blog_details']['site_activated'] ) ) ? 'hidden' : $hidden_class;


			$content .= '<div' . ( $tabbed ? ' id="gateways"' : '' ) . ' class="gateways checkout-gateways ' . $hidden_class . '">';

			// How many gateways can we use at checkout?
			$available_gateways = empty( $primary_gateway ) ? 0 : 1;
			$available_gateways = empty( $secondary_gateway ) ? $available_gateways : $available_gateways + 1;
			$available_gateways = empty( $manual_gateway ) ? $available_gateways : $available_gateways + 1;

			// Render tabs
			if ( $tabbed && $available_gateways > 1 ) {
				$content .= '<ul>';
				if ( ! empty( $primary_gateway ) ) {
					//If there are errors associated with the gateway, Mark the gateway tab as active
					$error = $psts->errors->get_error_message( $primary_gateway );
					$class = ! empty( $error ) ? 'ui-tabs-active ui-state-active' : '';
					$content .= '<li class="' . $class . '"><a href="#gateways-1">' . esc_html( $psts->get_setting( 'checkout_gateway_primary_label', __( 'Payment', 'psts' ) ) ) . '</a></li>';
				}
				if ( ! empty( $secondary_gateway ) ) {
					//If there are errors associated with the gateway, Mark the gateway tab as active
					$error = $psts->errors->get_error_message( $secondary_gateway );
					$class = ! empty( $error ) ? 'ui-tabs-active ui-state-active' : '';
					$content .= '<li class="' . $class . '"><a href="#gateways-2">' . esc_html( $psts->get_setting( 'checkout_gateway_secondary_label', __( 'Alternate Payment', 'psts' ) ) ) . '</a></li>';
				}
				if ( ! empty( $manual_gateway ) ) {
					//If there are errors associated with the gateway, Mark the gateway tab as active
					$error = $psts->errors->get_error_message( $manual_gateway );
					$class = ! empty( $error ) ? 'ui-tabs-active ui-state-active' : '';
					$content .= '<li class="' . $class . '"><a href="#gateways-3">' . esc_html( $psts->get_setting( 'checkout_gateway_manual_label', __( 'Offline Payment', 'psts' ) ) ) . '</a></li>';
				}
				$content .= '</ul>';
			}

			$allowed_html = wp_kses_allowed_html( 'post' );
			$last_gateway = ! empty ( $site_details['last_payment_gateway'] ) ? $site_details['last_payment_gateway'] : '';

			$allow_cancel_gateway = ! defined( 'PSTS_CANCEL_ON_GATEWAY_CHANGE' ) || ( defined( 'PSTS_CANCEL_ON_GATEWAY_CHANGE' ) && PSTS_CANCEL_ON_GATEWAY_CHANGE != false );

			// Primary
			if ( ! empty( $primary_gateway ) && isset ( $gateways[ $primary_gateway ] ) && method_exists( $gateways[ $primary_gateway ]['class'], 'render_gateway' ) ) {

				$content .= '<div id="gateways-1" class="gateway gateway-primary">';
				$content .= call_user_func( $gateways[ $primary_gateway ]['class'] . '::render_gateway', $render_data, $primary_args, $blog_id, $domain );

				if ( $allow_cancel_gateway && ! empty ( $last_gateway ) && $last_gateway !== $primary_gateway && strtolower( $last_gateway ) !== 'trial' ) {

					$name = "";
					if ( isset( $gateways[ $site_details['last_payment_gateway'] ] ) && isset( $gateways[ $site_details['last_payment_gateway'] ]['class'] ) && method_exists( $gateways[ $site_details['last_payment_gateway'] ]['class'], 'get_name' ) ) {
						$name = call_user_func( $gateways[ $site_details['last_payment_gateway'] ]['class'] . '::get_name' );
						$name = $name[ $site_details['last_payment_gateway'] ];
					}

					if ( ! empty( $last_gateway ) && 'manual' != $last_gateway ) {
						$content .= '<div id="psts-general-error" class="psts-warning">' .
						            wp_kses( sprintf( __( 'You signed up with the <strong>%1$s</strong> payment gateway. We will attempt to cancel your <strong>%1$s</strong> payments and setup new payments if you choose to continue.', 'psts' ), esc_html( $name ) ), $allowed_html );
						$content .= '</div>';
					}

				}

				$content .= '</div>';

			}

			// Secondary
			if ( ! empty( $secondary_gateway ) && isset( $gateways[ $secondary_gateway ] ) && method_exists( $gateways[ $secondary_gateway ]['class'], 'render_gateway' ) ) {

				$content .= '<div id="gateways-2" class="gateway gateway-secondary">';
				$content .= call_user_func( $gateways[ $secondary_gateway ]['class'] . '::render_gateway', $render_data, $secondary_args, $blog_id, $domain, false );

				if ( $allow_cancel_gateway && ! empty ( $last_gateway ) && $last_gateway !== $secondary_gateway && strtolower( $last_gateway ) !== 'trial' ) {
//				if( ! empty ( $site_details['last_payment_gateway'] ) && $site_details['last_payment_gateway'] !== $secondary_gateway ) {
					$name = "";
					if ( isset( $gateways[ $site_details['last_payment_gateway'] ] ) && method_exists( $gateways[ $site_details['last_payment_gateway'] ]['class'], 'get_name' ) ) {
						$name = call_user_func( $gateways[ $site_details['last_payment_gateway'] ]['class'] . '::get_name' );
						$name = $name[ $site_details['last_payment_gateway'] ];
					}

					if ( ! empty( $last_gateway ) && 'manual' != $last_gateway ) {
						$content .= '<div id="psts-general-error" class="psts-warning">' .
						            sprintf( wp_kses( __( 'You signed up with the <strong>%1$s</strong> payment gateway. We will attempt to cancel your <strong>%1$s</strong> payments and setup new payments if you choose to continue.', 'psts' ), $allowed_html ), esc_html( $name ) );
						$content .= '</div>';
					}

				}

				$content .= '</div>';
			}

			// Manual
			if ( ! empty( $manual_gateway ) && method_exists( $gateways[ $primary_gateway ]['class'], 'render_gateway' ) ) {

				$content .= '<div id="gateways-3" class="gateway gateway-manual">';
				$content .= call_user_func( $gateways[ $manual_gateway ]['class'] . '::render_gateway', $render_data, $manual_args, $blog_id, $domain, false );

				$content .= '</div>';
			}
			$content .= '</div>';

			/**
			 * Filter to modify gateway page content.
			 *
			 * @param string $content
			 */
			return apply_filters( 'psts_render_checkout_gateway', $content );
		}

		public static function render_current_plan_information( $render_data = array(), $blog_id, $domain, $gateways, $gateway_order ) {
			global $psts, $wpdb, $current_site, $current_prosite_blog;

			if ( empty( $gateway_order ) ) {
				return '';
			}

			$content        = '';
			$info_retrieved = array();

			if ( empty( $blog_id ) && isset( $_GET['bid'] ) ) {
				$blog_id = (int) $_GET['bid'];
			}

			$blog_id = empty( $blog_id ) && ! empty( $current_prosite_blog ) ? $current_prosite_blog : $blog_id;

			if ( empty( $blog_id ) ) {
				return '';
			}

			// Is this a trial, if not, get the normal gateway data?
			$sql    = $wpdb->prepare( "SELECT `gateway` FROM {$wpdb->base_prefix}pro_sites WHERE blog_ID = %s", $blog_id );
			$result = $wpdb->get_row( $sql );

			if ( ! empty( $result ) ) {
				if ( 'Trial' == $result->gateway ) {
					$info_retrieved = ProSites_Gateway_Trial::get_existing_user_information( $blog_id, $domain );
				} else {
					foreach ( $gateway_order as $key ) {
						// @todo: replace the called method with hooks with different names in the gateways (filter is from ProSites_Helper_ProSite::get_blog_info() )
						if ( ! empty( $key ) && $result->gateway == $key && method_exists( $gateways[ $key ]['class'], 'get_existing_user_information' ) ) {
							$info_retrieved = call_user_func( $gateways[ $key ]['class'] . '::get_existing_user_information', $blog_id, $domain );
						}

						// Filter to update payment data.
						$info_retrieved = apply_filters( 'psts_current_plan_info_retrieved', $info_retrieved, $blog_id, $domain, $key );
					}
				}
			}

			$generic_info   = ProSites_Helper_ProSite::get_blog_info( $blog_id );
			$info_retrieved = array_merge( $generic_info, $info_retrieved );

			// Notifications
			$content .= self::get_notifications_only( $info_retrieved );

			// Output level information
			if ( ! empty( $info_retrieved ) && empty( $info_retrieved['complete_message'] ) ) {

				// Manual payments, Recurring subscription
				if ( $info_retrieved['recurring'] && ! empty( $info_retrieved['last_payment_gateway'] ) && 'manual' == strtolower( $info_retrieved['last_payment_gateway'] ) ) {
					$content .= '<div id="psts-general-error" class="psts-warning psts-manual-payment-notify">' . __( 'Your site was extended manually and may not renew automatically. If you have an active subscription this message will disappear once payment is received. Please contact us if you have any concerns. ', 'psts' ) . '</div>';
				}

				// Last payment failed
				if ( ! empty( $info_retrieved['payment_failed'] ) ) {
					if ( ! empty( $info_retrieved['last_payment_gateway'] ) && 'stripe' === strtolower( $info_retrieved['last_payment_gateway'] ) ) {
						$content .= '<div id="psts-general-error" class="psts-warning psts-payment-failed">' . __( 'Your last payment failed. Please update your card below or choose a new payment option.', 'psts' ) . '</div>';
					} else {
						$content .= '<div id="psts-general-error" class="psts-warning psts-payment-failed">' . __( 'Your last payment failed. Please select your plan below and choose a new payment option.', 'psts' ) . '</div>';
					}
				}

				$content .= '<ul class="psts-info-list">';
				//level
				if ( ! empty( $info_retrieved['level'] ) ) {
					$content .= '<li class="psts-level">' . esc_html__( 'Level:', 'psts' ) . ' <strong>' . $info_retrieved['level'] . '</strong></li>';
				}
				//payment method
				if ( ! empty( $info_retrieved['card_type'] ) ) {
					$content .= '<li class="psts-payment-method">' . sprintf( __( 'Payment method: <strong>%1$s card</strong> ending in <strong>%2$s</strong>. Expires <strong>%3$s/%4$s</strong>', 'psts' ), $info_retrieved['card_type'], $info_retrieved['card_reminder'], $info_retrieved['card_expire_month'], $info_retrieved['card_expire_year'] );
					// Update Credit Card Details (Stripe only)
					if ( ! empty( $info_retrieved['modify_card'] ) ) {
						$content .= $info_retrieved['modify_card'];
					}
					$content .= '</li>';
				}
				//last payment
				if ( ! empty( $info_retrieved['last_payment_date'] ) ) {
					$content .= '<li class="psts-last-payment">' . esc_html__( 'Last payment date:', 'psts' ) . ' <strong>' . date_i18n( get_option( 'date_format' ), $info_retrieved['last_payment_date'] ) . '</strong></li>';
				}
				//next payment
				if ( ! empty( $info_retrieved['next_payment_date'] ) ) {
					$content .= '<li class="psts-next-payment">' . esc_html__( 'Next payment date:', 'psts' ) . ' <strong>' . date_i18n( get_option( 'date_format' ), $info_retrieved['next_payment_date'] ) . '</strong></li>';
				}
				//period
				if ( ! empty( $info_retrieved['period'] ) && ! empty( $info_retrieved['recurring'] ) ) {
					$content .= '<li class="psts-period">' . esc_html__( 'Renewal Period:', 'psts' ) . sprintf( __( ' Every <strong>%d</strong> month(s)', 'psts' ), $info_retrieved['period'] ) . '</li>';
				}
				// Is recurring?
				if ( empty( $info_retrieved['recurring'] ) ) {
					$content .= '<li class="psts-expiry">' . esc_html__( 'Plan expires on:', 'psts' ) . ' <strong>' . $info_retrieved['expires'] . '</strong></li>';
				} else if ( ! empty( $info_retrieved['recurring'] ) && empty( $info_retrieved['next_payment_date'] ) ) {
					$content .= '<li class="psts-expiry">' . esc_html__( 'Renewal due on:', 'psts' ) . ' <strong>' . $info_retrieved['expires'] . '</strong></li>';
				}

				$content .= '</ul>';

				// Cancel link?
				if ( empty( $info_retrieved['cancellation_message'] ) ) {
					if ( ! empty( $info_retrieved['cancel_link'] ) ) {
						$content .= '<div id="psts-cancel-link" class="psts-cancel-link">' . $info_retrieved['cancel_link'] . $info_retrieved['cancel_info'] . '</div>';
					} else if ( ! empty( $info_retrieved['cancel_info_link'] ) ) {
						$content .= '<div id="psts-cancel-link" class="psts-cancel-link">' . $info_retrieved['cancel_info_link'] . $info_retrieved['cancel_info'] . '</div>';
					}
					// Receipt form
					if ( ! empty( $info_retrieved['receipt_form'] ) ) {
						$content .= '<div class="psts-receipt-link">' . $info_retrieved['receipt_form'] . '</div>';
					}
				}

				$content .= apply_filters( 'prosites_myaccount_details', '', $blog_id );

			}

			return '<div id="psts_existing_info"><h2>' . esc_html__( 'Your current plan', 'psts' ) . '</h2>' . $content . '</div>';

		}

		public static function filter_usable_gateways( $gateways ) {

			// remove 'bulk_upgrade'
			if ( 'bulk upgrade' == $gateways['primary'] ) {
				$gateways['primary'] = 'none';
			}
			if ( 'bulk upgrade' == $gateways['secondary'] ) {
				$gateways['secondary'] = 'none';
			}
			foreach ( $gateways['order'] as $k => $v ) {
				if ( 'bulk upgrade' == $v ) {
					unset( $gateways['order'][ $k ] );
				}
			}
			$gateways['order'] = array_values( $gateways['order'] );

			return $gateways;
		}

		public static function get_gateway_details( $gateways ) {
			global $psts;

			$gateway_details = array();
			$active_count    = count( $gateways );

			if ( 1 == $active_count ) {
				$keys                         = array_keys( $gateways );
				$gateway_details['primary']   = $keys[0];
				$gateway_details['secondary'] = '';
				$gateway_details['manual']    = '';
				reset( $gateways );
			} elseif ( $active_count > 1 ) {
				$keys                         = array_keys( $gateways );
				$gateway_details['primary']   = $psts->get_setting( 'gateway_pref_primary', $keys[0] );
				$gateway_details['secondary'] = $psts->get_setting( 'gateway_pref_secondary', $keys[1] );
				$use_manual                   = $psts->get_setting( 'gateway_pref_use_manual' );

				$gateway_details['primary']   = ! empty( $gateway_details['primary'] ) && 'none' != $gateway_details['primary'] ? $gateway_details['primary'] : '';
				$gateway_details['secondary'] = ! empty( $gateway_details['secondary'] ) && 'none' != $gateway_details['secondary'] ? $gateway_details['secondary'] : '';

				if ( 'manual' != $gateway_details['primary'] && 'manual' != $gateway_details['secondary'] && 'off' != $use_manual ) {
					$gateway_details['manual'] = 'manual';
				} else {
					$gateway_details['manual'] = '';
				}
			} elseif ( 0 >= $active_count ) {
				$gateway_details['primary']   = 'manual';
				$gateway_details['secondary'] = '';
				$gateway_details['manual']    = '';
			}
			$gateway_order            = array(
				$gateway_details['primary'],
				$gateway_details['secondary'],
				$gateway_details['manual']
			);
			$gateway_order            = array_filter( $gateway_order );
			$gateway_details['order'] = $gateway_order;

			return $gateway_details;

		}

		public static function get_notifications_only( $info_retrieved ) {
			$content = '';

			// Get pending message
			if ( ! empty( $info_retrieved['pending'] ) ) {
				$content .= $info_retrieved['pending'];
			}

			// Get trial message
			if ( ! empty( $info_retrieved['trial'] ) && empty( $info_retrieved['cancel'] ) ) {
				$content .= $info_retrieved['trial'];
			}

			// Get complete message
			if ( ! empty( $info_retrieved['complete_message'] ) ) {
				$content .= $info_retrieved['complete_message'];
				$content .= $info_retrieved['thanks_message'];
				$content .= $info_retrieved['visit_site_message'];
			}

			// Get cancellation message
			if ( ! empty( $info_retrieved['cancellation_message'] ) ) {
				$content .= $info_retrieved['cancellation_message'];
			}

			return $content;
		}

		public static function render_notification_information( $render_data = array(), $blog_id, $domain, $gateways, $gateway_order ) {
			$content        = '';
			$info_retrieved = array();

			foreach ( $gateway_order as $key ) {
				//If the key is empty or The gateway is not enabled
				if ( empty( $key ) || empty( $gateways[ $key ] ) ) {
					continue;
				}

				if ( empty( $info_retrieved ) && method_exists( $gateways[ $key ]['class'], 'get_existing_user_information' ) ) {
					$info_retrieved = call_user_func( $gateways[ $key ]['class'] . '::get_existing_user_information', $blog_id, $domain, false );
				}

				// Filter to update payment data.
				$info_retrieved = apply_filters( 'psts_render_notification_information', $info_retrieved, $blog_id );
			}

			// Notifications
			$content .= self::get_notifications_only( $info_retrieved );

			return $content;
		}


		public static function prepend_plan_details( $content, $blog_id, $domain ) {
			global $psts;

			$plan_content    = '';
			$gateways        = ProSites_Helper_Gateway::get_gateways();
			$gateway_details = self::get_gateway_details( $gateways );

			$is_pro_site = is_pro_site( $blog_id );

			$session_data                          = array();
			$session_data['new_blog_details']      = ProSites_Helper_Session::session( 'new_blog_details' );
			$session_data['upgraded_blog_details'] = ProSites_Helper_Session::session( 'upgraded_blog_details' );

			//if ( ! is_user_logged_in() || ( isset( $session_data['new_blog_details'] ) && isset( $session_data['new_blog_details']['site_activated'] ) && $session_data['new_blog_details']['site_activated'] ) ) {
			$pre_content = '';

			// PayPal Fix, As the user is redirected to Paypal and then sent back over to the site
			if ( isset( $_GET['token'] ) && isset( $_GET['PayerID'] ) && isset( $_GET['action'] ) && $_GET['action'] == 'complete' ) {
				return self::render_payment_submitted( '', '', $blog_id );
			}

			if ( ( isset( $session_data['new_blog_details']['payment_success'] ) && true === $session_data['new_blog_details']['payment_success'] ) ||
			     ( isset( $session_data['upgraded_blog_details']['payment_success'] ) && true === $session_data['upgraded_blog_details']['payment_success'] )
			) {
				$pre_content .= self::render_payment_submitted();
			}

			// Check manual payments
			if ( ( isset( $session_data['new_blog_details'] ) && isset( $session_data['new_blog_details']['manual_submitted'] ) && true === $session_data['new_blog_details']['manual_submitted'] ) ) {
				$pre_content .= self::render_manual_submitted( '', '', $blog_id );
			}

			if ( ! empty( $pre_content ) ) {
				return $pre_content;
			}

			//For gateways after redirection, upon page refresh
			$page_reload = ! empty( $_GET['action'] ) && $_GET['action'] == 'complete' && isset( $_GET['token'] );

			$cancel = ! empty( $_GET['action'] ) && $_GET['action'] == 'cancel';

			//If action is new_blog, but new blog is not allowed
			$new_blog_allowed = is_user_logged_in() && ! empty( $_GET['action'] ) && $_GET['action'] == 'new_blog' && ! ProSites_Helper_ProSite::allow_new_blog();

			if ( $is_pro_site && ( $cancel || ! isset( $_GET['action'] ) || $page_reload || $new_blog_allowed ) ) {
				// EXISTING DETAILS
				if ( isset( $gateways ) && isset( $gateway_details ) ) {
					$gateway_order = isset( $gateway_details['order'] ) ? $gateway_details['order'] : array();
					$plan_content  = self::render_current_plan_information( array(), $blog_id, $domain, $gateways, $gateway_order );
					$plan_content .= '<h2>' . esc_html__( 'Change your plan', 'psts' ) . '</h2>';
				}
			} else {
				// NOTIFICATIONS ONLY
				$plan_content = self::render_notification_information( array(), $blog_id, $domain, $gateways, $gateway_details['order'] );
			}

			return $plan_content . $content;

		}

		public static function render_manual_submitted( $render_data = array() ) {

			$email = '';

			// Try going stateless, or check the session
			if ( empty( $render_data ) ) {
				$render_data                     = array();
				$render_data['new_blog_details'] = ProSites_Helper_Session::session( 'new_blog_details' );
			}

			$content = '<div id="psts-payment-info-received">';

			if ( isset( $render_data ) && isset( $render_data['new_blog_details'] ) ) {
				$email = ! empty( $render_data['new_blog_details']['email'] ) ? $render_data['new_blog_details']['email'] : '';
			}

			//If we don't have email yet
			if ( empty( $email ) && is_user_logged_in() ) {
				$user  = get_userdata( get_current_user_id() );
				$email = $user->user_email;
			}

			$content .= '<h2>' . esc_html__( 'Finalizing your site...', 'psts' ) . '</h2>';

			$content .= '<p>' . esc_html__( 'Thank you for submitting your request for manual payment. We will review your information and email you once your site has been activated or extended.', 'psts' ) . '</p>';

			$content .= '<p>' . sprintf( esc_html__( 'The email address we have for you is: %s', 'psts' ), $email ) . '</p>';

			$content .= '</div>';

			ob_start();
			do_action( 'signup_pending' );
			$content .= ob_get_clean();

			ProSites_Helper_Session::unset_session( 'new_blog_details' );
			ProSites_Helper_Session::unset_session( 'upgraded_blog_details' );
			ProSites_Helper_Session::unset_session( 'activation_key' );

			return $content;
		}

		public static function render_free_confirmation( $render_data = array() ) {
			global $psts;

			// Try going stateless, or check the session
			if ( empty( $render_data ) ) {
				$render_data                     = array();
				$render_data['new_blog_details'] = ProSites_Helper_Session::session( 'new_blog_details' );
			}

			$content = '<div id="psts-payment-info-received">';

			$username = $render_data['new_blog_details']['username'];
			$email    = $render_data['new_blog_details']['email'];
			$blog_id  = $render_data['new_blog_details']['blog_id'];

			//Get blog ID
			if ( empty( $blog_id ) ) {
				if ( ! empty( $render_data['new_blog_details'] ) ) {
					$blog_details = $render_data['new_blog_details'];
				} else {
					$blog_details = $render_data['upgraded_blog_details'];
				}
				$domain = ! empty( $blog_details['domain'] ) ? $blog_details['domain'] : '';
				$path   = ! empty( $render_data['path'] ) ? $blog_details['path'] : '';

				//Get blog Id if we have domain name
				$blog_id = get_blog_id_from_url( $domain, $path );
			}

			//Get admin URL for the blog
			if ( ! empty( $blog_id ) ) {
				$scheme         = ProSites_Helper_ProSite::ssl_scheme();
				$blog_admin_url = get_admin_url( $blog_id, '', $scheme );
			}

			$content .= '<h2>' . esc_html__( 'Finalizing your site...', 'psts' ) . '</h2>';

			$content .= '<p>' . esc_html__( 'Your basic site has been setup and you should soon receive an email with your site details and password. You can always upgrade your site by logging in and viewing your account.', 'psts' ) . '</p>';

			$content .= '<p><strong>' . esc_html__( 'Your login details are:', 'psts' ) . '</strong></p>';
			$content .= '<p>' . sprintf( esc_html__( 'Username: %s', 'psts' ), $username );
			$content .= '<br />' . esc_html__( 'Admin URL: ', 'psts' ) . '<a href="' . esc_url( $blog_admin_url ) . '">' . esc_html( $blog_admin_url ) . '</a></p>';

			$content .= '<p>' . esc_html__( 'If you did not receive an email please try the following:', 'psts' ) . '</p>';
			$content .= '<ul>' .
			            '<li>' . esc_html__( 'Wait a little bit longer.', 'psts' ) . '</li>' .
			            '<li>' . esc_html__( 'Check your spam folder just in case it ended up in there.', 'psts' ) . '</li>' .
			            '<li>' . sprintf( esc_html__( 'Make sure that your email address is correct (%s)', 'psts' ), $email ) . '</li>' .
			            '</ul>';
			$content .= '<p>' . esc_html__( 'If your email address is incorrect or you noticed a problem, please contact us to resolve the issue.', 'psts' ) . '</p>';

			if ( ! empty( $blog_admin_url ) && ! is_user_logged_in() ) {
				$content .= '<a class="button" href="' . esc_url( $blog_admin_url ) . '">' . esc_html__( 'Login Now', 'psts' ) . '</a>';
			}

			$content .= '</div>';

			ob_start();
			do_action( 'signup_finished' );
			$content .= ob_get_clean();

			ProSites_Helper_Session::unset_session( 'new_blog_details' );
			ProSites_Helper_Session::unset_session( 'upgraded_blog_details' );
			ProSites_Helper_Session::unset_session( 'activation_key' );

			return $content;

		}

		public static function render_payment_submitted( $render_data = array(), $show_trial = false, $blog_id = '' ) {
			global $psts;

			// Try going stateless, or check the session
			if ( empty( $render_data ) ) {
				$render_data                     = array();
				$render_data['new_blog_details'] = ProSites_Helper_Session::session( 'new_blog_details' );
			}
			if ( ! isset( $render_data['upgraded_blog_details'] ) ) {
				$render_data['upgraded_blog_details'] = ProSites_Helper_Session::session( 'upgraded_blog_details' );
			}

			$content = '<div id="psts-payment-info-received">';

			$email = $blog_admin_url = '';

			if ( ! is_user_logged_in() ) {
				if ( isset( $render_data['new_blog_detail'] ) && isset( $render_data['new_blog_details']['email'] ) ) {
					$email = $render_data['new_blog_details']['email'];
				}
			} else {
				$user  = wp_get_current_user();
				$email = $user->user_email;
			}

			/**
			 * @todo: update $_SESSION for 'upgraded_blog_details'
			 */
			// Get the blog id... try the session or get it from the database
			$upgrade_blog_id = isset( $render_data['upgraded_blog_details']['blog_id'] ) ? $render_data['upgraded_blog_details']['blog_id'] : 0;
			$new_blog_id     = isset( $render_data['new_blog_details']['blog_id'] ) ? $render_data['new_blog_details']['blog_id'] : 0;
			$new_blog_name   = isset( $render_data['new_blog_details']['blogname'] ) ? $render_data['new_blog_details']['blogname'] : '';

			if ( empty( $blog_id ) ) {

				$blog_id = ! empty( $upgrade_blog_id ) ? $upgrade_blog_id : ( ! empty( $new_blog_id ) ? $new_blog_id : ( ! empty( $new_blog_name ) ? get_id_from_blogname( $new_blog_name ) : 0 ) );
			}

			//Get blog admin URL
			if ( empty( $blog_id ) ) {
				if ( ! empty( $render_data['new_blog_details'] ) ) {
					$blog_details = $render_data['new_blog_details'];
				} else {
					$blog_details = $render_data['upgraded_blog_details'];
				}

				$domain = ! empty( $blog_details['domain'] ) ? $blog_details['domain'] : '';
				$path   = ! empty( $render_data['path'] ) ? $blog_details['path'] : '';

				//Get blog Id if we have domain name
				$blog_id = get_blog_id_from_url( $domain, $path );
			}

			//If we have the blog id, get the admin url
			if ( ! empty( $blog_id ) ) {
				$scheme         = ProSites_Helper_ProSite::ssl_scheme();
				$blog_admin_url = get_admin_url( $blog_id, '', $scheme );
			}

			$blog_admin_url = empty( $blog_admin_url ) ? admin_url() : $blog_admin_url;

			$content .= '<h2>' . esc_html__( 'Finalizing your site...', 'psts' ) . '</h2>';

			$last_gateway = ProSites_Helper_ProSite::last_gateway( $blog_id );

			// Fix for Paypal and Stripe when trial is enabled and gateway name is trial.
			if ( empty( $last_gateway ) || 'trial' == strtolower( $last_gateway ) ) {
				if ( isset( $_GET['token'] ) && isset( $_GET['PayerID'] ) && isset( $_GET['action'] ) && $_GET['action'] == 'complete' ) {
					$last_gateway = 'paypal';
				} elseif ( ! empty( $_POST['stripeToken'] ) ) {
					$last_gateway = 'stripe';
				}
			}

			$ty_message = '';
			if ( ! empty( $last_gateway ) ) {
				if ( 'stripe' == $last_gateway ) {
					$key = 'stripe_thankyou';
				} elseif ( 'paypal' == $last_gateway ) {
					$key = 'pypl_thankyou';
				}
				$ty_message = ! empty( $key ) ? $psts->get_setting( $key ) : '';
			}

			//If we have a thank you message to display
			if ( ! empty( $ty_message ) ) {
				$content .= '<p>' . $ty_message . '</p>';
			}

			if ( ! $show_trial ) {
				$content .= '<p>' . esc_html__( 'Your payment is being processed and you should soon receive an email with your site details.', 'psts' ) . '</p>';
			} else {
				$content .= '<p>' . esc_html__( 'Your site trial has been setup and you should soon receive an email with your site details. Once your trial finishes you will be prompted to upgrade manually.', 'psts' ) . '</p>';
			}

			$username = '';
			$userpass = isset( $render_data['new_blog_details']['user_pass'] ) ? $render_data['new_blog_details']['user_pass'] : '';
			if ( isset( $render_data['new_blog_details']['username'] ) ) {
				$username = $render_data['new_blog_details']['username'];
			} else {
				$user     = wp_get_current_user();
				$username = $user->user_login;
			}

			$content .= '<p><strong>' . esc_html__( 'Your login details are:', 'psts' ) . '</strong></p>';
			$content .= '<p>' . sprintf( esc_html__( 'Username: %s', 'psts' ), $username );
			// Any passwords for existing users here will be wrong, so just don't display it.
			if ( ! empty( $userpass ) ) {
				$content .= '<br />' . sprintf( esc_html__( 'Password: %s', 'psts' ), $userpass );
			}
			$content .= '<br />' . esc_html__( 'Admin URL: ', 'psts' ) . '<a href="' . esc_url( $blog_admin_url ) . '">' . esc_html( $blog_admin_url ) . '</a></p>';

			$content .= '<p>' . esc_html__( 'If you did not receive an email please try the following:', 'psts' ) . '</p>';
			$content .= '<ul>' .
			            '<li>' . esc_html__( 'Wait a little bit longer.', 'psts' ) . '</li>' .
			            '<li>' . esc_html__( 'Check your spam folder just in case it ended up in there.', 'psts' ) . '</li>' .
			            '<li>' . sprintf( esc_html__( 'Make sure that your email address is correct (%s)', 'psts' ), $email ) . '</li>' .
			            '</ul>';
			$content .= '<p>' . esc_html__( 'If your email address is incorrect or you noticed a problem, please contact us to resolve the issue.', 'psts' ) . '</p>';


			if ( ! empty( $blog_admin_url ) && ! is_user_logged_in() ) {
				$content .= '<a class="button" href="' . esc_url( $blog_admin_url ) . '">' . esc_html__( 'Login Now', 'psts' ) . '</a>';
			}

			$content .= '</div>';

			ProSites_Helper_Session::unset_session( 'new_blog_details' );
			ProSites_Helper_Session::unset_session( 'upgraded_blog_details' );
			ProSites_Helper_Session::unset_session( 'activation_key' );

			return $content;
		}

		public static function render_account_modified( $content, $blog_id, $domain ) {
			global $psts;

			$render_data['plan_updated'] = ProSites_Helper_Session::session( 'plan_updated' );

			// Exit as if this never happened
			if ( ! isset( $render_data['plan_updated'] ) || false == $render_data['plan_updated']['render'] ) {
				return $content;
			}

			$level_list = get_site_option( 'psts_levels' );

			$periods = array(
				1  => __( 'monthly', 'psts' ),
				3  => __( 'quarterly', 'psts' ),
				12 => __( 'anually', 'psts' ),
			);

			$previous = '<strong>' . $level_list[ $render_data['plan_updated']['prev_level'] ]['name'] . '</strong> (' . $periods[ $render_data['plan_updated']['prev_period'] ] . ')';
			$current  = '<strong>' . $level_list[ $render_data['plan_updated']['level'] ]['name'] . '</strong> (' . $periods[ $render_data['plan_updated']['period'] ] . ')';

			$blog_id = (int) $render_data['plan_updated']['blog_id'];

			$content = '<div id="psts-payment-info-received">';

			$user  = wp_get_current_user();
			$email = $user->user_email;

			$content .= '<h2>' . esc_html__( 'Plan updated...', 'psts' ) . '</h2>';
			$content .= '<p>' . sprintf( esc_html__( 'Your plan was successfully modified from %s to %s. You will receive a receipt email shortly to confirm this action.', 'psts' ), $previous, $current ) . '</p>';
			$content .= '<p>' . esc_html__( 'If you did not receive an email please try the following:', 'psts' ) . '</p>';
			$content .= '<ul>' .
			            '<li>' . esc_html__( 'Wait a little bit longer.', 'psts' ) . '</li>' .
			            '<li>' . esc_html__( 'Check your spam folder just in case it ended up in there.', 'psts' ) . '</li>' .
			            '<li>' . sprintf( esc_html__( 'Make sure that your email address is correct (%s)', 'psts' ), $email ) . '</li>' .
			            '</ul>';
			$content .= '<p>' . esc_html__( 'If your email address is incorrect or you noticed a problem, please contact us to resolve the issue.', 'psts' ) . '</p>';
			$content .= '<a href="' . $psts->checkout_url( $blog_id ) . '">' . esc_html__( 'Go back to your account.', 'psts' ) . '</a>';
			$content .= '</div>';

			ProSites_Helper_Session::unset_session( 'plan_updated' );

			return $content;
		}

		public static function select_current_period( $period, $blog_id ) {
			global $wpdb;

			if ( is_user_logged_in() && ! empty( $blog_id ) ) {
				$result = $wpdb->get_var( $wpdb->prepare( "SELECT term FROM {$wpdb->base_prefix}pro_sites WHERE blog_ID = %d", $blog_id ) );
				if ( ! empty( $result ) ) {
					$period = 'price_' . $result;
				}
			}

			return $period;
		}

		public static function select_current_level( $level, $blog_id ) {
			global $wpdb;
			if ( is_user_logged_in() && ! empty( $blog_id ) ) {
				$result = $wpdb->get_var( $wpdb->prepare( "SELECT level FROM {$wpdb->base_prefix}pro_sites WHERE blog_ID = %d", $blog_id ) );
				if ( ! empty( $result ) ) {
					$level = $result;
				}
			}

			return $level;
		}


	}

}