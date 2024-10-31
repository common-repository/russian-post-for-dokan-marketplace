<?php
/*
 * Add Dokan functions to Multiple Packages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Check if WooCommerce is active
if ( class_exists( 'WooCommerce' ) && function_exists( 'dokan' ) ) {

	if ( class_exists( 'BE_Multiple_Packages_Dokan' ) ) {
		return;
	}

	class BE_Multiple_Packages_Dokan {

		/**
		 * Cloning is forbidden. Will deactivate prior 'instances' users are running
		 *
		 * @since 4.0
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, __( 'Cloning this class could cause catastrophic disasters!', 'bolder-multi-package-woo' ), '1.1' );
		}

		/**
		 * Unserializing instances of this class is forbidden.
		 *
		 * @since 4.0
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, __( 'Unserializing is forbidden!', 'bolder-multi-package-woo' ), '1.1' );
		}

		/**
		 * __construct function.
		 *
		 * @access public
		 * @return void
		 */
		function __construct() {
			// modify the necessary settings values through hooks and filters
			add_filter( 'be_packages_settings_type', [ $this, 'add_type_modification' ], 10, 1 );
			add_filter( 'be_packages_generate_packages', [ $this, 'generate_packages' ], 10, 3 );
			add_filter( 'woocommerce_shipping_package_name', [ $this, 'update_package_titles' ], 10, 3 );


            $this->settings_class = new BE_Multiple_Packages_Settings();
            $this->settings_class->get_package_restrictions();
            $this->package_restrictions = $this->settings_class->package_restrictions;
		}


		/**
		 * add_type_modification function.
		 *
		 * @access public
		 *
		 * @param array $package (default: array())
		 *
		 * @return array
		 */
		function add_type_modification( $separation_types ) {
			$separation_types['dokan-vendors']      = __( 'Dokan Vendors', 'bolder-multi-package-woo' );
			$separation_types['dokan-vendors-s3pl'] = __( 'Dokan Vendors + 3PL Shipping', 'bolder-multi-package-woo' );

			return $separation_types;
		}


		/**
		 * generate_packages function.
		 *
		 * @access public
		 *
		 * @param array $packages , string $split_type, array $cart_items
		 *
		 * @return null
		 */
		function generate_packages( $packages, $split_type, $cart_items ) {

			// only process Dokan configurations
			if ( sanitize_title( $split_type ) !== 'dokan-vendors' && sanitize_title( $split_type ) !== 'dokan-vendors-s3pl' ) {
				return $packages;
			}

			if ( sanitize_title( $split_type ) === 'dokan-vendors' ) {
                $package_restrictions = array_shift($this->settings_class->package_restrictions);
				// Setup items by vendor array
				$items_by_vendor = [];
				foreach ( $cart_items as $item ) {
					if ( ! $item['data']->needs_shipping() ) {
						continue;
					}

					// get vendor ID
					$vendor    = dokan_get_vendor_by_product( $item['data'] );
					$vendor_id = $vendor->id;
					if ( ! isset( $items_by_vendor[ $vendor_id ] ) ) {
						$items_by_vendor[ $vendor_id ] = [];
					}

					$items_by_vendor[ $vendor_id ][] = $item;
				}

				// Put inside packages
				foreach ( $items_by_vendor as $vendor_id => $items ) {

					if ( count( $items ) ) {
						$packages[ $vendor_id ] = [
							'contents'        => $items,
							'contents_cost'   => array_sum( wp_list_pluck( $items, 'line_total' ) ),
							'applied_coupons' => WC()->cart->applied_coupons,
							'seller_id' => $vendor_id,
							'destination'     => [
								'country'   => WC()->customer->get_shipping_country(),
								'state'     => WC()->customer->get_shipping_state(),
								'postcode'  => WC()->customer->get_shipping_postcode(),
								'city'      => WC()->customer->get_shipping_city(),
								'address'   => WC()->customer->get_shipping_address(),
								'address_2' => WC()->customer->get_shipping_address_2()
							],
							'seller_id' => $vendor_id,
						];

                       if ( count( $package_restrictions ) ) {
                            $packages[ $vendor_id ]['ship_via'] = $package_restrictions;
                        }
					}
				}
			}
			elseif ( sanitize_title( $split_type ) === 'dokan-vendors-s3pl' ) {
				$shipping_3pl_package = [
					'contents'        => [],
					'contents_cost'   => 0,
					'applied_coupons' => WC()->cart->applied_coupons,
					'destination'     => [
						'country'   => WC()->customer->get_shipping_country(),
						'state'     => WC()->customer->get_shipping_state(),
						'postcode'  => WC()->customer->get_shipping_postcode(),
						'city'      => WC()->customer->get_shipping_city(),
						'address'   => WC()->customer->get_shipping_address(),
						'address_2' => WC()->customer->get_shipping_address_2()
					],
					"extra_shipping"  => "3pl_shipping",
					"ship_via"        => [ "3pl_shipping", "russian_post" ],
				];

				if ( class_exists( "\Shipping3PL\Includes\WC_Shipping_3PL" ) ) {
					$shipping_3pl_package["ship_via"] = [];
					$gateway                          = new \Shipping3PL\Includes\WC_Shipping_3PL();
					if ( $gateway->settings["delivery_type_easyway"] === "yes" ) {
						$shipping_3pl_package["ship_via"][] = "3pl_shipping";
					}
					if ( $gateway->settings["delivery_type_post"] === "yes" ) {
						$shipping_3pl_package["ship_via"][] = "russian_post";
					}
				}


				$items_by_vendor = [];

				foreach ( $cart_items as $index => $item ) {
					$product_id   = $item["product_id"];
					$variation_id = $item["variation_id"];
					$quantity     = $item["quantity"];

					if ( ! empty( $variation_id ) ) {
						$count_3pl    = get_post_meta( $variation_id, '_3pl_stock_field', true );
						$reserved_3pl = get_post_meta( $variation_id, '3pl_reserved', true );
					} else {
						$count_3pl    = get_post_meta( $product_id, '_3pl_stock_field', true );
						$reserved_3pl = get_post_meta( $product_id, '3pl_reserved', true );
					}
					$count_3pl = intval( $count_3pl ) - intval( $reserved_3pl );
					if ( $count_3pl < 0 ) {
						$count_3pl = 0;
					}


					if ( $item['data']->needs_shipping() ) {
						$quantity_3pl    = $count_3pl >= $quantity ? $quantity : $count_3pl;
						$quantity_simple = ( $quantity - $quantity_3pl ) > 0 ? $quantity - $quantity_3pl : 0;

						if ( $quantity_3pl ) {
							$new_item                           = $item;
							$new_item["quantity"]               = $quantity_3pl;
							$shipping_3pl_package["contents"][] = $new_item;
						}
						if ( $quantity_simple ) {
							$new_item             = $item;
							$new_item["quantity"] = $quantity_simple;
							$vendor               = dokan_get_vendor_by_product( $item['data'] );
							$vendor_id            = $vendor->id;
							if ( ! isset( $items_by_vendor[ $vendor_id ] ) ) {
								$items_by_vendor[ $vendor_id ] = [];
							}

							$items_by_vendor[ $vendor_id ][] = $new_item;
						}
					}
				}


				if ( ! empty( $shipping_3pl_package["contents"] ) ) {
					$shipping_3pl_package["contents_cost"] = array_sum( wp_list_pluck( $shipping_3pl_package["contents"], 'line_total' ) );
					$packages[]                            = $shipping_3pl_package;
				}

				foreach ( $items_by_vendor as $vendor_id => $items ) {

					if ( count( $items ) ) {
						$packages[ $vendor_id ] = [
							'contents'        => $items,
							'contents_cost'   => array_sum( wp_list_pluck( $items, 'line_total' ) ),
							'applied_coupons' => WC()->cart->applied_coupons,
							'destination'     => [
								'country'   => WC()->customer->get_shipping_country(),
								'state'     => WC()->customer->get_shipping_state(),
								'postcode'  => WC()->customer->get_shipping_postcode(),
								'city'      => WC()->customer->get_shipping_city(),
								'address'   => WC()->customer->get_shipping_address(),
								'address_2' => WC()->customer->get_shipping_address_2()
							]
						];
					}
				}

			}


			return $packages;
		}


		/**
		 * update_package_titles function.
		 *
		 * @access public
		 *
		 * @param string $current_title , int $pkg_id, array $items
		 *
		 * @return string
		 */
		function update_package_titles( $current_title, $pkg_id, $package ) {
			// only update if Dokan separation enabled
			$split_type = sanitize_text_field( get_option( 'multi_packages_type' ) );
			if ( $split_type !== 'dokan-vendors' ) {
				return $current_title;
			}

			// exit if package error
			if ( ! isset( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
				return $current_title;
			}

			// find vendor information
			$vendor_id = intval( $pkg_id );
			if ( $vendor_id > 0 ) {
				// get vendor profile
				$store_info = dokan_get_store_info( $vendor_id );

				if ( ! empty( $store_info['store_name'] ) ) {
					$current_title = $store_info['store_name'];
				}

			}

			return $current_title;

		}

	}

	new BE_Multiple_Packages_Dokan();

}

?>
