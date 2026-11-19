<?php
/**
 * Kapasite kaynağı testleri: süzgeç, sıkıştırma ve anlık görüntü birleşimi.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Reservation;

use MHM\Slotiva\Reservation\Capacity;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class CapacityTest extends SlotivaTestCase {

	public function tear_down(): void {
		remove_all_filters( 'slotiva_product_capacity' );
		parent::tear_down();
	}

	public function test_default_is_one(): void {
		$this->assertSame( 1, Capacity::forProduct( 123 ) );
	}

	public function test_filter_value_used(): void {
		add_filter( 'slotiva_product_capacity', static fn() => 7 );
		$this->assertSame( 7, Capacity::forProduct( 123 ) );
	}

	public function test_capacity_filter_garbage_is_clamped(): void {
		$cases = array(
			array( 0, 1 ),
			array( -3, 1 ),
			array( 'abc', 1 ),
			array( null, 1 ),
			array( 100000, 50 ),
		);
		foreach ( $cases as $case ) {
			remove_all_filters( 'slotiva_product_capacity' );
			add_filter( 'slotiva_product_capacity', static fn() => $case[0] );
			$this->assertSame( $case[1], Capacity::forProduct( 123 ) );
		}
	}

	public function test_filter_receives_product_id(): void {
		$seen = null;
		add_filter(
			'slotiva_product_capacity',
			static function ( $value, $product_id ) use ( &$seen ) {
				$seen = $product_id;
				return $value;
			},
			10,
			2
		);
		Capacity::forProduct( 456 );
		$this->assertSame( 456, $seen );
	}

	public function test_effective_null_snapshot_uses_current(): void {
		add_filter( 'slotiva_product_capacity', static fn() => 5 );
		$this->assertSame( 5, Capacity::effective( 123, null ) );
	}

	public function test_deleted_product_uses_min_of_snapshot_and_filter(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'product' ) );
		wp_delete_post( $id, true );
		add_filter( 'slotiva_product_capacity', static fn() => 5 );
		$this->assertSame( 3, Capacity::effective( $id, 3 ) );
		$this->assertSame( 5, Capacity::effective( $id, 8 ) );
		$this->assertSame( 1, Capacity::effective( $id, 0 ) );
	}
}
