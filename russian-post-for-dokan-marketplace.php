<?php

namespace Russian_Post;
/**
 * Plugin Name:  Фулфилмент от Почты России для маркетплейса Dokan
 * Description:  Фулфилмент от Почты России для маркетплейса на базе Dokan.
 * Version:      1.0.4
 * Author:       OnePix
 * Author URI:   https://onepix.net
 * License:      GPL2
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  russian-post-for-dokan-marketplace
 * Domain Path:  /languages
 * Network: false
*/

use mysql_xdevapi\Exception;
use Russian_Post\Includes\WC_Shipping_Russian_Post;
use Russian_Post\Emails\VendorCancelOrderRequest;
use SoapClient;

if ( ! function_exists( "woocommerce_multiple_packaging_init" ) ) {
	require_once __DIR__ . "/vendor/multiple-packages-for-woocommerce/multiple-packages-shipping.php";
}

require_once 'includes/dokan-custom-settings-tab.php';
require_once 'includes/class-russian-post-api.php';
require_once 'includes/functions.php';

class WC_Russian_Post {

	protected static $plugin_url;
	protected static $plugin_path;
	public $version;
	public $domain;
	public static $icon;
	public static $small_icon;

	public function __construct() {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		if ( ! is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) && ! is_plugin_active( 'woocommerce/woocommerce.php' ) && ! is_plugin_active_for_network( 'dokan-lite/dokan.php' ) && ! is_plugin_active( 'dokan-lite/dokan.php' ) ) {
			return;
		}

		self::$plugin_url  = plugin_dir_url( __FILE__ );
		self::$plugin_path = plugin_dir_path( __FILE__ );

		$this->version = time();
		$this->domain  = 'russian_post';
		add_action( 'admin_init', [ $this, 'init' ] );
		add_action( 'admin_init', [ $this, 'init_order_add' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'plugin_assets' ] );
		add_action( 'woocommerce_after_shipping_rate', [ $this, 'checkout_shipping_additional_field' ], 20, 2 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', [ $this, 'save_russian_post_meta' ], 10, 4 );
		add_action( 'woocommerce_shipping_packages', [ $this, 'condition_russian_post' ], 20, 1 );
		add_action( 'woocommerce_checkout_process', [ $this, 'russian_post_checkout_field_process' ] );
		add_action( 'wp_ajax_get_russian_post_price', [ $this, 'get_post_price' ] ); // wp_ajax_{ACTION HERE}
		add_action( 'wp_ajax_nopriv_get_russian_post_price', [ $this, 'get_post_price' ] );
		add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'checkout_map' ], 20 );

		add_action( 'woocommerce_shipping_init', [ $this, 'request_a_shipping_post_init' ] );

		add_filter( 'woocommerce_shipping_methods', [ $this, 'russian_post_shipping_method' ] );
		add_action( 'woocommerce_payment_complete', [ $this, 'russian_post_create_order' ], 100, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'russian_post_create_order' ], 100, 1 );
		add_action( 'woocommerce_after_order_itemmeta', [ $this, 'add_btn_create_shipping' ], 20, 2 );
		add_action( 'woocommerce_after_order_itemmeta', [ $this, 'add_admin_vendor_pdf' ], 21, 2 );
		add_action( 'woocommerce_admin_order_item_values', [ $this, 'add_vendor_pdf' ], 10, 3 );

		add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'add_shipping_group' ], 10 );
		add_action( 'woocommerce_review_order_before_shipping', [ $this, 'add_shipping_group_spoiler' ], 10 );
		add_action( 'woocommerce_cart_totals_before_shipping', [ $this, 'add_shipping_group' ], 10 );
		add_action( 'woocommerce_cart_totals_before_shipping', [ $this, 'add_shipping_group_spoiler' ], 10 );
		add_action( 'woocommerce_review_order_before_cart_contents', [ $this, 'add_product_spoiler' ], 10 );

		add_action( 'woocommerce_pre_payment_complete', 'Russian_Post\Includes\Functions\payment_event_completed', 1, 1 );
		add_action( 'woocommerce_order_status_changed', 'Russian_Post\Includes\Functions\payment_event_completed_by_admin', 0, 4 );
		add_filter( 'woocommerce_update_order_review_fragments', [ $this, 'update_shipping_price' ], 10, 1 );

		$settings = get_option( 'woocommerce_russian_post_settings' );
		if ( $settings['disable_cancel'] == 'yes' ) {
			add_filter( 'woocommerce_admin_order_actions', [ $this, 'remove_actions' ], 10, 2 );
			add_action( 'dokan_order_details_after_customer_info', [ $this, 'cancel_order_request' ], 10, 1 );
			add_filter( 'woocommerce_email_classes', [ $this, 'load_post_emails' ], 35 );
			add_action( 'wp_ajax_send_cancel_request', [ $this, 'send_cancel_request' ] ); // wp_ajax_{ACTION HERE}
			add_action( 'wp_ajax_nopriv_send_cancel_request', [ $this, 'send_cancel_request' ] );
		}

		add_action( 'init', [ $this, 'cron_init' ] );
		if ( isset( $_GET['run_check'] ) ) {
			$this->post_rf_check_send_orders();
		}
		add_action( 'post_rf_daily_event', [ $this, 'post_rf_check_send_orders' ] );
	}

	public function send_cancel_request() {
		if ( ! empty( $_POST['id'] ) ) {
			require_once 'emails/VendorCancelOrderRequest.php';
			new VendorCancelOrderRequest();
			do_action( 'rest_api_init' );
			do_action( 'cancel_order_send_email', sanitize_text_field( $_POST['id'] ) );

			return wp_send_json_success();
		}
	}

	public function load_post_emails( $wc_emails ) {
		require_once 'emails/VendorCancelOrderRequest.php';
		$wc_emails['Dokan_Email_Order_Cancel_Request'] = new VendorCancelOrderRequest();

		return $wc_emails;
	}


	public function cancel_order_request( $order ) {
		$id         = dokan_get_prop( $order, 'id' );
		$is_request = get_post_meta( $id, 'cancel_request', true );
		if ( $order->get_status() != 'cancelled' ) {
			if ( ! $is_request ) {
				echo '<br><a id="cancel-order-request" data-id="' . esc_html( dokan_get_prop( $order, 'id' ) ) . '" href="#">' . __( 'Запросить отмену заказа', 'russian_post' ) . '</a>';
			} else {
				_e( 'Запрос на отмену заказ отправлен', 'russian_post' );
			}
		}
	}

	public function remove_actions( $actions, $order ) {
		unset( $actions['complete'], $actions['processing'] );

		return $actions;
	}

	public function post_rf_check_send_orders() {
		global $wpdb;

		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}posts WHERE post_status LIKE 'wc-processing' AND `post_type` LIKE 'shop_order'" ) );
		if ( ! empty( $orders ) ) {
			foreach ( $orders as $order ) {
				require_once 'includes/class-tracking-russian-post.php';
				try {
					$tracking = new Includes\WC_Tracking_Russian_Post( $order->ID );
					if ( $tracking->is_sended() ) {
						$order = wc_get_order( $order->ID );
						update_post_meta( $order->ID, 'russian_post_sent', true );
						$order->update_status( 'completed', __( 'Посылка получена Почтой России', 'russian_post' ) );
					}
				} catch ( Exception $e ) {
					var_dump( $e );
				}
			}
		}
		exit;
	}

	public function cron_init() {
		if ( ! wp_next_scheduled( 'post_rf_daily_event' ) ) {
			wp_schedule_event( time(), 'daily', 'post_rf_daily_event' );
		}
	}

	public function update_shipping_price( $fragments ) {
		$shipping_total = WC()->cart->get_shipping_total() + WC()->cart->get_shipping_tax();

		parse_str( $_REQUEST['post_data'], $post_data );

		if ( is_checkout() && intval( $shipping_total ) == 0 && in_array( 'russian_post11', $post_data['shipping_method'] ) ) {
			$fragments['#shipping_summ'] = '<td id="shipping_summ" data-title="' . __( 'Сумма доставки', 'russian_post' ) . '">' . __( 'Выберите отделение на карте', 'russian_post' ) . '</td>';
		} else {
			$fragments['#shipping_summ'] = '<td id="shipping_summ" data-title="' . __( 'Сумма доставки', 'russian_post' ) . '">' . esc_html( $shipping_total ) . '₽</td>';
		}

		return $fragments;
	}

	public function add_shipping_group() {
		if ( wp_doing_ajax() && ( ! isset( $_SERVER["HTTP_REFERER"] ) || false === stripos( $_SERVER["HTTP_REFERER"], "cart" ) ) ) {
			return;
		}

		$type = 'point';

        $otherMethods = [];
        $packages       = WC()->shipping()->get_packages();
        foreach ($packages as $package){
            foreach ($package['rates'] as $rate){
                if(stripos($rate->method_id, '3pl') === false && stripos($rate->method_id, 'russian_post') === false){
                    $otherMethods[$rate->get_id()] = [
                        'label' => $rate->get_label(),
                        'checked' => $type == $rate->get_id() ?  'checked' : '',
                    ];
                }
            }
        }

		if ( ! empty( $_REQUEST['post_data'] ) ) {
			parse_str( $_REQUEST['post_data'], $post_data );

			if ( ! empty( $post_data['shipping_type'] ) ) {
				$type           = $post_data['shipping_type'];
				$chosen_methods = [];


				foreach ( $packages as $i => $package ) {
					if ( $type == 'point' && $package['extra_shipping'] == '3pl_shipping' ) {
						$chosen_methods[ $i ] = '3pl_shipping_2';
					}
					if ( $type == 'door' && $package['extra_shipping'] == '3pl_shipping' ) {
						$chosen_methods[ $i ] = '3pl_shipping_1';
					}
					if ( $type == 'point' && $package['extra_shipping'] != '3pl_shipping' ) {
						$chosen_methods[ $i ] = 'russian_post11';
					}
					if ( $type == 'door' && $package['extra_shipping'] != '3pl_shipping' ) {
						$chosen_methods[ $i ] = 'russian_post_courier13';
					}
				}
				WC()->session->set( 'chosen_shipping_methods', $chosen_methods );
			}
		}
		$door_checker  = $type == 'door' ? 'checked' : '';
		$point_checker = $type == 'point' ? 'checked' : '';
		echo '<tr>
            <th>Тип доставки: </th>
            <td data-title="Тип доставки">
                <ul id="shipping_method" class="woocommerce-shipping-methods">
                    <li>
                        <input type="radio" name="shipping_type" id="shipping_type_1" value="door" class="shipping_method" ' . esc_attr( $door_checker ) . '>
                        <label for="shipping_type_1">' . __( 'До двери', 'russian_post' ) . '</label>
                    </li>
                    <li>
                        <input type="radio" name="shipping_type" id="shipping_type_2" value="point" class="shipping_method" ' . esc_attr( $point_checker ) . '>
                        <label for="shipping_type_2">' . __( 'До отделения', 'russian_post' ) . '</label>
                    </li>';
                foreach ($otherMethods as $id => $method){
                    echo '<li>
                                <input type="radio" name="shipping_type" id="shipping_type_' . $id .'" value="' . $id .'" class="shipping_method" ' . esc_attr($method['checked']) . '>
                                <label for="shipping_type_' . $id .'">' . $method['label'] . '</label>
                            </li>';
                }

                echo '</ul>	
			</td>
        </tr>';
	}

	public function add_shipping_group_spoiler() {
		$shipping_total = WC()->cart->get_shipping_total() + WC()->cart->get_shipping_tax();
		$packages       = WC()->shipping()->get_packages();
		$post_rf        = false;
		$pek            = false;
		foreach ( $packages as $package ) {
			if ( ! empty( $package['rates'] ) ) {
				foreach ( $package['rates'] as $rate ) {
					if ( $rate->method_id == 'russian_post' ) {
						$post_rf = true;
					}
					if ( $rate->method_id == '3pl_shipping' ) {
						$pek = true;
					}
				}
			}
		}
		if ( $post_rf || $pek ) {
			echo '<tr>
            <th>' . __( 'Операторы доставки', 'russian_post' ) . ': </th>
            <td data-title="Операторы доставки">
					<ul id="shipping_method" class="woocommerce-shipping-methods">';
			if ( $pek ) {
				echo '<li>
						    <label for="shipping_operator_1">' . __( 'ПЭК', 'russian_post' ) . '</label>
                        </li>';
			}
			if ( $post_rf ) {
				echo '<li>
						    <label for="shipping_operator_2">' . __( 'Почта россии', 'russian_post' ) . '</label>
                        </li>';
			}
			echo '</ul>	
                </td>
            </tr>';
		}

		if ( is_cart() && intval( $shipping_total ) == 0 ) {
			echo '<tr>
                <th>' . __( 'Сумма доставки', 'russian_post' ) . ': </th>
                <td id="shipping_summ" data-title="Сумма доставки">' . __( 'Будет рассчитана на странице оформления заказа', 'russian_post' ) . '</td>
            </tr>';
		} elseif ( is_checkout() && intval( $shipping_total ) == 0 && $post_rf ) {
			echo '<tr>
                <th>' . __( 'Сумма доставки', 'russian_post' ) . ': </th>
                <td id="shipping_summ" data-title="Сумма доставки">' . __( 'Выберите отделение на карте', 'russian_post' ) . '</td>
            </tr>';
		} else {
			echo '<tr>
                <th>' . __( 'Сумма доставки', 'russian_post' ) . ': </th>
                <td id="shipping_summ" data-title="Сумма доставки">' . esc_html( $shipping_total ) . '₽</td>
            </tr>';
		}


		$show = '';
		if ( isset( $post_data['show-details'] ) ) {
			$show = 'checked';
		}
		echo '<tr>
            <td colspan="2" data-title="Тип доставки" style="text-align: center">
                <input class="hide" name="show-details" id="hd-1" type="checkbox" ' . esc_html( $show ) . '>
                <label for="hd-1">' . __( 'Детализация доставки', 'russian_post' ) . '</label>
			</td>
        </tr>';
	}

	public function add_product_spoiler() {
		if ( wp_doing_ajax() ) {
			return;
		}
		echo '<div class="show-product-details"><input class="hide" name="show-product-details" id="hd-2" type="checkbox">
                <label for="hd-2">' . __( 'Детализация заказа', 'russian_post' ) . '</label></div>';
	}

	public function add_vendor_pdf( $null, $item, $item_id ) {

		if ( ( $item['method_id'] == 'russian_post' || $item['method_id'] == 'russian_post_courier' ) && ! empty( $_GET['order_id'] ) ) {
			$seller_id = $item->get_meta( 'seller_id' );
			$order_id  = sanitize_text_field( $_GET['order_id'] );
			if ( wc_get_order_item_meta( $item_id, 'is_3pl_post_rf' ) ) {
				#$seller_id = 0;
				#$order = wc_get_order(intval($_GET['order_id']));
				#if($order->get_parent_id()) $order_id = $order->get_parent_id();
			}
			$post_rf_order_id = get_post_meta( $order_id, "_russian_post_order_id_" . strval( $seller_id ), true );

			if ( ! empty( $post_rf_order_id ) ) {
				$file = ABSPATH . 'uploads/forms/f7p_shipment_' . $post_rf_order_id . '.pdf';
				$url  = home_url() . '/uploads/forms/f7p_shipment_' . $post_rf_order_id . '.pdf';
				if ( file_exists( $file ) ) {
					echo '<td class="name">
                        <a href="' . esc_html( $url ) . '" target="_blank">' . __( 'Бланк отправки', 'russian_post' ) . '</a>
                    </td>';
				}

				$file = ABSPATH . 'uploads/forms/f103_shipment_' . $post_rf_order_id . '_' . $seller_id . '.pdf';
				$url  = home_url() . '/uploads/forms/f103_shipment_' . $post_rf_order_id . '_' . $seller_id . '.pdf';
				if ( file_exists( $file ) ) {
					echo '<td class="name">
                        <a href="' . esc_html( $url ) . '" target="_blank">' . __( 'Форма Ф103', 'russian_post' ) . '</a>
                    </td>';
				}
			}
		}
	}

	public function add_admin_vendor_pdf( $item_id, $item ) {

		if ( ( $item['method_id'] == 'russian_post' || $item['method_id'] == 'russian_post_courier' ) && ! empty( $_GET['post'] ) ) {
			if ( ! wc_get_order_item_meta( $item_id, 'is_3pl_post_rf' ) ) {
				return;
			}
			$seller_id        = 0;
			$post_rf_order_id = get_post_meta( $_GET['post'], "_russian_post_order_id_" . $seller_id, true );
			if ( ! empty( $post_rf_order_id ) ) {
				$file = ABSPATH . 'uploads/forms/f7p_shipment_' . $post_rf_order_id . '.pdf';
				$url  = home_url() . '/uploads/forms/f7p_shipment_' . $post_rf_order_id . '.pdf';
				if ( file_exists( $file ) ) {
					echo '<br>
                        <a href="' . esc_html( $url ) . '" target="_blank">' . __( 'Бланк отправки', 'russian_post' ) . '</a>
                    ';
				}

				$file = ABSPATH . 'uploads/forms/f103_shipment_' . $post_rf_order_id . '_' . $seller_id . '.pdf';
				$url  = home_url() . '/uploads/forms/f103_shipment_' . $post_rf_order_id . '_' . $seller_id . '.pdf';
				if ( file_exists( $file ) ) {
					echo '<br>
                        <a href="' . esc_html( $url ) . '" target="_blank">' . __( 'Форма Ф103', 'russian_post' ) . '</a>
                    ';
				}
			}
		}
	}

	public function add_btn_create_shipping( $item_id, $item ) {
		if ( get_class( $item ) == 'WC_Order_Item_Shipping' ) {
			if ( $item->get_method_id() == 'russian_post' || $item->get_method_id() == 'russian_post_courier' ) {
				$seller_id = $item->get_meta( 'seller_id' );
				if ( wc_get_order_item_meta( $item_id, 'is_3pl_post_rf' ) ) {
					$seller_id = 0;
				}
				$post_rf_order_id         = get_post_meta( sanitize_text_field( $_GET['post'] ), "_russian_post_order_id_" . $seller_id, true );
				$post_rf_courier_order_id = get_post_meta( sanitize_text_field( $_GET['post'] ), "_russian_post_order_courier_id_" . $seller_id, true );

				if ( empty( $post_rf_order_id ) ) {
					echo '<button class="button save_order button-primary" name="russian_post_shipping_order" type="submit">' . __( 'Создать отправление', 'russian_post' ) . '</button>';
					echo '&nbsp<button class="button save_order button-primary" name="russian_post_shipping_order_courier" type="submit">' . __( 'Создать отправление с забором', 'russian_post' ) . '</button>';
				} elseif ( ! empty( $post_rf_courier_order_id ) ) {
					echo __( 'Отправление с курьерским забором создано под номером - ', 'russian_post' ) . esc_attr( $post_rf_courier_order_id() );
				} else {
					echo __( 'Отправление создано под номером - ', 'russian_post' ) . esc_attr( $post_rf_order_id );
				}

				$is_seneded = get_post_meta( sanitize_text_field( $_GET['post'] ), "russian_post_sent", true );
				if ( ! $is_seneded ) {
					echo '&nbsp<button class="button save_order button-primary" name="russian_post_check_status" type="submit">' . __( 'Проверить статус отправки', 'russian_post' ) . '</button>';
				} else {
					echo __( ' \ Посылка получена почтой россии', 'russian_post' );
				}
			}
		}
	}

	public function init_order_add() {
		if ( isset( $_POST['russian_post_shipping_order'] ) ) {
			require_once 'includes/class-shipping-russian-post.php';
			try {
				$this->russian_post_create_order( sanitize_text_field( $_POST["post_ID"] ) );
			} catch ( Exception $e ) {
				var_dump( $e );
			}
		}
		if ( isset( $_POST['russian_post_shipping_order_courier'] ) ) {
			require_once 'includes/class-shipping-russian-post.php';
			try {
				$this->russian_post_create_order_courier( sanitize_text_field( $_POST["post_ID"] ) );
			} catch ( Exception $e ) {
				var_dump( $e );
			}
		}
		if ( isset( $_POST['russian_post_check_status'] ) ) {
			require_once 'includes/class-tracking-russian-post.php';
			try {
				$tracking = new Includes\WC_Tracking_Russian_Post( sanitize_text_field( $_POST["post_ID"] ) );
				if ( $tracking->is_sended() ) {
					$order = wc_get_order( sanitize_text_field( $_POST["post_ID"] ) );
					update_post_meta( sanitize_text_field( $_POST["post_ID"] ), 'russian_post_sent', true );
					$order->update_status( 'completed', __( 'Посылка получена почтой россии', 'russian_post' ) );
					$_POST['post_status'] = 'wc-completed';
				}
			} catch ( Exception $e ) {
				var_dump( $e );
			}
		}
	}

	public function russian_post_checkout_field_process() {

		if ( ! empty( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) ) {
			foreach ( $_POST['shipping_method'] as $i => $method ) {

				if ( stripos( $method, 'ussian_post_courier' ) ) {
					$post_api = new Includes\Russian_Post_Api( 'ONLINE_COURIER', 'ORDINARY', true );
					if ( $i !== "0" ) {
						$zip_from = get_user_meta( $i, 'post_rf_point', true );
						$zip_from = explode( ' - ', $zip_from )[0];
					} else {
						if ( ! class_exists( '\Shipping3PL\Includes\WC_Shipping_3PL' ) ) {
							return;
						}
						$gateway  = new \Shipping3PL\Includes\WC_Shipping_3PL();
						$zip_from = $gateway->settings["zipcode"];
					}
					$send_data = $post_api->get_send_price( $_POST['billing_postcode'], $zip_from, $i );
					$price     = ( $send_data["total-rate"] / 100 ) + ( $send_data["total-vat"] / 100 );
					if ( $price == 0 ) {
						wc_add_notice( __( 'По вашему адресу не осуществляется доставка "До двери". Выберите доставку "До отделения"', 'russian_post' ), 'error' );
					}
				}
				if ( stripos( $method, 'ussian_post' ) && ! stripos( $method, 'ussian_post_courier' ) ) {
					if ( stripos( $method, 'ussian_post' ) && empty( $_POST['russian_post_price'] ) ) {
						wc_add_notice( __( 'Выберите пункт выдачи', 'russian_post' ), 'error' );
					}
					if ( empty( $_POST['russian_post_index'][ $i ] ) || empty( $_POST['russian_post_address'][ $i ] ) || empty( $_POST['russian_post_city'][ $i ] ) ) {
						wc_add_notice( __( 'Выберите пункт выдачи', 'russian_post' ), 'error' );
					}
				}
			}
		}
	}

	public function russian_post_create_order( $order_id ) {
		$order          = wc_get_order( $order_id );
		$items_shipping = $order->get_items( 'shipping' );
		if ( ! empty( $items_shipping ) ) {
			$shipping_method = $items_shipping[ array_key_first( $items_shipping ) ]->get_method_id();
			if ( $shipping_method == 'russian_post' ) {
				$post_api = new Includes\Russian_Post_Api();
				$post_api->create_order( $order );
			}

			if ( $shipping_method == 'russian_post_courier' ) {
				$post_api = new Includes\Russian_Post_Api( 'ONLINE_COURIER', 'ORDINARY', true );
				$post_api->create_order_to_door( $order );
			}
		}
	}

	public function russian_post_create_order_courier( $order_id ) {
		$order          = wc_get_order( $order_id );
		$items_shipping = $order->get_items( 'shipping' );
		if ( ! empty( $items_shipping ) ) {
			$shipping_method = $items_shipping[ array_key_first( $items_shipping ) ]->get_method_id();
			if ( $shipping_method == 'russian_post' ) {
				$post_api = new Includes\Russian_Post_Api();
				$post_api->create_courier( $order );
			}
		}

	}

	public function russian_post_shipping_method( $methods ) {
		$methods['russian_post']         = 'Russian_Post\Includes\WC_Shipping_Russian_Post';
		$methods['russian_post_courier'] = 'Russian_Post\Includes\WC_Shipping_Russian_Post_Courier';

		return $methods;
	}

	public function request_a_shipping_post_init( $methods ) {
		require_once 'includes/class-shipping-russian-post.php';
		require_once 'includes/class-shipping-russian-post-courier.php';
	}

	public function plugin_assets() {
		if ( is_checkout() || is_cart() || strpos( $_SERVER['REQUEST_URI'], 'dashboard/' ) ) {
			$russian_post_shipping_args = [
				'jquery',
				'jquery-cookie'
			];
			if ( is_checkout() ) {
				wp_enqueue_script( 'russian_post_widget', 'https://widget.pochta.ru/map/widget/widget.js', [], '1.0');
				$russian_post_shipping_args[] = 'russian_post_widget';
			}
			wp_enqueue_style( 'russian_post_fancybox-css', self::$plugin_url . 'assets/css/fancybox.css', '1.0' );
			wp_enqueue_style( 'russian_post_shipping-css', self::$plugin_url . 'assets/css/style.css', '1.0' );
			wp_enqueue_style( 'russian_post_select-css', self::$plugin_url . 'assets/css/select2.min.css', '1.0' );

			wp_enqueue_script( 'russian_post_fancybox-script', self::$plugin_url . 'assets/js/fancybox.js', [ 'jquery' ], '1.0' );
			wp_enqueue_script( 'russian_post_shipping-script', self::$plugin_url . 'assets/js/scripts.js', $russian_post_shipping_args, '1.3' );
			wp_enqueue_script( 'jquery-cookie', self::$plugin_url . 'assets/js/jquery.cookie.js', [ 'jquery' ], '1.2' );
			wp_enqueue_script( 'russian_post_select-script', self::$plugin_url . 'assets/js/select2.min.js', [ 'jquery' ], '1.0' );

			wp_localize_script(
				'russian_post_shipping-script',
				'russian_post_ajax_url',
				[
					'url' => admin_url( 'admin-ajax.php' ),
				]
			);
			try {
				$shipping = new WC_Shipping_Russian_Post();
				wp_localize_script(
					'russian_post_shipping-script',
					'russian_post_widget',
					[
						'id' => $shipping->settings["widget"],
					]
				);
			} catch ( \Throwable $e ) {

			}
		}
	}

	public function init() {
		header( 'Content-Type: text/html; charset=UTF-8' );
	}

	/**
	 * @return mixed | void
	 * $_POST["vendor_id"] - int | "0" - 3pl
	 */
	public function get_post_price() {
		if ( ! empty( $_POST['zip_to'] ) && ( ! empty( $_POST['vendor_id'] ) || $_POST['vendor_id'] === "0" ) ) {
			foreach ( WC()->session->get_session_data() as $key => $item ) {
				if ( strpos( $key, 'shipping_for_package_' ) !== false ) {
					WC()->session->__unset( $key );
				}
			}
			// добавила обработку 3pl значения
			if ( $_POST["vendor_id"] !== "0" ) {
				$zip_from = get_user_meta( sanitize_text_field( $_POST['vendor_id'] ), 'post_rf_point', true );
				$zip_from = explode( ' - ', $zip_from )[0];
			} else {
				if ( ! class_exists( '\Shipping3PL\Includes\WC_Shipping_3PL' ) ) {
					return;
				}
				$gateway  = new \Shipping3PL\Includes\WC_Shipping_3PL();
				$zip_from = $gateway->settings["zipcode"];
				if ( empty( $zip_from ) ) {
					return;
				}
			}

			if ( ! class_exists( 'Includes\Russian_Post_Api' ) ) {
				require_once 'includes/class-russian-post-api.php';
			}
			if ( ! class_exists( 'Includes\WC_Shipping_Russian_Post' ) ) {
				require_once 'includes/class-shipping-russian-post.php';
			}

			$post_api                = new Includes\Russian_Post_Api();
			$send_data               = $post_api->get_send_price( sanitize_text_field( $_POST['zip_to'] ), $zip_from, $_POST['vendor_id'] );
			$result["delivery_time"] = $send_data["delivery-time"]["max-days"];
			$result["price"]         = ( $send_data ["total-rate"] / 100 ) + ( $send_data ["total-vat"] / 100 ); # получает прайс в копейках
			//добавила надбавку для ПЭКа на всякий случай
			if ( $_POST["vendor_id"] === "0" && isset( $gateway ) ) {
				$result["price"] += intval( $gateway->settings["post_rf_add"] );
			}
			$result["from"] = $zip_from;
			$result["to"]   = sanitize_text_field( $_POST['zip_to'] );
			$result["data"] = $send_data;

			return wp_send_json_success( $result );
		}
	}

	public function condition_russian_post( $packages ) {
		foreach ( $packages as $i => $package ) {
			$zip_from = get_user_meta( $package['seller_id'], 'post_rf_point', true );
			if ( ! $zip_from ) {
				foreach ( $package['rates'] as $r => $rate ) {
					if ( $rate->method_id == 'russian_post' ) {
						if ( isset( $package['extra_shipping'] ) && $package['extra_shipping'] === "3pl_shipping" ) {
							continue;
						}
						unset( $packages[ $i ]['rates'][ $r ] );
					}
					if ( $rate->method_id == 'russian_post_courier' ) {
						unset( $packages[ $i ]['rates'][ $r ] );
					}
				}
			}
		}

		return $packages;
	}

	public function save_russian_post_meta( $item, $package_key, $package, $order ) {
		$seller_id = ! empty( $package['seller_id'] ) ? $package['seller_id'] : "0";
		if ( key_exists( "extra_shipping", $package ) && "3pl_shipping" === $package["extra_shipping"] ) {
			$seller_id = "0";
		}
		$method = $item->get_method_id();
		if ( $method == 'russian_post' ) {
			if ( ! empty( $_POST['russian_post_index'] ) ) {
				$item->add_meta_data( 'russian_post_index', intval( $_POST['russian_post_index'][ $seller_id ] ), true );
			}
			if ( ! empty( $_POST['russian_post_address'] ) ) {
				$item->add_meta_data( 'russian_post_address', sanitize_text_field( $_POST['russian_post_address'][ $seller_id ] ), true );
			}
			if ( ! empty( $_POST['russian_post_city'] ) ) {
				$item->add_meta_data( 'russian_post_city', sanitize_text_field( $_POST['russian_post_city'][ $seller_id ] ), true );
			}
			if ( ! empty( $_POST['russian_post_region'] ) ) {
				$item->add_meta_data( 'russian_post_region', sanitize_text_field( $_POST['russian_post_region'][ $seller_id ] ), true );
			}
			if ( ! empty( $_POST['russian_post_price'] ) ) {
				$item->add_meta_data( 'russian_post_price', round( floatval( $_POST['russian_post_price'][ $seller_id ] ), 2 ), true );
			}
			if ( ! empty( $_POST['russian_post_delivery'] ) ) {
				$item->add_meta_data( 'russian_post_delivery', sanitize_text_field( $_POST['russian_post_delivery'][ $seller_id ] ), true );
			}
			if ( ! empty( $_POST['russian_post_vendor_index'] ) ) {
				$item->add_meta_data( 'russian_post_vendor_index', sanitize_text_field( $_POST['russian_post_vendor_index'][ $seller_id ] ), true );
			}
			if ( ! empty( $_POST['russian_post_vendor_email'] ) ) {
				$item->add_meta_data( 'russian_post_vendor_email', sanitize_text_field( $_POST['russian_post_vendor_email'][ $seller_id ] ), true );
			}

			if ( empty( $_SESSION ) ) {
				session_start();
			}
			if ( ! key_exists( "shipping_post_rf_cost", $_SESSION ) || empty( $_SESSION["shipping_post_rf_cost"] ) ) {
				$_SESSION["shipping_post_rf_cost"] = [];
			}
			if ( key_exists( $seller_id, $_SESSION["shipping_post_rf_cost"] ) ) {
				$item->add_meta_data( 'russian_post_vendor_price', floatval( $_SESSION["shipping_post_rf_cost"][ $seller_id ] ), true );
			}
		} elseif ( $method == 'russian_post_courier' ) {
			if ( ! empty( $_POST['russian_post_price'] ) ) {
				$item->add_meta_data( 'russian_post_price', round( floatval( $_POST['russian_post_price'][ $seller_id ] ), 2 ), true );
			}
			if ( ! empty( $_POST['russian_post_delivery'] ) ) {
				$item->add_meta_data( 'russian_post_delivery', sanitize_text_field( $_POST['russian_post_delivery'][ $seller_id ] ), true );
			}
			if ( ! empty( $_POST['russian_post_vendor_index'] ) ) {
				$item->add_meta_data( 'russian_post_vendor_index', sanitize_text_field( $_POST['russian_post_vendor_index'][ $seller_id ] ), true );
			}
			if ( ! empty( $_POST['russian_post_vendor_email'] ) ) {
				$item->add_meta_data( 'russian_post_vendor_email', sanitize_text_field( $_POST['russian_post_vendor_email'][ $seller_id ] ), true );
			}

			if ( empty( $_SESSION ) ) {
				session_start();
			}
			if ( ! key_exists( "shipping_post_rf_cost", $_SESSION ) || empty( $_SESSION["shipping_post_rf_cost"] ) ) {
				$_SESSION["shipping_post_rf_cost"] = [];
			}
			if ( key_exists( $seller_id, $_SESSION["shipping_post_rf_cost"] ) ) {
				$item->add_meta_data( 'russian_post_vendor_price', floatval( $_SESSION["shipping_post_rf_cost"][ $seller_id ] ), true );
			}
		}
	}

	public function checkout_shipping_additional_field( $method, $index ) {
		$id = preg_replace( '/[0-9]/', '', $method->get_id() );
		if ( $index === 0 && class_exists( '\Shipping3PL\Includes\WC_Shipping_3PL' ) ) {
			$gateway  = new \Shipping3PL\Includes\WC_Shipping_3PL();
			$zip_from = $gateway->settings["zipcode"];
		} else {
			$zip_from = get_user_meta( $index, 'post_rf_point', true );
		}

		if ( ! empty( $_POST["post_data"] ) ) {
			parse_str( $_REQUEST['post_data'], $post_data );

			if ( $id == 'russian_post' && $zip_from ) {
				if ( ! is_checkout() ) {
					echo '<br>' . __( 'будет расчитана на странице оформления заказа', 'russian_post' );
				} else {
					$zip      = '';
					$address  = '';
					$city     = '';
					$region   = '';
					$price    = '';
					$delivery = '';

					if ( ! empty( $post_data['russian_post_index'][ $index ] ) ) {
						$zip = intval( $post_data['russian_post_index'][ $index ] );
					}
					if ( ! empty( $post_data['russian_post_address'][ $index ] ) ) {
						$address = sanitize_text_field( $post_data['russian_post_address'][ $index ] );
					}
					if ( ! empty( $post_data['russian_post_city'][ $index ] ) ) {
						$city = sanitize_text_field( $post_data['russian_post_city'][ $index ] );
					}
					if ( ! empty( $post_data['russian_post_region'][ $index ] ) ) {
						$region = sanitize_text_field( $post_data['russian_post_region'][ $index ] );
					}
					if ( ! empty( $post_data['russian_post_price'][ $index ] ) ) {
						$price = floatval( $post_data['russian_post_price'][ $index ] );
					}
					if ( ! empty( $post_data['russian_post_delivery'][ $index ] ) ) {
						$delivery = sanitize_text_field( $post_data['russian_post_delivery'][ $index ] );
					}

					$zip_from = get_user_meta( $index, 'post_rf_point', true );
					$data     = get_userdata( $index );
					$email    = $data->user_email;

					echo '<input type="hidden" id="russian_post_seller_send" >';
					echo '<input type="hidden" id="russian_post_index_' . esc_attr($index) . '" value="' . esc_attr($zip) . '" name="russian_post_index[' . esc_attr($index) . ']">';
					echo '<input type="hidden" id="russian_post_index_from_' . esc_attr($index) . '" value="' . esc_attr($zip_from) . '" name="russian_post_vendor_index[' . esc_attr($index) . ']">';
					echo '<input type="hidden" id="russian_post_vendor_email_' . esc_attr($index) . '" value="' . esc_attr($email) . '" name="russian_post_vendor_email[' . esc_attr($index) . ']">';
					echo '<input type="hidden" id="russian_post_address_' . esc_attr($index) . '" value="' . esc_attr($address) . '" name="russian_post_address[' . esc_attr($index) . ']">';
					echo '<input type="hidden" id="russian_post_city_' . esc_attr($index) . '" value="' . esc_attr($city) . '" name="russian_post_city[' . esc_attr($index) . ']">';
					echo '<input type="hidden" id="russian_post_region_' . esc_attr($index) . '" value="' . esc_attr($region) . '" name="russian_post_region[' . esc_attr($index) . ']">';
					echo '<input type="hidden" id="russian_post_price_' . esc_attr($index) . '" value="' . esc_attr($price) . '" name="russian_post_price[' . esc_attr($index) . ']">';
					echo '<input type="hidden" id="russian_post_delivery_' . esc_attr($index) . '" value="' . esc_attr($delivery) . '" name="russian_post_delivery[' . esc_attr($index) . ']">';
					echo '<span id="address_postrf_' . esc_attr($index) . '">';
					if ( ! empty( $post_data['russian_post_index'][ $index ] ) && ! empty( $post_data['russian_post_city'][ $index ] ) && ! empty( $post_data['russian_post_address'][ $index ] ) ) {
						echo '<br>' . esc_html( $post_data['russian_post_index'][ $index ] ) . ', ' . esc_html( $post_data['russian_post_city'][ $index ] ) . ', ' . esc_html( $post_data['russian_post_address'][ $index ] );
						if(isset($post_data['russian_post_delivery'][ $index ]) && !empty($post_data['russian_post_delivery'][ $index ])) echo '<br> Срок доставки ' . esc_html( $post_data['russian_post_delivery'][ $index ] );
					}
					echo '</span>';
				}
			}


		}
	}

	public function checkout_map() {

		?>
        <div id="ecom-block">
			<?php _e( 'Пункт выдачи Почты России', 'russian_post' ) ?>
            <div id="ecom-widget" style="height: 500px"></div>
        </div>
		<?php
	}
}

new WC_Russian_Post();
