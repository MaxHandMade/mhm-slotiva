<?php
/**
 * Test ağ geçidi: `process_payment` çağrılarını sayar ve başarısız döner (WC yönlendirip `exit` etmesin).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

class CountingGateway extends \WC_Payment_Gateway {

	public const ID = 'slotiva_counting_card';

	/**
	 * `process_payment` çağrı sayısı.
	 *
	 * @var int
	 */
	public static int $calls = 0;

	public function __construct() {
		$this->id                 = self::ID;
		$this->method_title       = 'Sayan kart';
		$this->title              = 'Sayan kart';
		$this->enabled            = 'yes';
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->method_description = 'Yalnız test.';
	}

	/**
	 * Çağrıyı sayar; ödeme başlatmaz.
	 *
	 * @param int $order_id Sipariş.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ) {
		unset( $order_id );
		++self::$calls;
		return array( 'result' => 'failure' );
	}
}
