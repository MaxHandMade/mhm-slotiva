<?php
/**
 * Store API uzantıları: sepet öğesi verisi ve `cart/extensions` adet geri çağrısı.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Checkout;

use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;
use MHM\Slotiva\Tests\Support\TestCardGateway;

class StoreApiExtensionTest extends SlotivaTestCase {

	use CheckoutFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
	}

	public function tear_down(): void {
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * `per_slot` ürün.
	 *
	 * @param int $min En az.
	 * @param int $max En çok.
	 */
	private function per_slot( int $min = 1, int $max = 5 ): int {
		return $this->bookable(
			array(
				'_slotiva_pricing'   => 'per_slot',
				'_slotiva_min_slots' => (string) $min,
				'_slotiva_max_slots' => (string) $max,
				'_slotiva_policy'    => '24 saat öncesine kadar ücretsiz değiştirebilirsin.',
			)
		);
	}

	private function update_count( $count ): \WP_REST_Response {
		return $this->store(
			'POST',
			'/wc/store/v1/cart/extensions',
			array(
				'namespace' => 'slotiva',
				'data'      => array( 'count' => $count ),
			)
		);
	}

	private function qty(): int {
		$lines = array_values( WC()->cart->get_cart() );
		return (int) $lines[0]['quantity'];
	}

	public function test_cart_item_extension_exposes_bookable_meta(): void {
		$pid   = $this->per_slot( 2, 4 );
		$plain = $this->plain_product();
		WC()->cart->add_to_cart( $pid, 2 );
		WC()->cart->add_to_cart( $plain, 1 );

		$res = $this->store( 'GET', '/wc/store/v1/cart' );
		$this->assertSame( 200, $res->get_status() );
		$by_id = array();
		foreach ( $res->get_data()['items'] as $item ) {
			$by_id[ (int) $item['id'] ] = (array) $item['extensions'];
		}
		$this->assertSame(
			array(
				'bookable'   => true,
				'min'        => 2,
				'max'        => 4,
				'pricing'    => 'per_slot',
				'duration'   => 30,
				'product_id' => $pid,
				'policy'     => '24 saat öncesine kadar ücretsiz değiştirebilirsin.',
			),
			(array) $by_id[ $pid ]['slotiva']
		);
		$this->assertFalse( ( (array) $by_id[ $plain ]['slotiva'] )['bookable'] );
	}

	public function test_update_callback_sets_quantity(): void {
		WC()->cart->add_to_cart( $this->per_slot(), 1 );
		$res = $this->update_count( 3 );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 3, $this->qty() );
		$this->assertSame( 3, (int) $res->get_data()['items'][0]['quantity'] );
	}

	public function test_update_callback_clamps_count(): void {
		WC()->cart->add_to_cart( $this->per_slot( 2, 4 ), 2 );
		$this->update_count( 0 );
		$this->assertSame( 2, $this->qty(), '0 satırı silmez, min\'e kırpılır' );
		$this->update_count( 99 );
		$this->assertSame( 4, $this->qty() );
		$this->update_count( 'abc' );
		$this->assertSame( 2, $this->qty() );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_update_callback_ignores_fixed_pricing(): void {
		WC()->cart->add_to_cart( $this->bookable(), 1 );
		$this->update_count( 3 );
		$this->assertSame( 1, $this->qty() );
	}

	public function test_quantity_change_supersedes_previous_draft_hold(): void {
		WC()->cart->add_to_cart( $this->per_slot(), 2 );
		$res = $this->checkout( array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ), TestCardGateway::ID );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$oid = (int) $res->get_data()['order_id'];
		$this->assertCount( 2, ReservationRepository::forOrder( $oid, true ) );
		$this->assertSame( $oid, (int) WC()->session->get( 'store_api_draft_order' ) );

		$this->update_count( 3 );
		$this->assertSame( 3, $this->qty() );
		$this->assertSame( array(), ReservationRepository::forOrder( $oid, true ) );
		foreach ( ReservationRepository::forOrder( $oid ) as $r ) {
			$this->assertSame( 'superseded', $r->status );
		}
		$this->assertSame( 0, self::rows( 'occupancy' ) );

		// Aynı adet: hiçbir şey değişmez (ve yeni tutma bozulmaz).
		$res = $this->checkout( array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z', '2026-11-17T08:00:00Z' ), TestCardGateway::ID );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$new = (int) $res->get_data()['order_id'];
		$this->assertNotSame( $oid, $new, 'sepet özeti değişti → yeni sipariş' );
		$this->update_count( 3 );
		$this->assertCount( 3, ReservationRepository::forOrder( $new, true ) );
	}
}
