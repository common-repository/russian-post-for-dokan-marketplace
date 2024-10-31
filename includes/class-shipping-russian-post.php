<?php

namespace Russian_Post\Includes;

use WC_Shipping_Method;

class WC_Shipping_Russian_Post extends WC_Shipping_Method {

	/**
	 * Constructor. The instance ID is passed to this.
	 *
	 * @param int $instance_id
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'russian_post';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Почта России' );
		$this->method_description = __( 'Метод доставки Почты России' );
		$this->supports           = [
			'settings',
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];

		$this->init();

		$this->enabled = $this->settings['enabled'];
		$this->title   = isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Shipping 3PL', 'shipping_3pl' );
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
		if ( is_checkout() ) {
			foreach ( WC()->session->get_session_data() as $key => $item ) {
				if ( strpos( $key, 'shipping_for_package_' ) !== false ) {
					WC()->session->__unset( $key );
				}
			}
		}
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
				'label'       => __( 'Включить доставку почтой России', 'russian_post' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			],

			'title' => [
				'title'       => __( 'Название метода', 'russian_post' ),
				'type'        => 'text',
				'description' => __( 'Это название доставки пользователь увидит при оформлении заказа.', 'russian_post' ),
				'default'     => __( 'Доставка почты России', 'russian_post' ),
				'desc_tip'    => true,
			],

			'description' => [
				'title'       => __( 'Описание', 'russian_post' ),
				'type'        => 'textarea',
				'description' => __( 'Это описание доставки пользователь увидит при оформлении заказа.', 'russian_post' ),
				'default'     => __( '', 'russian_post' ),
			],

			'token' => [
				'title' => __( 'Токен', 'russian_post' ),
				'type'  => 'text',
			],

			'address' => [
				'title' => __( 'Адрес апи отправки', 'russian_post' ),
				'type'  => 'text',
			],

			'address_3pl' => [
				'title' => __( 'Адрес 3pl склада', 'russian_post' ),
				'type'  => 'text',
			],

			'address_3pl_post' => [
				'title' => __( 'Адрес почтового отделения для 3pl склада', 'russian_post' ),
				'type'  => 'text',
			],

			'manager_name' => [
				'title' => __( 'Контактное имя для курьера (ПЭК)', 'russian_post' ),
				'type'  => 'text',
			],

			'manager_phone' => [
				'title' => __( 'Контактный телефон для курьера (ПЭК)', 'russian_post' ),
				'type'  => 'text',
			],


			/*'address_calc'         => array(
				'title'         => __('Адрес апи калькулятора', 'russian_post'),
				'type'          => 'text',
			),

			'address_tracking'         => array(
				'title'         => __('Адрес апи отслеживания', 'russian_post'),
				'type'          => 'text',
			),*/

			'login' => [
				'title' => __( 'Логин от апи почты РФ', 'russian_post' ),
				'type'  => 'text',
			],

			'password' => [
				'title' => __( 'Пароль', 'russian_post' ),
				'type'  => 'password',
			],

			'loginTracking' => [
				'title' => __( 'Логин от апи отслеживания почты РФ', 'russian_post' ),
				'type'  => 'text',
			],

			'passwordTracking' => [
				'title' => __( 'Пароль от апи отслеживания почты РФ', 'russian_post' ),
				'type'  => 'password',
			],

			'widget' => [
				'title' => __( 'Id виджета карты', 'russian_post' ),
				'type'  => 'text',
			],

			'free_shipping'  => [
				'title' => __( 'Сумма корзины для бесплатной доставки', 'russian_post' ),
				'type'  => 'number',
			],
			'fix_shipping'   => [
				'title' => __( 'Фиксированная сумма доставки', 'russian_post' ),
				'type'  => 'number',
			],
			'disable_cancel' => [
				'title'       => __( 'Включить/Выключить', 'russian_post' ),
				'label'       => __( 'Выключить возможность продавцам менять статус заказов?', 'russian_post' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			],


		];


	}

	/**
	 * calculate_shipping function.
	 *
	 * @param array $package (default: array())
	 */
	public function calculate_shipping( $package = [] ) {

		$rate_data = [
			'id'    => $this->id . $this->instance_id,
			'label' => $this->title,
		];
		$prices    = [];

		$total = WC()->cart->cart_contents_total;

		$taxes = WC()->cart->get_cart_contents_taxes();

		$total = $total + array_sum( $taxes ) - WC()->cart->shipping_total - WC()->cart->shipping_tax_total;

		$fix  = floatval( $this->settings["fix_shipping"] );
		$free = floatval( $this->settings["free_shipping"] );

		if ( ! empty( $_REQUEST['post_data'] ) ) {
			parse_str( $_REQUEST['post_data'], $post_data );
			if ( ! empty( $post_data ) && ! empty( $post_data['russian_post_price'] ) ) {
				foreach ( $post_data['russian_post_price'] as $seller => $price ) {
					if ( ! empty( $price ) ) {
						$this->add_rate( $rate_data );

						if ( empty( $_SESSION ) ) {
							session_start();
						}
						if ( ! key_exists( "shipping_post_rf_cost", $_SESSION ) || empty( $_SESSION["shipping_post_rf_cost"] ) ) {
							$_SESSION["shipping_post_rf_cost"] = [];
						}
						$_SESSION["shipping_post_rf_cost"][ $seller ] = intval( $price );

						if ( ! empty( $fix ) ) {
							$price = $fix;
						}
						if ( ! empty( $free ) && $total >= $free ) {
							$price = 0;
						}
						$prices[ $seller ] = intval( $price );
					}
				}

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

				if ( ! empty( $fix ) ) {
					$price = $fix;
				}
				if ( ! empty( $free ) && $total >= $free ) {
					$price = 0;
				}

				if ( $seller == $seller_id || $seller == 0 ) {
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
}