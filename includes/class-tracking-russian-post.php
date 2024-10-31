<?php

namespace Russian_Post\Includes;

use SoapClient;
use SoapParam;

class WC_Tracking_Russian_Post {
	public $post_id;
	public $login;
	public $password;
	public $url;

	public function __construct( $post_id ) {
		$this->post_id  = $post_id;
		$settings       = get_option( 'woocommerce_russian_post_settings' );
		$this->login    = $settings['loginTracking'];
		$this->password = $settings['passwordTracking'];
		$this->url      = 'https://tracking.russianpost.ru/rtm34?wsdl';
	}

	public function is_sended() {
		$order = wc_get_order( $this->post_id );
		foreach ( $order->get_items( 'shipping' ) as $item_id => $item ) {
			$seller_id       = $item->get_meta( 'seller_id' );
			$api             = new Russian_Post_Api();
			$post_rf_barcode = $api->get_barcode( $this->post_id, $seller_id );
			if ( ! empty( $post_rf_barcode ) ) {
				$client2 = new SoapClient( $this->url, [ 'trace' => 1, 'soap_version' => SOAP_1_2 ] );
				$params3 = [
					'OperationHistoryRequest' => [ 'Barcode'     => $post_rf_barcode,
					                               'MessageType' => '0',
					                               'Language'    => 'RUS'
					],
					'AuthorizationHeader'     => [ 'login' => $this->login, 'password' => $this->password ]
				];

				$result  = $client2->getOperationHistory( new SoapParam( $params3, 'OperationHistoryRequest' ) );
				$history = $result->OperationHistoryData->historyRecord;
				foreach ( $history as $status ) {
					$operation = $status->OperationParameters->OperType->Id;
					if ( $operation == 1 ) {
						return true;
					}
				}
			}
		}

		return false;
	}
}