<?php

add_action( 'plugins_loaded', 'woocommerce_multiple_packaging_init', 106 );

function woocommerce_multiple_packaging_init() {

	/**
	 * Check if WooCommerce is active
	 */
	if ( class_exists( 'woocommerce' ) || class_exists( 'WooCommerce' ) ) {

		if ( ! class_exists( 'BE_Multiple_Packages' ) ) {

			// Include Necessary files
			require_once( 'class-settings.php' );

			class BE_Multiple_Packages {

				public $settings_class;

				/**
				 * Constructor.
				 */
				public function __construct() {

					// Setup compatibility functions
					include_once( plugin_dir_path( __FILE__ ) . 'compatibility/comp.dokan.php' );

					$this->settings_class = new BE_Multiple_Packages_Settings();
					$this->settings_class->get_package_restrictions();
					$this->package_restrictions = $this->settings_class->package_restrictions;

					add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'generate_packages' ] );
				}


				function generate_packages( $packages ) {
					if ( get_option( 'multi_packages_enabled' ) ) {
						// Reset the packages
						$packages = [];
						//$settings_class = new BE_Multiple_Packages_Settings();
						$package_restrictions = $this->settings_class->package_restrictions;
						$free_classes         = sanitize_text_field( get_option( 'multi_packages_free_shipping' ) );
						$split_type           = sanitize_text_field( get_option( 'multi_packages_type' ) );
						$cart_items           = WC()->cart->get_cart();

						// Determine Type of Grouping
						switch ( $split_type ) {
							case 'per-product':
								// separate each item into a package
								$n = 0;
								foreach ( $cart_items as $item ) {
									if ( $item['data']->needs_shipping() ) {
										// Put inside packages
										$packages[ $n ] = [
											'contents'        => [ $item ],
											'contents_cost'   => array_sum( wp_list_pluck( [ $item ], 'line_total' ) ),
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

										// Determine if 'ship_via' applies
										$key = $item['data']->get_shipping_class_id();
										if ( $free_classes && in_array( $key, $free_classes ) ) {
											$packages[ $n ]['ship_via'] = [ 'free_shipping' ];
										} elseif ( count( $package_restrictions ) && isset( $package_restrictions[ $key ] ) ) {
											$packages[ $n ]['ship_via'] = $package_restrictions[ $key ];
										}
										$n ++;
									}
								}
								break;
							case 'shipping-class':
								// Create arrays for each shipping class
								$shipping_classes = [ '' => 'other' ];
								$other            = [];
								$get_classes      = WC()->shipping->get_shipping_classes();
								foreach ( $get_classes as $key => $class ) {
									$shipping_classes[ $class->term_id ] = $class->slug;
									$array_name                          = $class->slug;
									$$array_name                         = [];
								}

								// Sort bulky from regular
								foreach ( $cart_items as $item ) {
									if ( $item['data']->needs_shipping() ) {
										$item_class = $item['data']->get_shipping_class();
										if ( isset( $item_class ) && $item_class != '' ) {
											foreach ( $shipping_classes as $class_id => $class_slug ) {
												if ( $item_class == $class_slug ) {
													array_push( $$class_slug, $item );
												}
											}
										} else {
											$other[] = $item;
										}
									}
								}

								// Put inside packages
								$n = 0;
								foreach ( $shipping_classes as $key => $value ) {
									if ( count( $$value ) ) {
										$packages[ $n ] = [
											'contents'        => $$value,
											'contents_cost'   => array_sum( wp_list_pluck( $$value, 'line_total' ) ),
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

										// Determine if 'ship_via' applies
										if ( $free_classes && in_array( $key, $free_classes ) ) {
											$packages[ $n ]['ship_via'] = [ 'free_shipping' ];
										} elseif ( count( $package_restrictions ) && isset( $package_restrictions[ $key ] ) ) {
											$packages[ $n ]['ship_via'] = $package_restrictions[ $key ];
										}
										$n ++;
									}
								}
								break;

							case 'shipping-method':
								$simple_shipping_package = $shipping_3pl_package = [
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
									"extra_shipping"  => "simple",
								];

								$shipping_3pl_package['ship_via']       = [ '3pl_shipping' ];
								$shipping_3pl_package['extra_shipping'] = '3pl_shipping';

								// Sort bulky from regular
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
									$product   = new WC_Product( $product_id );
									$count_all = $product->get_stock_quantity();

									if ( $item['data']->needs_shipping() ) {
										$quantity_3pl    = $count_3pl >= $quantity ? $quantity : $count_3pl;
										$quantity_simple = ( $quantity - $quantity_3pl ) > 0 ? $quantity - $quantity_3pl : 0;

										if ( $quantity_3pl ) {
											$new_item                           = $item;
											$new_item["quantity"]               = $quantity_3pl;
											$shipping_3pl_package["contents"][] = $new_item;
										}
										if ( $quantity_simple ) {
											$new_item                              = $item;
											$new_item["quantity"]                  = $quantity_simple;
											$simple_shipping_package["contents"][] = $new_item;
										}
									}
								}

								if ( empty( $shipping_3pl_package["contents"] ) ) {
									$packages = [ $simple_shipping_package ];
								} elseif ( empty( $simple_shipping_package["contents"] ) ) {
									$packages = [ $shipping_3pl_package ];
								} else {
									$packages = [ $shipping_3pl_package, $simple_shipping_package ];
								}

								// Put inside packages
								break;

							default:
								$packages = apply_filters( 'be_packages_generate_packages', $packages, $split_type, $cart_items );
								break;
						}

						return $packages;
					}
				}

			} // end class BE_Multiple_Packages

			return new BE_Multiple_Packages();

		} // end IF class 'BE_Multiple_Packages' exists

	} // end IF woocommerce exists

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'be_multiple_packages_plugin_action_links' );
	function be_multiple_packages_plugin_action_links( $links ) {
		return array_merge(
			[
				'settings' => '<a href="' . get_bloginfo( 'wpurl' ) . '/wp-admin/admin.php?page=wc-settings&tab=multiple_packages">Settings</a>',
				'support'  => '<a href="http://bolderelements.net/" target="_blank">Bolder Elements</a>'
			],
			$links
		);
	}
} // end function: woocommerce_multiple_packaging_init
