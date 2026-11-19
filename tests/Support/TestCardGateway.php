<?php
/**
 * Test için çevrimiçi kart ağ geçidi: ödemeyi başlatır, siparişi `pending` bırakır (PayTR iframe gibi).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

class TestCardGateway extends \WC_Payment_Gateway {

	public const ID = 'slotiva_test_card';

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = 'Test kart';
		$this->title              = 'Test kart';
		$this->enabled            = 'yes';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->method_description = 'Yalnız test.';
	}

	/**
	 * Ödeme sayfasına yönlendirir; sipariş `pending` kalır.
	 *
	 * @param int $order_id Sipariş.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		return array(
			'result'   => 'success',
			'redirect' => $order ? $order->get_checkout_payment_url( true ) : '',
		);
	}
}
