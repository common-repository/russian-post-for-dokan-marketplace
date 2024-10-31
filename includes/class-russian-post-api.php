<?php

namespace Russian_Post\Includes;

use Russian_Post\Includes\WC_Shipping_Russian_Post;

class Russian_Post_Api {
	public $version;
	public $send_url;
	public $token;
	public $address_3pl;
	public $address_3pl_post;
	public $key;
	public $mail_type;
	public $send_type;
	public $courier;
	public $mail_category;
	private $courier_3pl_order = false;

	public function __construct( $type = 'ONLINE_PARCEL', $category = 'ORDINARY', $courier = false ) {
		$this->version = time();
		try {
			$settings = get_option( 'woocommerce_russian_post_settings' );
			//здесь изменила запрос настроек
			$token                  = $settings['token'];
			$this->address_3pl      = $settings['address_3pl'];
			$this->address_3pl_post = $settings['address_3pl_post'];
			$send_url               = $settings['address'];
			$login                  = $settings['login'];
			$password               = $settings['password'];
			if ( ! empty( $token ) && ! empty( $send_url ) && ! empty( $login ) && ! empty( $password ) ) {
				$this->send_url  = $send_url;
				$this->key       = base64_encode( $login . ':' . $password );
				$this->token     = $token;
				$this->mail_type = $type;
				if ( $courier ) {
					$this->send_type = 'DEFAULT';
				} else {
					$this->send_type = 'DEMAND';
				}

				$this->courier       = $courier;
				$this->mail_category = $category;
			}
		} catch ( Exception $e ) {
			error_log( 'Выброшено исключение: ', $e->getMessage(), "\n" );
		}

	}

	public function get_products_by_vendor( $seller_id, $order ) {
		$order_items        = $order->get_items();
		$products['prod']   = [];
		$products['weight'] = 0;
		$products['price']  = 0;
		foreach ( $order_items as $item ) {
			$_product = wc_get_product( $item['product_id'] );
			$post_obj = get_post( $item['product_id'] );
			if ( $post_obj->post_author ) {
				$vendor_id = $post_obj->post_author;
				if ( $vendor_id == $seller_id ) {
					$weight             = intval( $_product->get_weight() ) * 1000;
					$price              = get_post_meta( $item['product_id'], '_price', true );
					$products['prod'][] = [
						'code'        => '444D' . $item['product_id'],
						'description' => $_product->get_title(),
						'goods-type'  => 'GOODS',
						'item-number' => $item['product_id'],
						'quantity'    => $item['quantity'],
						'value'       => $price,
						'weight'      => $weight,
						'vat-rate'    => 0, #todo
						'lineattr'    => 1, #todo
						'payattr'     => 1, #todo
					];
					$products['weight'] = $products['weight'] + $weight;
					$products['price']  = $products['price'] + ( intval( $price ) * $item['quantity'] );
				}
			}
		}

		return $products;
	}

	public function get_allowed_points() {
		$resp = $this->HttpRequest( '/1.0/user-shipping-points', [] );

		return $resp;
	}

	public function create_courier( $order, $shipping_item = null ) {
		error_log( "create_courier " . $order->get_id() );
		wc_get_logger()->debug( print_r( [ "create_courier" => $order->get_id() ], true ), [ 'source' => "create_courier" ] );
		if ( ! dokan_is_sub_order( $order->get_id() ) && $order->get_meta( 'has_sub_order' ) && empty( $shipping_item ) ) {
			return;
		}
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {

			$method = $item->get_method_id();
			//todo если будет забор не из Пэка переписать
			//$seller_id = $item->get_meta('seller_id');
			$seller_id = 0;
			wc_get_logger()->debug( print_r( [
				"seller_id"    => $seller_id,
				"method"       => $method,
				"address"      => $this->address_3pl,
				"address_post" => $this->address_3pl,
			], true ), [ 'source' => "create_courier" ] );
			if ( ! empty( $shipping_item ) && $shipping_item->get_id() === $item->get_id() && ! empty( $this->address_3pl ) ) {
				wc_get_logger()->debug( print_r( [
					"create_courier" => $order->get_id(),
					"not_empty"      => 1
				], true ), [ 'source' => "create_courier" ] );
				$address = $this->address_3pl;
				$this->create_order_courier( $order, $item, $address, $this->address_3pl_post );
			} elseif ( empty( $shipping_item ) && ! empty( $seller_id ) && $method == 'russian_post' && ! empty( $this->address_3pl ) ) {
				wc_get_logger()->debug( print_r( [
					"create_courier" => $order->get_id(),
					"not_empty"      => 1
				], true ), [ 'source' => "create_courier" ] );
				$address = $this->address_3pl;
				$this->create_order_courier( $order, $item, $address, $this->address_3pl_post );
			}
		}
	}

	public function create_order_courier( $order, $item, $address, $address_post = null ) {
		$this->courier_3pl_order = true;
		$seller_id = $item->get_meta( 'seller_id' );
		if ( wc_get_order_item_meta( $item->get_id(), "is_3pl_post_rf", true ) ) {
			$seller_id = 0;
		}
		$this->mail_type     = 'ONLINE_COURIER'; //EMS
		$this->mail_category = 'ORDINARY';
		if ( empty( $address_post ) ) {
			$address_post = $address;
		}
		//140012
		$data = $this->get_order_data( $order, $item, $seller_id, $address_post );
		wc_get_logger()->debug( print_r( [ "order_data" => $data ], true ), [ 'source' => "create_courier" ] );

		if ( $data ) {
			//140012
			$resultId = $this->create_shipping_order( $data, $order, $seller_id );
			wc_get_logger()->debug( print_r( [ "resultId" => $resultId ], true ), [ 'source' => "create_courier" ] );
			if ( $resultId ) {
				//140073
				$address_id = $this->check_courier_address( $address );
				wc_get_logger()->debug( print_r( [ "address_id" => $address_id ], true ), [ 'source' => "create_courier" ] );
				$batch_data = $this->create_shipping_batch( $order, $seller_id, $resultId );
				wc_get_logger()->debug( print_r( [ "batch_data" => $batch_data ], true ), [ 'source' => "create_courier" ] );
				if ( ! empty( $address_id ) && ! empty( $batch_data ) ) {
					//140073
					$address_dates = $this->check_courier_date( $address_id );
					wc_get_logger()->debug( print_r( [ "address_dates" => $address_dates ], true ), [ 'source' => "create_courier" ] );
					if ( $address_dates ) {
						$courier_data['order-date']       = $address_dates[0]["arrival-date"];
						$courier_data['time-interval-id'] = 1;
						//140073
						$courier_data['address-id'] = $address_id;
						//140073
						$courier_data['address'] = $address;
						$batches                 = wp_list_pluck( $batch_data['batches'], 'batch-name' );
						//140073
						$courier = $this->create_courier_order( $data, $batches, $courier_data );
						wc_get_logger()->debug( print_r( $courier, true ), [ 'source' => "create_courier" ] );
						if ( ! empty( $courier ) && count( $courier["errors"] ) == 0 ) {
							wc_get_logger()->debug( print_r( [
								"created" => $courier,
								"data"    => $courier_data
							], true ), [ 'source' => "create_courier" ] );
							wc_get_logger()->debug( print_r( [
								"order" => $order->get_id(),
								"meta"  => '_russian_post_order_courier_id_' . $seller_id
							], true ), [ 'source' => "create_courier" ] );
							update_post_meta( $order->get_id(), '_russian_post_order_courier_id_' . $seller_id, $courier["order-number"] );
							update_post_meta( $order->get_id(), '_russian_post_order_courier_data_' . $seller_id, json_encode( $courier_data ) );
							$this->get_form_f7p( $resultId, $seller_id );
							$this->get_form_f103( $resultId, $seller_id, $batch_data["batches"][0]["batch-name"] );
						}
					}
				}
			}
		}
	}

	public function create_courier_order( $data, $batches, $courier_data ) {
		$data = [
			'address'          => $courier_data['address'],
			'address-id'       => $courier_data['address-id'],
			'batch-names'      => $batches,
			'contact-name'     => $data[0]["sender-name"],
			'contact-phone'    => $data[0]["tel-address-from"],
			'country-to-code'  => 1,
			'order-date'       => $courier_data['order-date'],
			'print-documents'  => true,
			'time-interval-id' => $courier_data['time-interval-id'],
		];
		$resp = $this->HttpRequest( '/1.0/courier/order', $data, CURLOPT_PUT );

		return $resp;
	}

	public function check_courier_address( $address ) {
		$resp = $this->HttpRequest( '/1.0/courier/EMS/find-addresses' );
		if ( ! empty( $resp[0] ) && ! empty( $resp[0]['address-id'] ) ) {
			return $resp[0]['address-id'];
		}

		return $resp;
	}

	public function check_courier_date( $address_id ) {
		$resp = $this->HttpRequest( '/1.0/courier/' . $address_id . '/EMS/find-dates' );
		if ( ! empty( $resp['address-dates'] ) ) {
			return $resp['address-dates'];
		}

		return false;
	}

	public function create_order( $order ) {
		if ( ! dokan_is_sub_order( $order->get_id() ) && $order->get_meta( 'has_sub_order' ) ) {
			return;
		}
		wc_get_logger()->debug( print_r( [ "create_order" => $order->get_id() ], true ), [ 'source' => "postrf_create_order" ] );
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$method           = $item->get_method_id();
			$seller_id        = $item->get_meta( 'seller_id' );
			$post_rf_order_id = get_post_meta( $order->get_id(), "_russian_post_order_id_" . $seller_id, true );
			if ( ! empty( $post_rf_order_id ) ) {
				return;
			}
			if ( ! empty( $seller_id ) && $method == 'russian_post' ) {
				$post_spot_address = get_user_meta( $seller_id, 'post_rf_point', true );
				if ( ! empty( $post_spot_address ) ) {
					$data = $this->get_order_data( $order, $item, $seller_id, $post_spot_address );
					if ( $data ) {
						$post_rf_order_id = $this->create_shipping_order( $data, $order, $seller_id );
						if ( $post_rf_order_id ) {
							wc_get_logger()->debug( print_r( [ "order_created" => $order->get_id() ], true ), [ 'source' => "postrf_create_order" ] );
							$resp = $this->create_shipping_batch( $order, $seller_id, $post_rf_order_id );
							$this->get_form_f7p( $post_rf_order_id, $seller_id );
							$this->get_form_f103( $post_rf_order_id, $seller_id, $resp["batches"][0]["batch-name"] );
						} else {
							wc_get_logger()->debug( 'Order not created ' . print_r( $post_rf_order_id, true ), [ 'source' => "postrf_create_order" ] );
						}
					}
				}
			}
		}
	}

	public function create_order_to_door( $order ) {
		if ( ! dokan_is_sub_order( $order->get_id() ) && $order->get_meta( 'has_sub_order' ) ) {
			return;
		}
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$method           = $item->get_method_id();
			$seller_id        = $item->get_meta( 'seller_id' );
			$post_rf_order_id = get_post_meta( $order->get_id(), "_russian_post_order_id_" . $seller_id, true );
			if ( ! empty( $post_rf_order_id ) ) {
				return;
			}
			if ( ! empty( $seller_id ) && $method == 'russian_post_courier' ) {
				$post_spot_address = get_user_meta( $seller_id, 'post_rf_point', true );
				if ( ! empty( $post_spot_address ) ) {
					$data = $this->get_order_data( $order, $item, $seller_id, $post_spot_address );
					if ( $data ) {
						$post_rf_order_id = $this->create_shipping_order( $data, $order, $seller_id );
						if ( $post_rf_order_id ) {
							$resp = $this->create_shipping_batch( $order, $seller_id, $post_rf_order_id );
							$this->get_form_f7p( $post_rf_order_id, $seller_id );
							$this->get_form_f103( $post_rf_order_id, $seller_id, $resp["batches"][0]["batch-name"] );
						}
					}
				}
			}
		}
	}

	public function create_shipping_batch( $order, $seller_id, $resultId ) {
		$data = [ $resultId ];
		$resp = $this->HttpRequest( '/1.0/user/shipment', $data, CURLOPT_POST );
		if ( $resp ) {
			update_post_meta( $order->get_id(), '_russian_post_order_created_' . $seller_id, true );
			update_post_meta( $order->get_id(), '_russian_post_order_batch_name_' . $seller_id, $resp["batches"][0]["batch-name"] );

			return $resp;
		}
	}

	public function create_shipping_order( $data, $order, $seller_id ) {
		$resp = $this->HttpRequest( '/1.0/user/backlog', $data, CURLOPT_PUT );
		if ( ! empty( $resp['result-ids'] ) ) {
			$result_id = $resp['result-ids'][ array_key_first( $resp['result-ids'] ) ];
			update_post_meta( $order->get_id(), '_russian_post_order_id_' . $seller_id, $result_id );

			$resp = $this->HttpRequest( '/1.0/backlog/' . $result_id );
			if ( ! empty( $resp['barcode'] ) ) {
				update_post_meta( $order->get_id(), '_russian_post_barcode_' . $seller_id, $resp['barcode'] );
			}

			return $result_id;
		}

		return $resp;
	}

	public function get_barcode( $order_id, $seller_id ) {
		$post_rf_barcode = get_post_meta( $order_id, "_russian_post_barcode_" . $seller_id, true );
		if ( ! empty( $post_rf_barcode ) ) {
			return $post_rf_barcode;
		} else {
			$resp = $this->HttpRequest( '/1.0/backlog/' . $order_id );
			if ( ! empty( $resp['barcode'] ) ) {
				return $resp['barcode'];
			}
		}

		return false;
	}

	public function get_order_data( $order, $item, $seller_id, $post_spot_address ) {
		$norm_address_spot = $this->normAddress( $post_spot_address );
		if ( ! empty( $norm_address_spot ) ) {
			if ( preg_match( "/^[0-9]{6}/", $post_spot_address, $matches ) ) {
				$post_spot_index = $matches[0];
			} else {
				$post_spot_index = $norm_address_spot[0]['index'];
			}

			if ( $post_spot_index === "140000" ) {
				$post_spot_index = "140012";
			}
			$post_spot_city   = $norm_address_spot[0]['place'];
			$post_spot_region = $norm_address_spot[0]['region'];
		} else {
			return false;
		}

		if ( $this->mail_type == 'ONLINE_COURIER' && $this->courier_3pl_order ) {
			$index_to   = $order->shipping_postcode;
			$city_to    = $order->shipping_city;
			$region_to  = $order->shipping_state;
			$addr       = explode( ',', $order->shipping_address_1 );
			$address_to = $addr[0];
			$house_to   = $addr[1];
		} else {
			$index_to   = $item->get_meta( 'russian_post_index', true );
			$address_to = $item->get_meta( 'russian_post_address', true );
			$city_to    = $item->get_meta( 'russian_post_city', true );
			$region_to  = $item->get_meta( 'russian_post_region', true );
		}


		if ( $seller_id !== 0 ) {
			$vendor      = dokan_get_vendor( $seller_id );
			$phoneVendor = str_replace( '+7', "8", $vendor->get_phone() );
			$phoneVendor = str_replace( [ ")", "-", " ", "(" ], "", $phoneVendor );
			$name        = $vendor->get_shop_name() . ", " . $vendor->get_name();
			//$inn = get_user_meta($seller_id, 'post_rf_shipping_inn', true);
			$products = $this->get_products_by_vendor( $seller_id, $order );
			$mass     = $products['weight_item'] ? $products['weight_item'] : 500;
		} else {
			if ( ! class_exists( "\Russian_Post\Includes\WC_Shipping_Russian_Post" ) ) {
				require_once __DIR__ . "/class-shipping-russian-post.php";
			}
			$shipping_method = new \Russian_Post\Includes\WC_Shipping_Russian_Post();
			$phoneVendor     = str_replace( '+7', "8", $shipping_method->settings["manager_phone"] );
			$phoneVendor     = str_replace( [ ")", "-", " ", "(" ], "", $phoneVendor );
			$name            = str_replace( '+7', "8", $shipping_method->settings["manager_name"] );
			$mass = 500;
		}

		$phoneUser = str_replace( '+7', "8", $order->get_billing_phone() );
		$phoneUser = str_replace( [ ")", "-", " ", "(" ], "", $phoneUser );


		$address_to_norm = $index_to . ', ' . $city_to . ', ' . $address_to;
		$norm_address    = $this->normAddress( $address_to_norm );

		if ( empty( $house_to ) ) {
			if ( ! empty( $norm_address[0]["house"] ) ) {
				$house_to = $norm_address[0]["house"];
			} else {
				$house_to = '';
			}
		}

		$data = [
			[
				'add-to-mmo'           => false,
				#Отметка 'Добавить в многоместное отправление'
				'address-from'         => [ #Адрес забора заказа
					'address-type' => 'DEFAULT', #Тип адреса отправителя
					'index'        => $post_spot_index, #Индекс почтового отделения
					'place'        => $post_spot_city, #Населенный пункт почтового отделения
					'region'       => $post_spot_region, #Регион почтового отделения
				],
				'address-type-to'      => $this->send_type,
				#Тип адреса доставки DEFAULT для доставки до двери
				'fragile'              => false,
				#Установлена ли отметка "Осторожно/Хрупкое"
				'given-name'           => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				#Имя получателя
				/*'goods' => array(
					'items' => $products['prod']
				),*/
				'house-to'             => $house_to,
				'index-to'             => $index_to,
				'mail-category'        => $this->mail_category,
				'mail-direct'          => 643,
				#Код страны
				'mail-type'            => $this->mail_type,
				'mass'                 => $mass,
				'order-num'            => $order->get_id(),
				'place-to'             => $city_to,
				'sms-notice-recipient' => 0,
				'postoffice-code'      => $post_spot_index,
				'recipient-name'       => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				#Имя получателя
				'region-to'            => $region_to,
				'street-to'            => $address_to,
				'surname'              => $order->get_billing_last_name(),
				'tel-address'          => $phoneUser,
				'tel-address-from'     => $phoneVendor,
				'sender-name'          => $name,
			]
		];

		return $data;
	}

	public function normAddress( $address ) {
		$data = [
			[
				'id'               => '123',
				'original-address' => $address,
			]
		];
		$resp = $this->HttpRequest( '/1.0/clean/address', $data, CURLOPT_POST );

		return $resp;
	}

	public function get_spot_data( $post_spot_index ) {
		$resp = $this->HttpRequest( '/postoffice/1.0/' . $post_spot_index, [] );

		return $resp;
	}

	public function get_vendors_weight_cart( $seller_id ) {
		global $woocommerce;
		$items               = $woocommerce->cart->get_cart();
		$data['weight_item'] = 0;
		$data['price']       = 0;
		foreach ( $items as $item => $values ) {
			$_product = wc_get_product( $values['product_id'] );
			$post_obj = get_post( $values['product_id'] );
			if ( $post_obj->post_author ) {
				$vendor_id = $post_obj->post_author;
				if ( $vendor_id == $seller_id ) {
					$price               = intval( get_post_meta( $values['product_id'], '_price', true ) );
					$weight              = intval( $_product->get_weight() ) * 100;
					$data['weight_item'] = $data['weight_item'] + $weight;
					$data['price']       = $data['price'] + $price;
				}
			}
		}

		return $data;
	}

	public function get_send_price( $zip_to, $zip_from, $vendor_id ) {
		$data_items = $this->get_vendors_weight_cart( $vendor_id );
		$data       = [
			'index-from'           => $zip_from,
			'index-to'             => $zip_to,
			'courier'              => $this->courier,
			'inventory'            => false,
			'declared-value'       => $data_items['price'],
			'mail-category'        => $this->mail_category,
			'mail-type'            => $this->mail_type,
			'mass'                 => $data_items['weight_item'] ? $data_items['weight_item'] : 500,
			//'fragile' => true,
			'with-order-of-notice' => false,
			'with-simple-notice'   => false,
		];
		$resp       = $this->HttpRequest( '/1.0/tariff', $data, CURLOPT_POST );

		return $resp;
	}

	public function get_form_f7p( $order_id, $vendor_id ) {
		$type = get_post_meta( $vendor_id, "post_rf_form_type" );
		if ( $type ) {
			$resp = $this->HttpRequest( '/1.0/forms/' . $order_id . "/f7pdf?print-type=" . $type, [], '', false );
		} else {
			$resp = $this->HttpRequest( '/1.0/forms/' . $order_id . "/f7pdf", [], '', false );
		}
		if ( ! file_exists( ABSPATH . 'uploads/forms' ) ) {
			mkdir( ABSPATH . 'uploads/forms', 0770, true );
		}
		$filename = ABSPATH . 'uploads/forms/f7p_shipment_' . $order_id . '.pdf';

		if ( ! empty( $resp ) ) {
			return file_put_contents( $filename, $resp );
		}

		return false;
	}

	public function get_form_f103( $order_id, $vendor_id, $batchName ) {
		$respCheckin = $this->HttpRequest( '/1.0/batch/' . $batchName . '/checkin', [], CURLOPT_POST );
		$resp        = $this->HttpRequest( '/1.0/forms/' . $batchName . '/f103pdf', [], '', false );
		if ( ! file_exists( ABSPATH . 'uploads/forms' ) ) {
			mkdir( ABSPATH . 'uploads/forms', 0770, true );
		}
		$filename = ABSPATH . 'uploads/forms/f103_shipment_' . $order_id . '_' . $vendor_id . '.pdf';

		if ( ! empty( $resp ) ) {
			return file_put_contents( $filename, $resp );
		}

		return false;
	}


	private function HttpRequest($url_path, $data = [], $method = '', $is_json = true) {
        $req_method = 'GET';
        if ($method == CURLOPT_POST) {
            $req_method = 'POST';
        } elseif ($method == CURLOPT_PUT) {
            $req_method = 'PUT';
        }
        $response = wp_remote_request($this->send_url . $url_path, [
            'method'  => $req_method,
            'headers' => [
                'Authorization'        => 'AccessToken ' . $this->token,
                'X-User-Authorization' => 'Basic ' . $this->key,
                'Content-Type'         => 'application/json;charset=UTF-8'
            ],
            'body'    => json_encode($data)
        ]);
        $body     = wp_remote_retrieve_body($response);
        if ($is_json) {
            return json_decode($body, true);
        }
        return $body;
    }
}
