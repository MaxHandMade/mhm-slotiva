<?php
/**
 * SlotValidator: biçim, sayı, adet, örtüşme, takvim — doluluk HARİÇ.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use MHM\Slotiva\Checkout\SlotValidator;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation/BookableProduct alanları sözleşmeyle camelCase.

class SlotValidatorTest extends SlotivaTestCase {

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
	 * Ürün görünümü.
	 *
	 * @param array<string,mixed> $meta Meta.
	 */
	private function bp( array $meta = array() ): BookableProduct {
		$bp = BookableProduct::from( wc_get_product( $this->bookable( $meta ) ) );
		$this->assertNotNull( $bp );
		return $bp;
	}

	/**
	 * Beklenen hata kodunu ölçer.
	 *
	 * @param string           $code  Kod.
	 * @param callable():mixed $call Çağrı.
	 */
	private function assert_route_error( string $code, callable $call ): RouteException {
		try {
			$call();
		} catch ( RouteException $e ) {
			$this->assertSame( $code, $e->getErrorCode() );
			$this->assertSame( 400, $e->getCode() );
			return $e;
		}
		$this->fail( "RouteException {$code} bekleniyordu." );
	}

	public function test_valid_slot_returns_utc_datetimes(): void {
		$out = SlotValidator::validate( $this->bp(), array( '2026-11-17T10:00:00+03:00' ), 1 );
		$this->assertCount( 1, $out );
		$this->assertSame( '2026-11-17T07:00:00Z', SlotValidator::iso( $out[0] ) );
	}

	public function test_empty_is_no_slots(): void {
		$this->assert_route_error( 'slotiva_no_slots', fn() => SlotValidator::validate( $this->bp(), array(), 1 ) );
	}

	public function test_bad_format_rejected(): void {
		$bp = $this->bp();
		foreach ( array( 'yarın 10:00', '2026-11-17 07:00:00', '2026-02-30T07:00:00Z', 1763362800, array( 'x' ) ) as $bad ) {
			$this->assert_route_error( 'slotiva_bad_format', fn() => SlotValidator::validate( $bp, array( $bad ), 1 ) );
		}
	}

	public function test_fixed_qty_is_one(): void {
		$bp = $this->bp();
		$this->assertCount( 1, SlotValidator::validate( $bp, array( '2026-11-17T07:00:00Z' ), 1 ) );
		$e = $this->assert_route_error( 'slotiva_qty_mismatch', fn() => SlotValidator::validate( $bp, array( '2026-11-17T07:00:00Z' ), 2 ) );
		$this->assertSame( 1, $e->getAdditionalData()['expected'] );
		$this->assert_route_error( 'slotiva_count_range', fn() => SlotValidator::validate( $bp, array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ), 1 ) );
	}

	public function test_per_slot_count_range(): void {
		$bp = $this->bp(
			array(
				'_slotiva_pricing'   => 'per_slot',
				'_slotiva_min_slots' => '2',
				'_slotiva_max_slots' => '3',
			)
		);
		$this->assert_route_error( 'slotiva_count_range', fn() => SlotValidator::validate( $bp, array( '2026-11-17T07:00:00Z' ), 1 ) );
		$this->assertCount( 2, SlotValidator::validate( $bp, array( '2026-11-17T07:30:00Z', '2026-11-17T07:00:00Z' ), 2 ) );
		$this->assert_route_error( 'slotiva_count_range', fn() => SlotValidator::validate( $bp, array_fill( 0, 11, '2026-11-17T07:00:00Z' ), 11 ) );
	}

	public function test_duplicate_slots_rejected(): void {
		$bp = $this->bp(
			array(
				'_slotiva_pricing'   => 'per_slot',
				'_slotiva_max_slots' => '3',
			)
		);
		// Aynı an, farklı yazım.
		$this->assert_route_error( 'slotiva_duplicate', fn() => SlotValidator::validate( $bp, array( '2026-11-17T07:00:00Z', '2026-11-17T10:00:00+03:00' ), 2 ) );
	}

	public function test_overlapping_slots_rejected(): void {
		Rules::save( array( 'bufferMin' => 15 ) );
		$bp = $this->bp(
			array(
				'_slotiva_pricing'   => 'per_slot',
				'_slotiva_max_slots' => '3',
			)
		);
		// 10:00 + 30 dk + 15 dk ara 10:30 birimini kaplar; 10:30 dilimi onunla örtüşür (aksi hâlde kendi 1062'si → yanlış 409).
		$this->assert_route_error( 'slotiva_overlap', fn() => SlotValidator::validate( $bp, array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ), 2 ) );
		$this->assertCount( 2, SlotValidator::validate( $bp, array( '2026-11-17T07:00:00Z', '2026-11-17T08:00:00Z' ), 2 ) );
	}

	public function test_offgrid_and_outside_schedule_rejected(): void {
		$bp = $this->bp();
		$this->assert_route_error( 'slotiva_not_bookable', fn() => SlotValidator::validate( $bp, array( '2026-11-17T07:10:00Z' ), 1 ) ); // 10:10.
		$this->assert_route_error( 'slotiva_not_bookable', fn() => SlotValidator::validate( $bp, array( '2026-11-17T06:00:00Z' ), 1 ) ); // 09:00 kapalı.
		$this->assert_route_error( 'slotiva_not_bookable', fn() => SlotValidator::validate( $bp, array( '2026-11-16T07:00:00Z' ), 1 ) ); // Ön süre içinde.
		$this->assert_route_error( 'slotiva_not_bookable', fn() => SlotValidator::validate( $bp, array( '2026-12-15T07:00:00Z' ), 1 ) ); // Ufuk dışı.
	}

	public function test_validator_ignores_occupancy(): void {
		$bp = $this->bp();
		ReservationRepository::claim(
			array(
				'product_id' => $bp->productId,
				'slots'      => array( new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ),
				'duration'   => 30,
				'status'     => 'confirmed',
			)
		);
		$this->assertCount( 1, SlotValidator::validate( $bp, array( '2026-11-17T07:00:00Z' ), 1 ) );
	}
}
