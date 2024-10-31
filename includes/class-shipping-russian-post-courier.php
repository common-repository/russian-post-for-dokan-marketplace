<?php

namespace Russian_Post\Includes;

use WC_Shipping_Method;

class WC_Shipping_Russian_Post_Courier extends WC_Shipping_Method {

	/**
	 * Constructor. The instance ID is passed to this.
	 *
	 * @param int $instance_id
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'russian_post_courier';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Почта России Курьер' );
		$this->method_description = __( 'Метод доставки курьер Почты России' );
		$this->supports           = [
			'settings',
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];

		$this->init();

		$this->enabled = $this->settings['enabled'];
		$this->title   = isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Почта России Курьер', 'russian_post' );
		$this->logging = "yes";

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/** Init your settings
	 *
	 *
	 * @return void
	 */
	public function init() {
		// Load the settings API
		$this->init_form_fields();
		$this->init_settings();

		// Save settings in admin if you have any defined
		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * Define settings field for this shipping
	 * @return void
	 */
	function init_form_fields() {

		$this->form_fields = [

			'enabled' => [
				'title'       => __( 'Включить/Выключить', 'russian_post' ),
				'label'       => __( 'Включить доставку почтой России до двери', 'russian_post' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			],

			'title' => [
				'title'       => __( 'Название метода', 'russian_post' ),
				'type'        => 'text',
				'description' => __( 'Это название доставки пользователь увидит при оформлении заказа.', 'russian_post' ),
				'default'     => __( 'Доставка почты России до двери', 'russian_post' ),
				'desc_tip'    => true,
			],

			'description'   => [
				'title'       => __( 'Описание', 'russian_post' ),
				'type'        => 'textarea',
				'description' => __( 'Это описание доставки пользователь увидит при оформлении заказа.', 'russian_post' ),
				'default'     => __( '', 'russian_post' ),
			],
			'free_shipping' => [
				'title' => __( 'Сумма корзины для бесплатной доставки', 'russian_post' ),
				'type'  => 'number',
			],
			'fix_shipping'  => [
				'title' => __( 'Фиксированная сумма доставки', 'russian_post' ),
				'type'  => 'number',
			],
		];


	}

	/**
	 * calculate_shipping function.
	 *
	 * @param array $package (default: array())
	 */
	public function calculate_shipping( $package = [] ) {
		if ( ! empty( $_REQUEST['post_data'] ) ) {
			parse_str( $_REQUEST['post_data'], $post_data );

			error_log( print_r( $post_data, true ) );
			if ( empty( $post_data['billing_postcode'] ) ) {

				$this->add_rate(
					[
						'id'    => $this->id . $this->instance_id,
						'label' => $this->title . ' ' . __( '(для рассчета стоимости укажите адрес)', 'russian_post' ),
						'cost'  => 0,
					]
				);
			} else {
				$total = WC()->cart->cart_contents_total;

				$taxes = WC()->cart->get_cart_contents_taxes();

				$total = $total + array_sum( $taxes ) - WC()->cart->shipping_total - WC()->cart->shipping_tax_total;

				$fix  = floatval( $this->settings["fix_shipping"] );
				$free = floatval( $this->settings["free_shipping"] );

				$post_api = new Russian_Post_Api( 'ONLINE_COURIER', 'ORDINARY', true );

				$prices = [];

				foreach ( $post_data['russian_post_vendor_index'] as $vendor_id => $vendor_data ) {
					$zip_from  = explode( ' - ', sanitize_text_field( $vendor_data ) )[0];
					$send_data = $post_api->get_send_price( sanitize_text_field( $post_data['billing_postcode'] ), $zip_from, $vendor_id );

					$price = ( $send_data["total-rate"] / 100 ) + ( $send_data["total-vat"] / 100 );

					if ( $price == 0 ) {
						$prices[ $vendor_id ] = null;
					} else {
						if ( empty( $_SESSION ) ) {
							session_start();
						}
						if ( ! key_exists( "shipping_post_rf_cost", $_SESSION ) || empty( $_SESSION["shipping_post_rf_cost"] ) ) {
							$_SESSION["shipping_post_rf_cost"] = [];
						}
						$_SESSION["shipping_post_rf_cost"][ $vendor_id ] = $price;


						if ( ! empty( $fix ) ) {
							$price = $fix;
						}
						if ( ! empty( $free ) && $total >= $free ) {
							$price = 0;
						}
						$prices[ $vendor_id ] = $price;
					}
				}

				$seller_id = 0;
				foreach ( $package['contents'] as $content ) {
					$product = ! empty( $content['product_id'] ) ? wc_get_product( $content['product_id'] ) : '';
					if ( $product && $product->needs_shipping() ) {
						$seller_id = (int) get_post_field( 'post_author', $content['product_id'] );
					}
				}

				if ( ! empty( $prices ) && $seller_id ) {
					foreach ( $prices as $seller => $price ) {
						if ( is_null( $price ) ) {
							if ( $seller == $seller_id ) {
								$this->add_rate(
									[
										'id'      => $this->id . $this->instance_id,
										'label'   => __( 'По вашему адресу не осуществляется доставка "До двери". Выберите доставку "До отделения"', 'russian_post' ),
										'cost'    => null,
										'package' => $package,
									]
								);
							}
						} else {
							if ( ! empty( $fix ) ) {
								$price = $fix;
							}
							if ( ! empty( $free ) && $total >= $free ) {
								$price = 0;
							}

							if ( $seller == $seller_id ) {
								$this->add_rate(
									[
										'id'      => $this->id . $this->instance_id,
										'label'   => $this->title,
										'cost'    => $price,
										'package' => $package,
									]
								);
							}
						}
					}
				} else {
					$this->add_rate(
						[
							'id'    => $this->id . $this->instance_id,
							'label' => $this->title,
							'cost'  => 0,
						]
					);
				}
			}
		} else {
			$this->add_rate(
				[
					'id'    => $this->id . $this->instance_id,
					'label' => $this->title . ' ' . __( '(для рассчета стоимости укажите адрес)', 'russian_post' ),
					'cost'  => 0,
				]
			);
		}

	}

}