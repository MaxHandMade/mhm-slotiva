<?php
/**
 * Task 10 (K1): kaynak kilidi (`slotiva_res_<id>`), birim yazan HER yolda. Rakip bağlantı kilidi tutarken her yol
 * bekler, sonra LockBusyException ile düşer ve hiçbir şey yazmaz.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Reservation;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Reservation\LockBusyException;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test yardımcıları özel tabloları doğrudan okur; tablo adları Schema'dan.
// phpcs:disable WordPress.DB.RestrictedFunctions -- bağımsız ikinci bağlantı (mysqli) yalnız test için.

class ResourceLockTest extends SlotivaTestCase {

	public function set_up(): void {
		parent::set_up();
		Clock::freeze( self::utc( '2026-11-10 12:00:00' ) );
	}

	private static function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Claim isteği.
	 *
	 * @param array<string,mixed> $over Ezmeler.
	 * @return array<string,mixed>
	 */
	private function req( array $over = array() ): array {
		return array_merge(
			array(
				'product_id'      => 11,
				'product_name'    => 'Danışmanlık 30 dk',
				'order_id'        => 601,
				'order_item_id'   => 7,
				'slots'           => array( self::utc( '2026-11-17 07:00:00' ) ),
				'duration'        => 30,
				'buffer'          => 0,
				'status'          => 'held',
				'hold_expires_at' => self::utc( '2026-11-10 12:45:00' ),
				'customer'        => array(
					'name'  => 'Ayşe Test',
					'email' => 'ayse@example.test',
					'phone' => '5550000000',
				),
				'session_hash'    => md5( 'sess-res' ),
				'ip_hash'         => md5( 'ip-res' ),
			),
			$over
		);
	}

	/** Bağımsız ikinci bağlantı. */
	private function rival(): \mysqli {
		$rival = mysqli_init();
		$this->assertTrue( @mysqli_real_connect( $rival, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- test bağlantısı.
		return $rival;
	}

	/** Tablo önekli kaynak kilidi adı (tek kaynak: 1). */
	private static function res_lock(): string {
		return $GLOBALS['wpdb']->prefix . 'slotiva_res_1';
	}

	private function occupancy_count(): int {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'occupancy' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adı Schema'dan.
	}

	/**
	 * Rakip kaynak kilidini tutarken `$path` LockBusyException ile düşmeli; kilit adı kaynak kilidi olmalı, kilit bırakılmalı.
	 *
	 * @param callable $path Yazan yol.
	 */
	private function assertBlockedByResourceLock( callable $path ): void {
		$name  = self::res_lock();
		$rival = $this->rival();
		mysqli_query( $rival, "SELECT GET_LOCK('{$name}', 0)" );
		$fast = static fn(): int => 1;
		add_filter( 'slotiva_lock_timeout', $fast );
		try {
			$path();
			$this->fail( 'LockBusyException bekleniyordu' );
		} catch ( LockBusyException $e ) {
			$this->assertSame( $name, $e->lock );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $fast );
			mysqli_query( $rival, "SELECT RELEASE_LOCK('{$name}')" );
			mysqli_close( $rival );
		}
	}

	public function test_claim_blocked(): void {
		$this->assertBlockedByResourceLock( fn() => ReservationRepository::claim( $this->req() ) );
		$this->assertSame( 0, $this->occupancy_count() );
	}

	public function test_replace_for_order_blocked(): void {
		$this->assertBlockedByResourceLock( fn() => ReservationRepository::replaceForOrder( 601, $this->req() ) );
		$this->assertSame( 0, $this->occupancy_count() );
	}

	public function test_claim_for_order_pay_blocked(): void {
		$this->assertBlockedByResourceLock( fn() => ReservationRepository::claimForOrderPay( 601, $this->req(), static fn( array $s ): array => $s ) );
		$this->assertSame( 0, $this->occupancy_count() );
	}

	public function test_claim_for_checkout_draft_blocked(): void {
		$this->assertBlockedByResourceLock( fn() => ReservationRepository::claimForCheckoutDraft( 601, $this->req(), static fn( array $s ): array => $s ) );
		$this->assertSame( 0, $this->occupancy_count() );
	}

	public function test_reschedule_blocked(): void {
		$ids    = ReservationRepository::claim( $this->req() );
		$before = ReservationRepository::find( $ids[0] );
		$this->assertBlockedByResourceLock( fn() => ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-17 09:00:00' ), false, false ) );
		$after = ReservationRepository::find( $ids[0] );
		$this->assertEquals( $before?->startsAt, $after?->startsAt );
	}

	public function test_reclaim_blocked(): void {
		$ids = ReservationRepository::claim( $this->req() );
		$this->assertTrue( ReservationRepository::setStatus( $ids[0], 'expired' ) );
		$this->assertBlockedByResourceLock( fn() => ReservationRepository::reclaim( $ids[0] ) );
		$this->assertSame( 0, $this->occupancy_count() );
		$this->assertSame( 'expired', ReservationRepository::find( $ids[0] )?->status );
	}

	/** Kilit sırası: kaynak kilidi sonda; sipariş kilidi bloklandığında kaynak kilidi hiç alınmamış olmalı. */
	public function test_resource_lock_is_released_after_success(): void {
		global $wpdb;
		ReservationRepository::claim( $this->req() );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', self::res_lock() ) ) );
	}

	/** Varsayılan bekleme (süzgeçsiz) spec §15.1: en az 10 sn. */
	public function test_default_wait_is_at_least_ten_seconds(): void {
		$const = ( new \ReflectionClass( ReservationRepository::class ) )->getReflectionConstant( 'LOCK_TIMEOUT' );
		$this->assertNotFalse( $const );
		$this->assertGreaterThanOrEqual( 10, $const->getValue() );
		$this->assertSame( $const->getValue(), apply_filters( 'slotiva_lock_timeout', $const->getValue() ) );
	}
}
