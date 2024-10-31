<?php
namespace Russian_Post\Includes\Functions;

use Russian_Post\Includes\Russian_Post_Api;

/**
 * Метод срабатывает при успешном завершении оплаты
 *
 * @param $order_id
 *
 * @return mixed
 */
function payment_event_completed( $order_id ) {
	handle_paid_event( $order_id );

	return $order_id;
}

/**
 * Метод срабатывает при установлении в админке статуса "На удержании"
 *
 * @param $order_id
 * @param $status_transition_from
 * @param $status_transition_to
 * @param $that
 *
 * @return mixed
 */
function payment_event_completed_by_admin( $order_id, $status_transition_from, $status_transition_to, $that ) {
	if ( "processing" !== $status_transition_to ) {
		return $order_id;
	}
	handle_paid_event( $order_id );

	return $order_id;
}


function handle_paid_event( $order_id ) {

	$GLOBALS["post_rf_is_vendor_paid_order_mail"] = true;
}

add_filter( 'woocommerce_email_attachments', 'Russian_Post\Includes\Functions\add_form_to_on_hold_email', 10, 4 );
add_filter( 'woocommerce_email_attachments', 'Russian_Post\Includes\Functions\add_form_to_admin_3pl_post_rf_email', 10, 4 );

/**
 * @param $attachments array
 * @param $id     string
 * @param $object object|bool
 * @param $mail   \WC_Email
 *
 * @return array
 */
function add_form_to_on_hold_email( $attachments, $id, $object, $mail ) {
	if ( ! class_exists( "Dokan_Email_Settings\VendorNewOrderChanged" ) && ! class_exists( "WeDevs\Dokan\Emails\VendorNewOrder" ) ) {
		return $attachments;
	}
	if ( ! ( is_a( $mail, "Dokan_Email_Settings\VendorNewOrderChanged" ) || is_a( $mail, "WeDevs\Dokan\Emails\VendorNewOrder" ) ) ) {
		return $attachments;
	}
	error_log( 'Выброшено исключение: ' . $id );

	$items = $mail->object->get_items();
	if ( $items ) {
		if ( sizeof( $items ) === 0 ) {
			return;
		}
		$item     = array_shift( $items );
		$order_id = $item->get_order_id();

		if ( post_rf_is_post_rf_order( $item->get_order_id() ) ) {
			$vendor           = dokan_get_vendor_by_product( $item["product_id"] );
			$vendor_id        = $vendor->id;
			$post_rf_order_id = get_post_meta( $order_id, "_russian_post_order_id_" . $vendor_id, true );
			if ( $post_rf_order_id ) {
				$file = ABSPATH . 'uploads/forms/f7p_shipment_' . $post_rf_order_id . '.pdf';

				if ( file_exists( $file ) ) {
					$attachments[] = $file;
				} else {
					try {
						$api      = new Russian_Post_Api();
						$response = $api->get_form_f7p( $post_rf_order_id, $vendor_id );
						if ( $response ) {
							$attachments[] = $file;
						}
					} catch ( Exception $e ) {
						error_log( 'Выброшено исключение: ', $e->getMessage(), "\n" );
					}
				}

				$file103       = ABSPATH . 'uploads/forms/f103_shipment_' . $post_rf_order_id . '_' . $vendor_id . '.pdf';
				$post_rf_batch = get_post_meta( $order_id, "_russian_post_order_batch_name_" . $vendor_id, true );
				if ( file_exists( $file103 ) ) {
					$attachments[] = $file103;
				} else {
					try {
						$api      = new Russian_Post_Api();
						$response = $api->get_form_f103( $post_rf_order_id, $vendor_id, $post_rf_batch );
						if ( $response ) {
							$attachments[] = $file;
						}
					} catch ( Exception $e ) {
						error_log( 'Выброшено исключение: ', $e->getMessage(), "\n" );
					}
				}
			}
		}
	}

	return $attachments;
}


/**
 * @param $attachments array
 * @param $id     string
 * @param $object object|bool
 * @param $mail   \WC_Email
 *
 * @return array
 */
function add_form_to_admin_3pl_post_rf_email( $attachments, $id, $object, $mail ) {

	if ( ! class_exists( "\WC_Email_Admin_3pl_Post_Rf_Sent" ) ) {
		return $attachments;
	}
	if ( ! ( is_a( $mail, "\WC_Email_Admin_3pl_Post_Rf_Sent" ) ) ) {
		return $attachments;
	}
	error_log( 'Выброшено исключение: ' . $id );

	$items = $mail->object->get_items();
	if ( $items ) {
		if ( sizeof( $items ) === 0 ) {
			return;
		}
		$item     = array_shift( $items );
		$order_id = $item->get_order_id();

		if ( post_rf_is_post_rf_order( $item->get_order_id() ) ) {
			$vendor_id        = 0;
			$post_rf_order_id = get_post_meta( $order_id, "_russian_post_order_id_" . $vendor_id, true );
			if ( $post_rf_order_id ) {
				$file = ABSPATH . 'uploads/forms/f7p_shipment_' . $post_rf_order_id . '.pdf';

				if ( file_exists( $file ) ) {
					$attachments[] = $file;
				} else {
					try {
						$api      = new Russian_Post_Api();
						$response = $api->get_form_f7p( $post_rf_order_id, $vendor_id );
						if ( $response ) {
							$attachments[] = $file;
						}
					} catch ( Exception $e ) {
						error_log( 'Выброшено исключение: ', $e->getMessage(), "\n" );
					}
				}

				$file103       = ABSPATH . 'uploads/forms/f103_shipment_' . $post_rf_order_id . '_' . $vendor_id . '.pdf';
				$post_rf_batch = get_post_meta( $order_id, "_russian_post_order_batch_name_" . $vendor_id, true );
				if ( file_exists( $file103 ) ) {
					$attachments[] = $file103;
				} else {
					try {
						$api      = new Russian_Post_Api();
						$response = $api->get_form_f103( $post_rf_order_id, $vendor_id, $post_rf_batch );
						if ( $response ) {
							$attachments[] = $file;
						}
					} catch ( Exception $e ) {
						error_log( 'Выброшено исключение: ', $e->getMessage(), "\n" );
					}
				}
			}
		}
	}

	return $attachments;
}

add_filter( 'woocommerce_email_enabled_new_order', 'Russian_Post\Includes\Functions\post_rf_send_sub_order_admin_email', 1000, 2 );
add_filter( 'woocommerce_email_recipient_new_order', 'Russian_Post\Includes\Functions\post_rf_get_sub_order_email_recipient', 1000, 3 );

/**
 * Send sub-order email for admin
 *
 * @param $bool
 * @param $order
 *
 * @return bool
 */
function post_rf_send_sub_order_admin_email( $bool, $order ) {
	if ( ! $order ) {
		return $bool;
	}

	if ( $order->get_parent_id() ) {

		if ( key_exists( "post_rf_is_vendor_paid_order_mail", $GLOBALS ) && $GLOBALS["post_rf_is_vendor_paid_order_mail"] ) {
			return true;
		}

		return $bool;
	}

	return $bool;
}

function post_rf_get_sub_order_email_recipient( $recipient, $object, $mail ) {
	if ( ! class_exists( "Dokan_Email_Settings\VendorNewOrderChanged" ) && ! class_exists( "WeDevs\Dokan\Emails\VendorNewOrder" ) ) {
		return $recipient;
	}
	if ( ! ( is_a( $mail, "Dokan_Email_Settings\VendorNewOrderChanged" ) || is_a( $mail, "WeDevs\Dokan\Emails\VendorNewOrder" ) ) ) {
		return $recipient;
	}
	$items = $mail->object->get_items();
	if ( sizeof( $items ) === 0 ) {
		return $recipient;
	}
	$item     = array_shift( $items );
	$order_id = $item->get_order_id();
	if ( ! dokan_is_sub_order( $order_id ) ) {
		return $recipient;
	}
	$vendor = dokan_get_vendor_by_product( $item["product_id"] );

	return $vendor->get_email();
}

function post_rf_is_post_rf_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return false;
	}
	$items_shipping = $order->get_items( 'shipping' );
	if ( ! empty( $items_shipping ) ) {
		$shipping_method = $items_shipping[ array_key_first( $items_shipping ) ]->get_method_id();
		if ( $shipping_method == 'russian_post' ) {
			return true;
		}
	}

	return false;
}
