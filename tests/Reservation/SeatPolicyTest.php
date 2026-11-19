<?php
/**
 * Task 11: SeatPolicy kabul kuralları (K3 sahiplik, K4 etkin kapasite, K6 ızgara) — doğrudan `admit()`/`place()`.
 *
 * Takvim CheckoutFixtures'tan: hafta içi 10:00–12:00 İstanbul; Salı 2026-11-17 10:00 yerel = 07:00Z.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Reservation;

use MHM\Slotiva\Availability\SlotCalculator;
use MHM\Slotiva\Availability\Units;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SeatPolicy;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Schedule\ExceptionRepository;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test yardımcıları özel tabloları doğrudan okur; tablo adları Schema'dan.

class SeatPolicyTest extends SlotivaTestCase {

	use CheckoutFixtures;

	/**
	 * Ürün başına süzgeç kapasitesi (yoksa 1).
	 *
	 * @var array<int,int>
	 */
	private array $caps = array();

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		$this->caps = array();
		add_filter( 'slotiva_product_capacity', array( $this, 'cap_filter' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'slotiva_product_capacity', array( $this, 'cap_filter' ), 10 );
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * Test kapasite süzgeci.
	 *
	 * @param mixed $value      Gelen değer.
	 * @param mixed $product_id Ürün.
	 * @return mixed
	 */
	public function cap_filter( $value, $product_id ) {
		return $this->caps[ (int) $product_id ] ?? $value;
	}

	private static function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Siparişsiz onaylı alma.
	 *
	 * @param int                 $pid  Ürün.
	 * @param string              $slot UTC başlangıç.
	 * @param array<string,mixed> $over Ezmeler.
	 */
	private function take( int $pid, string $slot = '2026-11-17 07:00:00', array $over = array() ): int {
		return ReservationRepository::claim(
			array_merge(
				array(
					'product_id'    => $pid,
					'slots'         => array( self::utc( $slot ) ),
					'duration'      => 30,
					'status'        => 'confirmed',
					'payment_state' => 'manual',
				),
				$over
			)
		)[0];
	}

	/**
	 * 30 dk'lık başlangıcın birimleri.
	 *
	 * @param string $slot    UTC başlangıç.
	 * @param int    $minutes Süre (dk).
	 * @return list<\DateTimeImmutable>
	 */
	private static function units( string $slot, int $minutes = 30 ): array {
		return Units::span( self::utc( $slot ), $minutes, 0 );
	}

	private function seat_of( int $id ): int {
		global $wpdb;
		$table = Schema::table( 'occupancy' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(seat) FROM {$table} WHERE reservation_id = %d", $id ) );
	}

	private function capacity_of( int $id ): int {
		global $wpdb;
		$table = Schema::table( 'reservations' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT capacity FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Reddi bekler.
	 *
	 * @param callable $call  Çağrı.
	 * @param string   $start Beklenen dolu başlangıç (UTC).
	 */
	private function assertRefused( callable $call, string $start = '2026-11-17 07:00:00' ): void {
		try {
			$call();
			$this->fail( 'SlotTakenException bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			$this->assertSame( array( $start ), array_map( static fn( \DateTimeImmutable $d ): string => $d->format( 'Y-m-d H:i:s' ), $e->taken ) );
		}
	}

	public function test_empty_unit_gives_seat_one_and_stamps_current(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 4;
		$p                  = SeatPolicy::place( 1, $pid, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 4 );
		$this->assertSame( 1, $p['seat'] );
		$this->assertSame( 4, $p['stamp'] );
		$this->assertFalse( $p['exempt'] );
	}

	public function test_lite_default_occupied_unit_is_refused(): void {
		$pid = $this->bookable();
		$this->take( $pid );
		$this->assertRefused( fn() => SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 1 ) );
	}

	public function test_same_product_same_start_joins_next_seat(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$a                  = $this->take( $pid );
		$this->assertSame( 1, $this->seat_of( $a ) );
		$this->assertSame( 2, SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 3 ) );
	}

	public function test_variation_is_the_same_product(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$this->take( $pid, '2026-11-17 07:00:00', array( 'variation_id' => 9001 ) );
		$b = $this->take( $pid, '2026-11-17 07:00:00', array( 'variation_id' => 9002 ) );
		$this->assertSame( 2, $this->seat_of( $b ), 'K3: sahiplik ebeveyn ürünle; varyasyon ayrı ürün değil' );
	}

	public function test_other_product_cannot_enter_owned_unit(): void {
		$pid                  = $this->bookable();
		$other                = $this->bookable();
		$this->caps[ $pid ]   = 3;
		$this->caps[ $other ] = 3;
		$this->take( $pid );
		$this->assertRefused( fn() => SeatPolicy::admit( 1, $other, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 3 ) );
	}

	public function test_overlapping_other_start_is_refused(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$this->take( $pid );
		// 10:00 grubu varken 10:15 başlangıcı (kısmen çakışan) doludur (kural 4).
		$this->assertRefused( fn() => SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:15:00' ), self::units( '2026-11-17 07:15:00' ), 3 ), '2026-11-17 07:15:00' );
	}

	public function test_orphan_occupancy_row_is_foreign(): void {
		global $wpdb;
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$wpdb->insert(
			Schema::table( 'occupancy' ),
			array(
				'resource_id'    => 1,
				'unit_start'     => '2026-11-17 07:15:00',
				'seat'           => 1,
				'reservation_id' => 9999,
			)
		);
		$this->assertRefused( fn() => SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 3 ) );
	}

	public function test_completed_and_no_show_rows_occupy(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 2;
		$a                  = $this->take( $pid );
		$b                  = $this->take( $pid );
		$this->assertTrue( ReservationRepository::setStatus( $a, 'completed' ) );
		$this->assertTrue( ReservationRepository::setStatus( $b, 'no_show' ) );
		// K3: kural durum değil doluluk satırı üzerinden; completed/no_show ACTIVE değil ama birim tutar.
		$this->assertRefused( fn() => SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 2 ) );
	}

	public function test_effective_capacity_is_min_of_snapshots_and_current(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 2;
		$a                  = $this->take( $pid );
		$this->assertSame( 2, $this->capacity_of( $a ) );

		// Ürün kapasitesi büyüdü: saat ilk katılımcının değeriyle (2) sınırlı.
		$this->caps[ $pid ] = 5;
		$b                  = $this->take( $pid );
		$this->assertSame( 2, $this->capacity_of( $b ), 'K4: katılımcıya etkin kapasite damgalanır (ürün kapasitesi değil)' );
		$this->assertRefused( fn() => $this->take( $pid ) );
	}

	public function test_lowered_capacity_closes_new_joins_immediately(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$this->take( $pid );
		$this->caps[ $pid ] = 1;
		$this->assertRefused( fn() => $this->take( $pid ) );
	}

	public function test_moving_capacity_one_row_only_into_empty_time(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$this->take( $pid );
		// Taşınan satırın `capacity`'si 1 → sınır min(3, 3, 1) = 1; hedefte bir satır var.
		$this->assertRefused( fn() => SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 1, 424242 ) );
		$this->assertSame( 1, SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 08:00:00' ), self::units( '2026-11-17 08:00:00' ), 1, 424242 ) );
	}

	public function test_moving_reservation_own_rows_are_excluded(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 1;
		$a                  = $this->take( $pid );
		// Kendi birimleriyle örtüşen kaydırma (10:00 → 10:15) yalnız başka satır yokken serbest.
		$this->assertSame( 1, SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:15:00' ), self::units( '2026-11-17 07:15:00' ), 1, $a ) );
	}

	/**
	 * Paylaşılan ızgara (`SlotCalculator::gridStarts`) — UTC 'Y-m-d H:i:s'.
	 *
	 * @param int    $duration Süre (dk).
	 * @param string $date     Yerel Y-m-d.
	 * @return list<string>
	 */
	private static function grid( int $duration, string $date ): array {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		return array_map( static fn( \DateTimeImmutable $d ): string => $d->format( 'Y-m-d H:i:s' ), $day ? SlotCalculator::gridStarts( $duration, $day, WeeklySchedule::load(), ExceptionRepository::between( $date, $date ) ) : array() );
	}

	public function test_off_grid_group_gets_no_joiners(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$a                  = $this->take( $pid, '2026-11-17 07:30:00' ); // 10:30 yerel, 30 dk ızgarada.
		$this->assertSame( 2, SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:30:00' ), self::units( '2026-11-17 07:30:00' ), 3 ), 'ızgara üstünde katılım açık' );

		// Süre 45 dk: ızgara 10:00, 10:45 → 10:30 grubu ızgara dışı (K6).
		$product = wc_get_product( $pid );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$product->update_meta_data( '_slotiva_duration', '45' );
		$product->save();
		// Beklenti elle yazılmaz: tek ızgara kaynağı (SlotCalculator::gridStarts) müşteri adaylarıyla aynı olmalı ve 10:30'u içermemeli.
		$bp = BookableProduct::from( $product );
		$this->assertNotNull( $bp );
		$this->assertSame( self::grid( 45, '2026-11-18' ), array_map( static fn( array $s ): string => ( new \DateTimeImmutable( $s['start'] ) )->format( 'Y-m-d H:i:s' ), SlotCalculator::slots( $bp, '2026-11-18' ) ), 'ızgara = müşteri adayları (ön süre dışındaki gün)' );
		$this->assertNotContains( '2026-11-17 07:30:00', self::grid( 45, '2026-11-17' ) );
		$this->assertContains( '2026-11-18 07:45:00', self::grid( 45, '2026-11-18' ) );
		$this->assertRefused( fn() => SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:30:00' ), self::units( '2026-11-17 07:30:00' ), 3 ), '2026-11-17 07:30:00' );
		$this->assertSame( 1, $this->seat_of( $a ), 'mevcut katılımcı yerinde kalır' );
		// Yeni ızgaradaki (10:45) grup katılım alır.
		$this->take( $pid, '2026-11-18 07:45:00', array( 'duration' => 45 ) );
		$this->assertSame( 2, SeatPolicy::admit( 1, $pid, self::utc( '2026-11-18 07:45:00' ), self::units( '2026-11-18 07:45:00', 45 ), 3 ) );
	}

	public function test_same_order_same_start_is_exempt_and_keeps_seat(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$this->take( $pid );
		$own = $this->take( $pid, '2026-11-17 07:00:00', array( 'order_id' => 777 ) );
		$this->take( $pid );
		$this->caps[ $pid ] = 1;
		$p                  = SeatPolicy::place( 1, $pid, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 1, null, 777 );
		$this->assertTrue( $p['exempt'], 'K5: kapasite düşmüş olsa da kendi koltuğu' );
		$this->assertSame( $this->seat_of( $own ), $p['seat'] );
		$this->assertSame( 3, $p['stamp'], 'kendi değeri taşınır' );
		// Başka sipariş aynı anda reddedilir.
		$this->assertRefused( fn() => SeatPolicy::admit( 1, $pid, self::utc( '2026-11-17 07:00:00' ), self::units( '2026-11-17 07:00:00' ), 1, null, 778 ) );
	}
}
