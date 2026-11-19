<?php
/**
 * Task 6b: kilitler, oturum sahipliği + kota, beklenen durum, sipariş geneli reclaim, tam-deneme yeniden denemesi ve
 * bağımsız bağlantıyla (ayrı PHP süreci) gerçek yarışlar.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Reservation;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Reservation\HoldQuotaException;
use MHM\Slotiva\Reservation\LockBusyException;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\Race;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test yardımcıları özel tabloları doğrudan okur/yazar; tablo adları Schema'dan.
// phpcs:disable WordPress.DB.RestrictedFunctions -- bağımsız ikinci bağlantı (mysqli) yalnız test için.

class ReservationConcurrencyTest extends SlotivaTestCase {

	/**
	 * Yakalanan durum olayları.
	 *
	 * @var list<array<int,mixed>>
	 */
	private array $events = array();

	public function set_up(): void {
		parent::set_up();
		$this->events = array();
		add_action( 'slotiva_reservation_status', array( $this, 'capture' ), 10, 4 );
		Clock::freeze( self::utc( '2026-11-10 12:00:00' ) );
	}

	public function tear_down(): void {
		remove_action( 'slotiva_reservation_status', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	/**
	 * Olay yakalar.
	 *
	 * @param mixed ...$args Bağımsız değişkenler.
	 */
	public function capture( ...$args ): void {
		$this->events[] = $args;
	}

	private static function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	private static function h( string $s ): string {
		return md5( $s );
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
				'order_id'        => 501,
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
				'session_hash'    => self::h( 'sess-a' ),
				'ip_hash'         => self::h( 'ip-a' ),
			),
			$over
		);
	}

	/**
	 * İsteğin çocuk sürece giden JSON biçimi (tarihler dizgi).
	 *
	 * @param array<string,mixed> $req İstek.
	 * @return array<string,mixed>
	 */
	private static function wire( array $req ): array {
		$req['slots'] = array_map( static fn( \DateTimeImmutable $d ): string => $d->format( 'Y-m-d H:i:s' ), $req['slots'] );
		if ( $req['hold_expires_at'] instanceof \DateTimeImmutable ) {
			$req['hold_expires_at'] = $req['hold_expires_at']->format( 'Y-m-d H:i:s' );
		}
		return $req;
	}

	private function count_rows( string $short, string $where = '1=1' ): int {
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // autocommit=0 ise eski anlık görüntüyü bırak.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $short ) . " WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sabit test girdileri.
	}

	/**
	 * Siparişin durumları (başlangıca göre).
	 *
	 * @param int $order Sipariş.
	 * @return list<string>
	 */
	private function statuses( int $order ): array {
		$this->count_rows( 'reservations' );
		return array_map( static fn( $r ): string => $r->status, ReservationRepository::forOrder( $order ) );
	}

	/**
	 * Rezervasyonun birimleri.
	 *
	 * @param int $id Rezervasyon.
	 * @return list<string>
	 */
	private function units_of( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT unit_start FROM ' . Schema::table( 'occupancy' ) . ' WHERE reservation_id = %d ORDER BY unit_start', $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adı Schema'dan.
	}

	/**
	 * Başka rezervasyonun birimini COMMIT'li yazar.
	 *
	 * @param string $unit Birim.
	 */
	private function occupy( string $unit ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::table( 'occupancy' ),
			array(
				'resource_id'    => 1,
				'unit_start'     => $unit,
				'seat'           => 1,
				'reservation_id' => 9999,
			)
		);
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Üretimdeki gibi autocommit=1 altında çalıştırır.
	 *
	 * @param callable $work İş.
	 */
	private function with_autocommit( callable $work ): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT @@SESSION.autocommit' );
		$wpdb->query( 'SET autocommit = 1' );
		try {
			$work();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET autocommit = %d', $before ? 1 : 0 ) );
		}
	}

	/** Bağımsız ikinci bağlantı. */
	private function rival(): \mysqli {
		$rival = mysqli_init();
		$this->assertTrue( @mysqli_real_connect( $rival, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- test bağlantısı.
		return $rival;
	}

	/**
	 * Adlı kilidin tam adı (sunucu geneli ad alanı: tablo önekiyle).
	 *
	 * @param string $base slotiva_order_1 gibi.
	 */
	private static function lk( string $base ): string {
		return $GLOBALS['wpdb']->prefix . $base;
	}

	private function lock_free( string $name ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', $name ) );
	}

	public function test_claim_stores_snapshot_and_ownership_fields(): void {
		$ids = ReservationRepository::claim( $this->req() );
		$r   = ReservationRepository::find( $ids[0] );
		$this->assertSame( 'Danışmanlık 30 dk', $r?->productName );
		$this->assertSame( self::h( 'sess-a' ), $r?->sessionHash );
		$this->assertSame( self::h( 'ip-a' ), $r?->ipHash );

		$plain = ReservationRepository::claim(
			$this->req(
				array(
					'order_id'     => 502,
					'slots'        => array( self::utc( '2026-11-18 07:00:00' ) ),
					'session_hash' => null,
					'ip_hash'      => null,
					'product_name' => null,
				)
			)
		);
		$p     = ReservationRepository::find( $plain[0] );
		$this->assertNull( $p?->sessionHash );
		$this->assertNull( $p?->ipHash );
		$this->assertSame( '', $p?->productName );
	}

	public function test_invalid_hash_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		ReservationRepository::claim( $this->req( array( 'session_hash' => 'not-a-hash' ) ) );
	}

	public function test_claim_supersedes_other_orders_of_same_session(): void {
		$old = ReservationRepository::claim( $this->req() );
		// Adet değişti → WC yeni sipariş; aynı saat yeniden alınabilmeli (eski siparişin birimi aynı işlemde bırakılır).
		$this->events = array();
		$new          = ReservationRepository::claim( $this->req( array( 'order_id' => 502 ) ) );
		$this->assertSame( 'superseded', ReservationRepository::find( $old[0] )?->status );
		$this->assertSame( array(), $this->units_of( $old[0] ) );
		$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $new[0] ) );
		$this->assertSame( array( $old[0], 'held', 'superseded' ), array_slice( $this->events[0], 0, 3 ) );
		$this->assertFalse( $this->events[0][3]['notify'] );
		$this->assertSame( 'session', $this->events[0][3]['reason'] );
	}

	public function test_supersede_for_session_spares_confirmed_other_sessions_and_except_order(): void {
		// Önce onaylı (ödenmiş/yerinde) sipariş; sonraki oturum alımı onu supersede ETMEMELİ.
		$c  = ReservationRepository::claim(
			$this->req(
				array(
					'order_id' => 502,
					'status'   => 'confirmed',
					'slots'    => array( self::utc( '2026-11-18 07:00:00' ) ),
				)
			)
		);
		$a1 = ReservationRepository::claim( $this->req() );
		$this->assertSame( 'confirmed', ReservationRepository::find( $c[0] )?->status );
		$b = ReservationRepository::claim(
			$this->req(
				array(
					'order_id'     => 503,
					'session_hash' => self::h( 'sess-b' ),
					'slots'        => array( self::utc( '2026-11-19 07:00:00' ) ),
				)
			)
		);
		$this->assertSame( 0, ReservationRepository::supersedeForSession( self::h( 'sess-a' ), 501 ) );
		$this->assertSame( 'held', ReservationRepository::find( $a1[0] )?->status );
		$this->assertSame( 1, ReservationRepository::supersedeForSession( self::h( 'sess-a' ), 999 ) );
		$this->assertSame( 'superseded', ReservationRepository::find( $a1[0] )?->status );
		$this->assertSame( 'confirmed', ReservationRepository::find( $c[0] )?->status );
		$this->assertSame( 'held', ReservationRepository::find( $b[0] )?->status );
		$this->assertTrue( $this->lock_free( self::lk( 'slotiva_sess_' . self::h( 'sess-a' ) ) ) );
	}

	public function test_ip_quota_rejects_third_held_order_and_rolls_back(): void {
		$ids = array();
		foreach ( array(
			601 => 'sess-1',
			602 => 'sess-2',
		) as $order => $sess ) {
			$ids[ $order ] = ReservationRepository::claim(
				$this->req(
					array(
						'order_id'     => $order,
						'session_hash' => self::h( $sess ),
						'slots'        => array( self::utc( '2026-11-' . ( $order - 584 ) . ' 07:00:00' ) ),
					)
				)
			);
		}
		$this->events = array();
		try {
			ReservationRepository::claim(
				$this->req(
					array(
						'order_id'     => 603,
						'session_hash' => self::h( 'sess-3' ),
						'slots'        => array( self::utc( '2026-11-20 07:00:00' ) ),
					)
				)
			);
			$this->fail( 'HoldQuotaException bekleniyordu' );
		} catch ( HoldQuotaException $e ) {
			$this->assertSame( 'slotiva_hold_quota', $e->getMessage() );
			$this->assertSame( 'ip', $e->scope );
		}
		$this->assertSame( 0, $this->count_rows( 'reservations', 'order_id = 603' ) );
		$this->assertSame( 4, $this->count_rows( 'occupancy' ) );
		$this->assertSame( array(), $this->events );

		// Süresi geçmiş tutma (süpürücü henüz geçmemiş) sayılmaz.
		Clock::freeze( self::utc( '2026-11-10 13:00:00' ) );
		$ok = ReservationRepository::claim(
			$this->req(
				array(
					'order_id'        => 603,
					'session_hash'    => self::h( 'sess-3' ),
					'slots'           => array( self::utc( '2026-11-20 07:00:00' ) ),
					'hold_expires_at' => self::utc( '2026-11-10 13:30:00' ),
				)
			)
		);
		$this->assertCount( 1, $ok );
		// Kotalar yalnız `held` alımlar içindir (elle/yerinde onaylı alım sınırsız).
		$this->assertCount( 1, ReservationRepository::claim( $this->req( array( 'order_id' => 604, 'session_hash' => self::h( 'sess-4' ), 'status' => 'confirmed', 'slots' => array( self::utc( '2026-11-21 07:00:00' ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
	}

	public function test_expired_hold_whose_job_never_ran_does_not_block_new_claim(): void {
		$stale = ReservationRepository::claim( $this->req( array( 'order_id' => 801, 'session_hash' => self::h( 'sess-x' ), 'ip_hash' => self::h( 'ip-x' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		Clock::freeze( self::utc( '2026-11-10 13:00:00' ) ); // Tutma 12:45'te doldu; süpürücü çalışmadı.
		$this->events = array();
		$new          = ReservationRepository::claim( $this->req( array( 'order_id' => 802, 'hold_expires_at' => self::utc( '2026-11-10 13:45:00' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		$this->assertSame( 'expired', ReservationRepository::find( $stale[0] )?->status );
		$this->assertSame( array(), $this->units_of( $stale[0] ) );
		$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $new[0] ) );
		$expired = array_values( array_filter( $this->events, static fn( array $e ): bool => $e[0] === $stale[0] ) );
		$this->assertCount( 1, $expired );
		$this->assertSame( array( 'held', 'expired', 'lazy_expired' ), array( $expired[0][1], $expired[0][2], $expired[0][3]['reason'] ) );
	}

	public function test_live_hold_still_conflicts(): void {
		$live = ReservationRepository::claim( $this->req( array( 'order_id' => 801, 'session_hash' => self::h( 'sess-x' ), 'ip_hash' => self::h( 'ip-x' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		Clock::freeze( self::utc( '2026-11-10 12:30:00' ) );
		$this->events = array();
		try {
			ReservationRepository::claim( $this->req( array( 'order_id' => 802 ) ) );
			$this->fail( 'SlotTakenException bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			$this->assertSame( '2026-11-17 07:00:00', $e->taken[0]->format( 'Y-m-d H:i:s' ) );
		}
		$this->assertSame( 'held', ReservationRepository::find( $live[0] )?->status );
		$this->assertCount( 2, $this->units_of( $live[0] ) );
		$this->assertSame( array(), $this->events );
	}

	public function test_hold_quota_exempts_admin_claims_without_order(): void {
		foreach ( array(
			601 => 'sess-1',
			602 => 'sess-2',
		) as $order => $sess ) {
			ReservationRepository::claim( $this->req( array( 'order_id' => $order, 'session_hash' => self::h( $sess ), 'slots' => array( self::utc( '2026-11-' . ( $order - 584 ) . ' 07:00:00' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		}
		// Yönetici elle tutma: siparişsiz → kota yok (aynı IP özeti taşısa bile).
		$ids = ReservationRepository::claim(
			$this->req(
				array(
					'order_id'      => null,
					'order_item_id' => null,
					'session_hash'  => null,
					'slots'         => array( self::utc( '2026-11-20 07:00:00' ) ),
				)
			)
		);
		$this->assertCount( 1, $ids );
		$this->assertSame( 'held', ReservationRepository::find( $ids[0] )?->status );
		$this->assertSame( 3, $this->count_rows( 'reservations', "status = 'held'" ) );
	}

	public function test_exhausted_lock_wait_is_busy_not_slot_taken(): void {
		global $wpdb;
		// Rakip birimi COMMIT'siz tutar: iki deneme de 1205 → "karar verilemedi" (409), "saat dolu" DEĞİL.
		$rival = $this->rival();
		mysqli_query( $rival, 'SET autocommit = 0' );
		mysqli_query( $rival, 'INSERT INTO ' . Schema::table( 'occupancy' ) . " (resource_id, unit_start, seat, reservation_id) VALUES (1, '2026-11-17 07:00:00', 1, 9999)" );
		$timeout_before = (int) $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		try {
			ReservationRepository::claim( $this->req() );
			$this->fail( 'LockBusyException bekleniyordu' );
		} catch ( LockBusyException $e ) {
			$this->assertSame( 'slotiva_busy', $e->getMessage() );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', max( 1, $timeout_before ) ) );
			mysqli_query( $rival, 'ROLLBACK' );
			mysqli_close( $rival );
		}
		$this->assertSame( 0, $this->count_rows( 'reservations' ) );
		$this->assertSame( array(), $this->events );
	}

	public function test_order_lock_busy_is_typed_and_writes_nothing(): void {
		$rival = $this->rival();
		$name  = self::lk( 'slotiva_order_501' );
		mysqli_query( $rival, "SELECT GET_LOCK('{$name}', 0)" );
		$fast = static fn(): int => 1;
		add_filter( 'slotiva_lock_timeout', $fast );
		try {
			ReservationRepository::claim( $this->req() );
			$this->fail( 'LockBusyException bekleniyordu' );
		} catch ( LockBusyException $e ) {
			$this->assertSame( 'slotiva_busy', $e->getMessage() );
			$this->assertSame( $name, $e->lock );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $fast );
			mysqli_query( $rival, "SELECT RELEASE_LOCK('{$name}')" );
			mysqli_close( $rival );
		}
		$this->assertSame( 0, $this->count_rows( 'reservations' ) );
		$this->assertSame( array(), $this->events );
		// Önce alınan oturum kilidi bırakılmış olmalı.
		$this->assertTrue( $this->lock_free( self::lk( 'slotiva_sess_' . self::h( 'sess-a' ) ) ) );
	}

	public function test_session_lock_busy_is_typed(): void {
		$name  = self::lk( 'slotiva_sess_' . self::h( 'sess-a' ) );
		$rival = $this->rival();
		mysqli_query( $rival, "SELECT GET_LOCK('{$name}', 0)" );
		$fast = static fn(): int => 1;
		add_filter( 'slotiva_lock_timeout', $fast );
		try {
			ReservationRepository::replaceForOrder( 501, $this->req() );
			$this->fail( 'LockBusyException bekleniyordu' );
		} catch ( LockBusyException $e ) {
			$this->assertSame( $name, $e->lock );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $fast );
			mysqli_close( $rival );
		}
		$this->assertTrue( $this->lock_free( self::lk( 'slotiva_order_501' ) ) );
	}

	public function test_locks_released_after_conflict_and_success(): void {
		$this->occupy( '2026-11-17 07:15:00' );
		try {
			ReservationRepository::claim( $this->req() );
			$this->fail( 'SlotTakenException bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			unset( $e );
		}
		foreach ( array( self::lk( 'slotiva_order_501' ), self::lk( 'slotiva_sess_' . self::h( 'sess-a' ) ), self::lk( 'slotiva_ip_' . self::h( 'ip-a' ) ) ) as $name ) {
			$this->assertTrue( $this->lock_free( $name ), $name );
		}
		ReservationRepository::claim( $this->req( array( 'slots' => array( self::utc( '2026-11-18 07:00:00' ) ) ) ) );
		$this->assertTrue( $this->lock_free( self::lk( 'slotiva_order_501' ) ) );
	}

	public function test_transitions_noop_when_expectation_fails(): void {
		$ids          = ReservationRepository::claim( $this->req() );
		$id           = $ids[0];
		$this->events = array();
		$this->assertFalse( ReservationRepository::release( $id, 'expired', array( 'expect_sequence' => 3 ) ) );
		$this->assertFalse( ReservationRepository::release( $id, 'expired', array( 'expect_hold_expires_at' => self::utc( '2026-11-10 12:50:00' ) ) ) );
		$this->assertFalse( ReservationRepository::setStatus( $id, 'confirmed', 'paid', array( 'expect_status' => array( 'expired' ) ) ) );
		$this->assertSame( 'held', ReservationRepository::find( $id )?->status );
		$this->assertCount( 2, $this->units_of( $id ) );
		$this->assertSame( array(), $this->events );

		$this->assertTrue(
			ReservationRepository::release(
				$id,
				'expired',
				array(
					'expect_status'          => array( 'held' ),
					'expect_sequence'        => 0,
					'expect_hold_expires_at' => self::utc( '2026-11-10 12:45:00' ),
				)
			)
		);
		$this->assertSame( 'expired', ReservationRepository::find( $id )?->status );
		// Süre dolumu ikinci kez gelirse (bayat iş) hiçbir şey yapmaz.
		$this->assertFalse( ReservationRepository::release( $id, 'expired', array( 'expect_status' => array( 'held' ) ) ) );
		$this->assertCount( 1, $this->events );
	}

	public function test_reschedule_noop_on_stale_sequence(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'status' => 'confirmed' ) ) );
		$this->assertFalse( ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-17 09:00:00' ), true, false, 5 ) );
		$this->assertSame( '2026-11-17 07:00:00', ReservationRepository::find( $ids[0] )?->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertTrue( ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-17 09:00:00' ), true, false, 0 ) );
		$this->assertSame( 1, ReservationRepository::find( $ids[0] )?->sequence );
	}

	public function test_reclaim_is_order_wide_all_or_nothing(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'slots' => array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 09:00:00' ) ) ) ) );
		ReservationRepository::releaseForOrder( 501, 'expired' );
		ReservationRepository::setStatus( $ids[1], 'conflict' );
		$this->occupy( '2026-11-17 09:15:00' );
		$this->events = array();
		try {
			ReservationRepository::reclaim( $ids[0], array( 'payment_state' => 'paid' ) );
			$this->fail( 'SlotTakenException bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			$this->assertSame( array( '2026-11-17 09:00:00' ), array_map( static fn( $d ) => $d->format( 'Y-m-d H:i:s' ), $e->taken ) );
		}
		$this->assertSame( array( 'expired', 'conflict' ), $this->statuses( 501 ) );
		$this->assertSame( array(), $this->units_of( $ids[0] ) );
		$this->assertSame( 1, $this->count_rows( 'occupancy' ) );
		$this->assertSame( array(), $this->events );

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'occupancy' ) . ' WHERE reservation_id = 9999' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adı Schema'dan.
		$this->assertTrue( ReservationRepository::reclaim( $ids[1], array( 'payment_state' => 'paid' ) ) );
		$this->assertSame( array( 'confirmed', 'confirmed' ), $this->statuses( 501 ) );
		$this->assertCount( 2, $this->units_of( $ids[0] ) );
		$this->assertCount( 2, $this->units_of( $ids[1] ) );
		$this->assertCount( 2, $this->events );
		// İkinci (eşzamanlı/bayat) çağrı: geri alınacak satır yok → no-op.
		$this->assertFalse( ReservationRepository::reclaim( $ids[0] ) );
		$this->assertCount( 2, $this->events );
	}

	public function test_reclaim_rejects_superseded_cancelled_completed(): void {
		$ids = ReservationRepository::claim( $this->req() );
		ReservationRepository::release( $ids[0], 'superseded' );
		foreach ( array( 'superseded', 'cancelled' ) as $status ) {
			global $wpdb;
			$wpdb->update( Schema::table( 'reservations' ), array( 'status' => $status ), array( 'id' => $ids[0] ) );
			try {
				ReservationRepository::reclaim( $ids[0] );
				$this->fail( 'LogicException bekleniyordu: ' . $status );
			} catch ( \LogicException $e ) {
				$this->assertSame( 'slotiva_reservation_not_reclaimable', $e->getMessage() );
			}
		}
		$this->assertSame( 0, $this->count_rows( 'occupancy' ) );
	}

	public function test_failure_on_last_unit_write_leaves_nothing(): void {
		$old           = ReservationRepository::claim( $this->req() );
		$this->events  = array();
		$events_before = $this->count_rows( 'events' );
		$occ           = Schema::table( 'occupancy' );
		$seen          = 0;
		// Yeni seçim 2 dilim × 2 birim = 4 birim eklemesi; yalnız SONUNCUSU bozulur.
		$break = static function ( string $q ) use ( $occ, &$seen ): string {
			if ( str_starts_with( $q, "INSERT INTO `{$occ}`" ) && 4 === ++$seen ) {
				return 'INSERT INTO slotiva_no_such_table VALUES (1)';
			}
			return $q;
		};
		$this->with_autocommit(
			function () use ( $break ): void {
				add_filter( 'query', $break );
				try {
					ReservationRepository::replaceForOrder( 501, $this->req( array( 'slots' => array( self::utc( '2026-11-17 09:00:00' ), self::utc( '2026-11-17 10:00:00' ) ) ) ) );
					$this->fail( 'RuntimeException bekleniyordu' );
				} catch ( \RuntimeException $e ) {
					$this->assertStringStartsWith( 'slotiva_db_error', $e->getMessage() );
				} finally {
					remove_filter( 'query', $break );
				}
			}
		);
		$this->assertSame( 4, $seen );
		$this->assertSame( array( 'held' ), $this->statuses( 501 ) );
		$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $old[0] ) );
		$this->assertSame( 2, $this->count_rows( 'occupancy' ) );
		$this->assertSame( $events_before, $this->count_rows( 'events' ) );
		$this->assertSame( array(), $this->events );
		$this->assertTrue( $this->lock_free( self::lk( 'slotiva_order_501' ) ) );
	}

	public function test_lock_wait_retry_restarts_whole_attempt_including_supersede(): void {
		global $wpdb;
		$old   = ReservationRepository::claim( $this->req() );
		$other = ReservationRepository::claim( $this->req( array( 'order_id' => 502, 'session_hash' => self::h( 'sess-z' ), 'slots' => array( self::utc( '2026-11-19 07:00:00' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		unset( $other );
		$this->events  = array();
		$events_before = $this->count_rows( 'events' );

		// Rakip yeni birimi COMMIT'siz tutar → 1. deneme 1205 (1 sn). 2. denemenin START'ında rakip geri çekilir.
		$rival = $this->rival();
		mysqli_query( $rival, 'SET autocommit = 0' );
		mysqli_query( $rival, 'INSERT INTO ' . Schema::table( 'occupancy' ) . " (resource_id, unit_start, seat, reservation_id) VALUES (1, '2026-11-17 09:15:00', 1, 9999)" );
		$starts         = 0;
		$release        = static function ( string $q ) use ( $rival, &$starts ): string {
			if ( 'START TRANSACTION' === $q && 2 === ++$starts ) {
				mysqli_query( $rival, 'ROLLBACK' );
			}
			return $q;
		};
		$timeout_before = (int) $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		add_filter( 'query', $release );
		try {
			$new = ReservationRepository::replaceForOrder( 501, $this->req( array( 'slots' => array( self::utc( '2026-11-17 09:00:00' ) ) ) ) );
		} finally {
			remove_filter( 'query', $release );
			$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', max( 1, $timeout_before ) ) );
			mysqli_close( $rival );
		}
		$this->assertSame( 2, $starts );
		$this->assertSame( array( 'superseded', 'held' ), $this->statuses( 501 ) );
		$this->assertSame( array( '2026-11-17 09:00:00', '2026-11-17 09:15:00' ), $this->units_of( $new[0] ) );
		// Tam olarak tek denemenin izleri: eski satır 1 kez superseded, yeni satır 1 kez oluşturuldu.
		$this->assertSame( $events_before + 2, $this->count_rows( 'events' ) );
		$this->assertCount( 2, $this->events );
	}

	/**
	 * (a) Süre dolumu × onay, aynı satır: biri kazanır, öteki no-op. İki yönde.
	 */
	public function test_race_expiry_vs_confirmation(): void {
		$this->with_autocommit(
			function (): void {
				// Çocuk onaylar (kilidi tutar), ana süreç süre dolumunu dener → no-op.
				$a    = ReservationRepository::claim( $this->req() )[0];
				$race = Race::start( 'setStatus', array( $a, 'confirmed', 'paid', array( 'expect_status' => array( 'held' ) ) ) );
				$race->wait_ready();
				$t0           = microtime( true );
				$this->events = array();
				$expired      = ReservationRepository::release( $a, 'expired', array( 'expect_status' => array( 'held' ) ) );
				$waited       = microtime( true ) - $t0;
				$child        = $race->finish();
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertTrue( $child['result'] );
				$this->assertFalse( $expired );
				$this->assertGreaterThan( 0.8, $waited, 'ana süreç satır kilidinde beklemedi — yarış kurulmadı' );
				$this->assertSame( 'confirmed', ReservationRepository::find( $a )?->status );
				$this->assertCount( 2, $this->units_of( $a ) );
				$this->assertSame( array(), $this->events );

				// Ters yön: çocuk süre dolumunu yazar, ana süreç onayı dener → no-op.
				$b    = ReservationRepository::claim( $this->req( array( 'order_id' => 502, 'session_hash' => self::h( 'sess-b' ), 'slots' => array( self::utc( '2026-11-18 07:00:00' ) ) ) ) )[0]; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
				$race = Race::start( 'release', array( $b, 'expired', array( 'expect_status' => array( 'held' ) ) ) );
				$race->wait_ready();
				$this->events = array();
				$confirmed    = ReservationRepository::setStatus( $b, 'confirmed', 'paid', array( 'expect_status' => array( 'held' ) ) );
				$child        = $race->finish();
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertTrue( $child['result'] );
				$this->assertFalse( $confirmed );
				$this->assertSame( 'expired', ReservationRepository::find( $b )?->status );
				$this->assertSame( array(), $this->units_of( $b ) );
				$this->assertSame( array(), $this->events );
			}
		);
	}

	/**
	 * Çocuk adlı kilitleri ALMIŞ, henüz işlem başlatmamışken (satır kilidi yok) ana süreç: zaman aşımı 0 ile tipli
	 * meşgul hatası almalı (kilit gerçekten dışlıyor); ardından varsayılan zaman aşımıyla kilitte BEKLEMELİ.
	 * Adlı kilit kaldırılırsa ikisi de bozulur (rapor: Fix round 1, I2 RED).
	 *
	 * @param Race     $race   `start` noktasında bekleyen çocuk.
	 * @param string   $lock   Beklenen meşgul kilit adı.
	 * @param callable $mine Ana sürecin işlemi.
	 * @return array{0:float,1:mixed} [bekleme sn, sonuç ya da istisna]
	 */
	private function assert_named_lock_excludes( Race $race, string $lock, callable $mine ): array {
		$race->wait_ready();
		$zero = static fn(): int => 0;
		add_filter( 'slotiva_lock_timeout', $zero );
		try {
			$mine();
			$this->fail( 'LockBusyException bekleniyordu (adlı kilit dışlamadı)' );
		} catch ( LockBusyException $e ) {
			$this->assertSame( $lock, $e->lock );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $zero );
		}
		$t0 = microtime( true );
		try {
			$out = $mine();
		} catch ( \Throwable $e ) {
			$out = $e;
		}
		return array( microtime( true ) - $t0, $out );
	}

	/**
	 * (b1) Aynı oturum + IP, iki YEPYENİ sipariş eşzamanlı (hiç satır yokken FOR UPDATE serileştiremez; oturum kilidi
	 * serileştirir) → sonuncusu kazanır, oturumda tek etkin tutma.
	 */
	public function test_race_same_session_two_brand_new_orders(): void {
		$this->with_autocommit(
			function (): void {
				$race            = Race::start( 'claim', array( self::wire( $this->req( array( 'order_id' => 602 ) ) ) ), 1500, 'start' );
				[ $wait, $mine ] = $this->assert_named_lock_excludes( $race, self::lk( 'slotiva_sess_' . self::h( 'sess-a' ) ), fn() => ReservationRepository::claim( $this->req( array( 'order_id' => 603 ) ) ) );
				$child           = $race->finish();
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertIsArray( $mine, $mine instanceof \Throwable ? $mine->getMessage() : '' );
				$this->assertGreaterThan( 0.8, $wait, 'oturum kilidinde beklenmedi' );
				$this->assertSame( array( 'superseded' ), $this->statuses( 602 ) );
				$this->assertSame( array( 'held' ), $this->statuses( 603 ) );
				$this->assertSame( 1, $this->count_rows( 'reservations', "status = 'held'" ) );
				$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $mine[0] ) );
				$this->assertSame( 2, $this->count_rows( 'occupancy' ) );
			}
		);
	}

	/**
	 * (b2) Aynı oturum + IP, önceden tutulan sipariş varken iki farklı sipariş eşzamanlı.
	 */
	public function test_race_same_session_existing_hold_two_orders(): void {
		$this->with_autocommit(
			function (): void {
				ReservationRepository::claim( $this->req( array( 'order_id' => 601 ) ) );
				$race            = Race::start( 'claim', array( self::wire( $this->req( array( 'order_id' => 602, 'slots' => array( self::utc( '2026-11-17 08:00:00' ) ) ) ) ) ), 1500, 'start' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
				[ $wait, $mine ] = $this->assert_named_lock_excludes( $race, self::lk( 'slotiva_sess_' . self::h( 'sess-a' ) ), fn() => ReservationRepository::claim( $this->req( array( 'order_id' => 603, 'slots' => array( self::utc( '2026-11-17 09:00:00' ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
				$child           = $race->finish();
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertIsArray( $mine );
				$this->assertGreaterThan( 0.8, $wait );
				$this->assertSame( array( 'superseded' ), $this->statuses( 601 ) );
				$this->assertSame( array( 'superseded' ), $this->statuses( 602 ) );
				$this->assertSame( array( 'held' ), $this->statuses( 603 ) );
				$this->assertSame( 2, $this->count_rows( 'occupancy' ) );
			}
		);
	}

	/**
	 * (b3) Aynı IP, farklı oturumlar: sınırda eşzamanlı iki alma → IP kilidi serileştirir, biri kotaya takılır; IP'de asla
	 * 2'den fazla tutma.
	 */
	public function test_race_ip_quota_never_exceeded(): void {
		$this->with_autocommit(
			function (): void {
				ReservationRepository::claim(
					$this->req(
						array(
							'order_id'     => 611,
							'session_hash' => self::h( 's1' ),
						)
					)
				);
				$race            = Race::start( 'claim', array( self::wire( $this->req( array( 'order_id' => 612, 'session_hash' => self::h( 's2' ), 'slots' => array( self::utc( '2026-11-17 08:00:00' ) ) ) ) ) ), 1500, 'start' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
				[ $wait, $mine ] = $this->assert_named_lock_excludes( $race, self::lk( 'slotiva_ip_' . self::h( 'ip-a' ) ), fn() => ReservationRepository::claim( $this->req( array( 'order_id' => 613, 'session_hash' => self::h( 's3' ), 'slots' => array( self::utc( '2026-11-17 09:00:00' ) ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
				$child           = $race->finish();
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertInstanceOf( HoldQuotaException::class, $mine );
				$this->assertSame( 'ip', $mine->scope );
				$this->assertGreaterThan( 0.8, $wait );
				$this->assertSame( 2, $this->count_rows( 'reservations', "status = 'held'" ) );
				$this->assertSame( 0, $this->count_rows( 'reservations', 'order_id = 613' ) );
			}
		);
	}

	/**
	 * (c) Aynı sipariş, iki farklı dilim seti eşzamanlı (oturum/IP yok: yalnız SİPARİŞ kilidi) → tam olarak bir set etkin.
	 */
	public function test_race_same_order_two_slot_sets(): void {
		$this->with_autocommit(
			function (): void {
				$bare            = array(
					'order_id'     => 701,
					'session_hash' => null,
					'ip_hash'      => null,
				);
				$race            = Race::start( 'replaceForOrder', array( 701, self::wire( $this->req( $bare + array( 'slots' => array( self::utc( '2026-11-17 09:00:00' ), self::utc( '2026-11-17 11:00:00' ) ) ) ) ) ), 1500, 'start' );
				[ $wait, $mine ] = $this->assert_named_lock_excludes( $race, self::lk( 'slotiva_order_701' ), fn() => ReservationRepository::replaceForOrder( 701, $this->req( $bare + array( 'slots' => array( self::utc( '2026-11-17 10:00:00' ) ) ) ) ) );
				$child           = $race->finish();
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertIsArray( $mine );
				$this->assertGreaterThan( 0.8, $wait );
				$active = ReservationRepository::forOrder( 701, true );
				$this->assertCount( 1, $active );
				$this->assertSame( $mine[0], $active[0]->id );
				$this->assertSame( array( 'superseded', 'held', 'superseded' ), $this->statuses( 701 ) );
				$this->assertSame( 2, $this->count_rows( 'occupancy' ) );
				$this->assertSame( array( '2026-11-17 10:00:00', '2026-11-17 10:15:00' ), $this->units_of( $mine[0] ) );
			}
		);
	}

	/**
	 * (I1) İlgisiz iki müşteri (farklı oturum/IP/sipariş/dilim) eşzamanlı alır: çocuk okumalarını yapmış, ilk INSERT'ten
	 * hemen önce duruyor. K1 (Task 10): kaynak kilidi mağaza geneli olduğundan ana süreç çocuğun COMMIT'ini BEKLER (artık
	 * serileşir; eski "beklemez" sözleşmesi K1 ile çelişir), ama kilitlenme yok ve ikisi de başarılı.
	 */
	public function test_race_unrelated_customers_do_not_block_or_deadlock(): void {
		$this->with_autocommit(
			function (): void {
				// Tabloda başka satırlar da olsun (boşluklar gerçekçi).
				ReservationRepository::claim( $this->req( array( 'order_id' => 650, 'session_hash' => self::h( 's-old' ), 'ip_hash' => self::h( 'ip-old' ), 'slots' => array( self::utc( '2026-11-16 07:00:00' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
				foreach ( array( 'claim', 'replaceForOrder' ) as $op ) {
					$base  = 'claim' === $op ? 900 : 950;
					$child = $this->req( array( 'order_id' => $base + 1, 'session_hash' => self::h( "c-{$op}" ), 'ip_hash' => self::h( "ci-{$op}" ), 'slots' => array( self::utc( '2026-11-' . ( 'claim' === $op ? 18 : 20 ) . ' 07:00:00' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
					$mine  = $this->req( array( 'order_id' => $base + 2, 'session_hash' => self::h( "m-{$op}" ), 'ip_hash' => self::h( "mi-{$op}" ), 'slots' => array( self::utc( '2026-11-' . ( 'claim' === $op ? 19 : 21 ) . ' 07:00:00' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
					$args  = 'claim' === $op ? array( self::wire( $child ) ) : array( $base + 1, self::wire( $child ) );
					$race  = Race::start( $op, $args, 1500, 'insert' );
					$race->wait_ready();
					$t0  = microtime( true );
					$ids = 'claim' === $op ? ReservationRepository::claim( $mine ) : ReservationRepository::replaceForOrder( $base + 2, $mine );
					$dt  = microtime( true ) - $t0;
					$res = $race->finish();
					$this->assertTrue( $res['ok'], $op . ': ' . (string) wp_json_encode( $res ) );
					$this->assertCount( 1, $ids );
					$this->assertGreaterThan( 0.8, $dt, "{$op}: kaynak kilidi (K1) ilgisiz müşteriyi serileştirmeliydi" );
					$this->assertSame( array( 'held' ), $this->statuses( $base + 1 ) );
					$this->assertSame( array( 'held' ), $this->statuses( $base + 2 ) );
				}
			}
		);
	}

	/**
	 * Senaryo 11 (bağımsız bağlantı): 60 dakikalık ürün 07:00'ı alırken (çocuk, COMMIT'ten hemen önce bütün birim
	 * kilitlerini tutuyor) aynı takvimde 30 dakikalık ürün 07:30'u ister → ana süreç birim anahtarında bekler, çocuk
	 * COMMIT edince SlotTakenException; tek etkin rezervasyon, 07:30–08:00 birimleri yalnız 60 dakikalığın.
	 */
	public function test_race_mixed_durations_overlap_second_rejected(): void {
		$this->with_autocommit(
			function (): void {
				$long = $this->req(
					array(
						'product_id'   => 12,
						'product_name' => 'Birebir ders 60 dk',
						'order_id'     => 811,
						'duration'     => 60,
						'session_hash' => self::h( 's-long' ),
						'ip_hash'      => self::h( 'ip-long' ),
						'slots'        => array( self::utc( '2026-11-17 07:00:00' ) ),
					)
				);
				$race = Race::start( 'claim', array( self::wire( $long ) ), 1500, 'commit' );
				$race->wait_ready();
				$t0     = microtime( true );
				$thrown = null;
				try {
					ReservationRepository::claim(
						$this->req(
							array(
								'order_id'     => 812,
								'session_hash' => self::h( 's-short' ),
								'ip_hash'      => self::h( 'ip-short' ),
								'slots'        => array( self::utc( '2026-11-17 07:30:00' ) ),
							)
						)
					);
				} catch ( \Throwable $e ) {
					$thrown = $e;
				}
				$waited = microtime( true ) - $t0;
				$child  = $race->finish();
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertInstanceOf( SlotTakenException::class, $thrown );
				$this->assertGreaterThan( 0.8, $waited, 'ana süreç birim kilidinde beklemedi — yarış kurulmadı' );
				$this->assertSame( array( 'held' ), $this->statuses( 811 ) );
				$this->assertSame( 0, $this->count_rows( 'reservations', 'order_id = 812' ) );
				$this->assertSame( 4, $this->count_rows( 'occupancy' ), '60 dk = dört 15 dakikalık birim, 30 dk için hiç birim yok' );
			}
		);
	}

	/**
	 * Senaryo 13 + 20 (bağımsız bağlantı): yönetici 07:00 randevusunu 07:15'e kaydırır (kendi birimleriyle örtüşür) ve
	 * çocuk süreç COMMIT'ten hemen önce durur (eski birimler silinmiş, yenileri yazılmış, kilitler tutuluyor). Bu arada
	 * başka müşteri 07:30'u ister → bekler, sonra SlotTakenException. Kaydırma başarılı (yanlış 409 yok), randevu
	 * 07:15–07:45 birimlerinde, ikinci randevu yazılmadı.
	 */
	public function test_race_admin_reschedule_onto_own_units_blocks_intruder(): void {
		$this->with_autocommit(
			function (): void {
				$id = ReservationRepository::claim(
					$this->req(
						array(
							'order_id'     => 821,
							'status'       => 'confirmed',
							'session_hash' => self::h( 's-admin' ),
							'ip_hash'      => self::h( 'ip-admin' ),
						)
					)
				)[0];
				$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $id ) );

				$race = Race::start( 'reschedule', array( $id, array( '__dt' => '2026-11-17 07:15:00' ), false, false ), 1500, 'commit' );
				$race->wait_ready();
				$t0     = microtime( true );
				$thrown = null;
				try {
					ReservationRepository::claim(
						$this->req(
							array(
								'order_id'     => 822,
								'session_hash' => self::h( 's-intruder' ),
								'ip_hash'      => self::h( 'ip-intruder' ),
								'slots'        => array( self::utc( '2026-11-17 07:30:00' ) ),
							)
						)
					);
				} catch ( \Throwable $e ) {
					$thrown = $e;
				}
				$waited = microtime( true ) - $t0;
				$child  = $race->finish();
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertTrue( $child['result'], 'kendi birimleriyle örtüşen kaydırma başarılı (yanlış 409 yok)' );
				$this->assertInstanceOf( SlotTakenException::class, $thrown );
				$this->assertGreaterThan( 0.8, $waited, 'ana süreç birim kilidinde beklemedi — yarış kurulmadı' );
				$this->assertSame( array( '2026-11-17 07:15:00', '2026-11-17 07:30:00' ), $this->units_of( $id ) );
				$this->assertSame( '2026-11-17 07:15:00', ReservationRepository::find( $id )?->startsAt->format( 'Y-m-d H:i:s' ) );
				$this->assertSame( 0, $this->count_rows( 'reservations', 'order_id = 822' ) );
			}
		);
	}

	/**
	 * (Codex C1) 1205 sonrası ROLLBACK başarısız: işlem durumu bilinmiyor. İkinci deneme açılmaz (yeni START TRANSACTION
	 * yarım kalan ilk denemeyi örtük COMMIT ederdi); bağlantı atılır (sunucu açık işlemi geri alır), LockBusyException.
	 */
	public function test_failed_rollback_after_lock_wait_aborts_without_retry(): void {
		global $wpdb;
		$old           = ReservationRepository::claim( $this->req() );
		$this->events  = array();
		$events_before = $this->count_rows( 'events' );

		// Rakip yeni dilimin ilk birimini COMMIT'siz tutar → INSERT kilit bekler → 1205 (eski satır o anda supersede edilmiş).
		$rival = $this->rival();
		mysqli_query( $rival, 'SET autocommit = 0' );
		mysqli_query( $rival, 'INSERT INTO ' . Schema::table( 'occupancy' ) . " (resource_id, unit_start, seat, reservation_id) VALUES (1, '2026-11-17 09:00:00', 1, 9999)" );
		$starts    = 0;
		$rollbacks = 0;
		$fault     = static function ( string $q ) use ( &$starts, &$rollbacks ): string {
			if ( 'START TRANSACTION' === $q ) {
				++$starts;
			}
			if ( 'ROLLBACK' === $q && 1 === ++$rollbacks ) {
				return 'ROLLBACK_INJECTED_FAILURE'; // Sözdizimi hatası → query() false.
			}
			return $q;
		};
		$thrown    = null;
		$this->with_autocommit(
			function () use ( $wpdb, $fault, $rival, &$thrown ): void {
				$timeout_before = (int) $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
				$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
				add_filter( 'query', $fault );
				try {
					ReservationRepository::replaceForOrder( 501, $this->req( array( 'slots' => array( self::utc( '2026-11-17 09:00:00' ) ) ) ) );
				} catch ( \Throwable $e ) {
					$thrown = $e;
				} finally {
					remove_filter( 'query', $fault );
					$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', max( 1, $timeout_before ) ) );
					mysqli_query( $rival, 'ROLLBACK' );
					mysqli_close( $rival );
				}
			}
		);
		$this->assertSame( array( 'held' ), $this->statuses( 501 ), 'eski tutma bozulmadı, yarım deneme COMMIT edilmedi' );
		$this->assertSame( 1, $starts, 'durumu bilinmeyen bağlantıda ikinci deneme açılmamalı' );
		$this->assertInstanceOf( LockBusyException::class, $thrown );
		$this->assertSame( 'slotiva_rollback_failed', $thrown->lock );
		$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $old[0] ) );
		$this->assertSame( $events_before, $this->count_rows( 'events' ) );
		$this->assertSame( array(), $this->events );
		$this->assertTrue( $this->lock_free( self::lk( 'slotiva_order_501' ) ) );
	}

	/**
	 * (Codex C2) Tutma süresi yenilemesi sipariş kilidi altında: kilidi başka bağlantı tutarken çıplak UPDATE yapılmaz.
	 */
	public function test_refresh_hold_respects_order_lock(): void {
		$ids   = ReservationRepository::claim( $this->req() );
		$rival = $this->rival();
		$name  = self::lk( 'slotiva_order_501' );
		mysqli_query( $rival, "SELECT GET_LOCK('{$name}', 0)" );
		add_filter( 'slotiva_lock_timeout', '__return_zero' );
		try {
			ReservationRepository::refreshHold( 501, self::utc( '2026-11-10 13:30:00' ) );
			$this->fail( 'LockBusyException bekleniyordu' );
		} catch ( LockBusyException $e ) {
			$this->assertSame( $name, $e->lock );
		} finally {
			remove_filter( 'slotiva_lock_timeout', '__return_zero' );
			mysqli_query( $rival, 'DO RELEASE_ALL_LOCKS()' );
			mysqli_close( $rival );
		}
		$this->assertSame( '2026-11-10 12:45:00', ReservationRepository::find( $ids[0] )?->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 1, ReservationRepository::refreshHold( 501, self::utc( '2026-11-10 13:30:00' ) ) );
		$this->assertSame( '2026-11-10 13:30:00', ReservationRepository::find( $ids[0] )?->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
	}
}
