<?php
/**
 * Rezervasyon deposu testleri: atomik alma, bırakma, yeniden zamanlama, sorgu ve olaylar.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Reservation;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Reservation\EventLog;
use MHM\Slotiva\Reservation\OrderCancelledException;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test yardımcıları özel tabloları doğrudan okur/yazar.

class ReservationRepositoryTest extends SlotivaTestCase {

	/**
	 * Yakalanan olaylar.
	 *
	 * @var list<array<int,mixed>>
	 */
	private array $status_events = array();

	/**
	 * Yakalanan yeniden zamanlama olayları.
	 *
	 * @var list<array<int,mixed>>
	 */
	private array $resched_events = array();

	public function set_up(): void {
		parent::set_up();
		$this->status_events  = array();
		$this->resched_events = array();
		add_action( 'slotiva_reservation_status', array( $this, 'capture_status' ), 10, 4 );
		add_action( 'slotiva_reservation_rescheduled', array( $this, 'capture_resched' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_action( 'slotiva_reservation_status', array( $this, 'capture_status' ), 10 );
		remove_action( 'slotiva_reservation_rescheduled', array( $this, 'capture_resched' ), 10 );
		parent::tear_down();
	}

	/**
	 * Durum olayını yakalar.
	 *
	 * @param mixed ...$args Olay bağımsız değişkenleri.
	 */
	public function capture_status( ...$args ): void {
		$this->status_events[] = $args;
	}

	/**
	 * Yeniden zamanlama olayını yakalar.
	 *
	 * @param mixed ...$args Olay bağımsız değişkenleri.
	 */
	public function capture_resched( ...$args ): void {
		$this->resched_events[] = $args;
	}

	private static function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Geçerli bir claim isteği.
	 *
	 * @param array<string,mixed> $over Ezmeler.
	 * @return array<string,mixed>
	 */
	private function req( array $over = array() ): array {
		return array_merge(
			array(
				'product_id'      => 11,
				'variation_id'    => 0,
				'order_id'        => 501,
				'order_item_id'   => 7,
				'slots'           => array( self::utc( '2026-11-17 07:00:00' ) ),
				'duration'        => 30,
				'buffer'          => 0,
				'status'          => 'held',
				'payment_state'   => 'pending',
				'hold_expires_at' => self::utc( '2026-11-10 12:45:00' ),
				'customer'        => array(
					'name'  => 'Ayşe Test',
					'email' => 'ayse@example.test',
					'phone' => '5550000000',
				),
				'location'        => 'Atölye',
			),
			$over
		);
	}

	private function count_rows( string $short, string $where = '1=1' ): int {
		global $wpdb;
		$table = Schema::table( $short );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test yardımcısı, sabit girdiler.
	}

	/**
	 * Rezervasyonun birimleri, artan.
	 *
	 * @param int $id Rezervasyon.
	 * @return list<string>
	 */
	private function units_of( int $id ): array {
		global $wpdb;
		$table = Schema::table( 'occupancy' );
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT unit_start FROM {$table} WHERE reservation_id = %d ORDER BY unit_start", $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test yardımcısı.
	}

	/**
	 * Başka bir rezervasyona ait birimi doğrudan yazar (rakip müşteri).
	 *
	 * @param string $unit           Birim (Y-m-d H:i:s UTC).
	 * @param int    $reservation_id Sahibi.
	 */
	private function occupy( string $unit, int $reservation_id = 9999 ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( 'occupancy' ),
			array(
				'resource_id'    => 1,
				'unit_start'     => $unit,
				'seat'           => 1,
				'reservation_id' => $reservation_id,
			)
		);
		$wpdb->query( 'COMMIT' );
	}

	public function test_claim_writes_units_and_rows(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'buffer' => 15 ) ) );
		$this->assertCount( 1, $ids );
		$r = ReservationRepository::find( $ids[0] );
		$this->assertNotNull( $r );
		$this->assertSame( 'held', $r->status );
		$this->assertSame( 'pending', $r->paymentState );
		$this->assertSame( 501, $r->orderId );
		$this->assertSame( 7, $r->orderItemId );
		$this->assertSame( 11, $r->productId );
		$this->assertSame( '2026-11-17 07:00:00', $r->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-11-17 07:30:00', $r->endsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-11-10 12:45:00', $r->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'Ayşe Test', $r->customerName );
		$this->assertSame( 'ayse@example.test', $r->customerEmail );
		$this->assertSame( 'Atölye', $r->locationText );
		$this->assertSame( 0, $r->sequence );
		$this->assertSame( 0, $r->rescheduleCount );
		// 30 dk + 15 dk ara = 3 birim, artan.
		$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00', '2026-11-17 07:30:00' ), $this->units_of( $ids[0] ) );
	}

	public function test_claim_conflict_throws_and_rolls_back(): void {
		$this->occupy( '2026-11-17 07:15:00' );
		$this->with_autocommit(
			function (): void {
				try {
					ReservationRepository::claim( $this->req() );
					$this->fail( 'SlotTakenException bekleniyordu' );
				} catch ( SlotTakenException $e ) {
					$this->assertCount( 1, $e->taken );
					$this->assertSame( '2026-11-17 07:00:00', $e->taken[0]->format( 'Y-m-d H:i:s' ) );
				}
			}
		);
		$this->assertSame( 0, $this->count_rows( 'reservations' ) );
		$this->assertSame( 0, $this->count_rows( 'occupancy', 'reservation_id <> 9999' ) );
		$this->assertSame( 1, $this->count_rows( 'occupancy' ) );
		$this->assertSame( 0, $this->count_rows( 'events' ) );
		$this->assertSame( array(), $this->status_events );
	}

	public function test_claim_multi_slot_all_or_nothing(): void {
		$this->occupy( '2026-11-17 09:15:00' );
		$slots = array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 08:00:00' ), self::utc( '2026-11-17 09:00:00' ) );
		try {
			ReservationRepository::claim( $this->req( array( 'slots' => $slots ) ) );
			$this->fail( 'SlotTakenException bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			$this->assertSame( array( '2026-11-17 09:00:00' ), array_map( static fn( $d ) => $d->format( 'Y-m-d H:i:s' ), $e->taken ) );
		}
		$this->assertSame( 0, $this->count_rows( 'reservations' ) );
		$this->assertSame( 1, $this->count_rows( 'occupancy' ) );
		$this->assertSame( array(), $this->status_events );

		// Aynı üç dilim, çakışma yokken hepsi yazılır.
		$this->setUp_clean_occupancy();
		$ids = ReservationRepository::claim( $this->req( array( 'slots' => $slots ) ) );
		$this->assertCount( 3, $ids );
		$this->assertSame( 6, $this->count_rows( 'occupancy' ) );
	}

	private function setUp_clean_occupancy(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'occupancy' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test yardımcısı.
		$wpdb->query( 'COMMIT' );
	}

	public function test_release_frees_units(): void {
		$ids = ReservationRepository::claim( $this->req() );
		ReservationRepository::release( $ids[0], 'cancelled', array( 'reason' => 'admin' ) );
		$r = ReservationRepository::find( $ids[0] );
		$this->assertSame( 'cancelled', $r?->status );
		$this->assertNull( $r?->holdExpiresAt );
		$this->assertSame( array(), $this->units_of( $ids[0] ) );
		// Boşalan dilim yeniden alınabilir.
		$again = ReservationRepository::claim( $this->req( array( 'order_id' => 502 ) ) );
		$this->assertCount( 1, $again );
	}

	public function test_reschedule_overlapping_own_units_succeeds(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'status' => 'confirmed' ) ) );
		ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-17 07:15:00' ), true, true );
		$r = ReservationRepository::find( $ids[0] );
		$this->assertSame( '2026-11-17 07:15:00', $r?->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-11-17 07:45:00', $r?->endsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( array( '2026-11-17 07:15:00', '2026-11-17 07:30:00' ), $this->units_of( $ids[0] ) );
	}

	public function test_reschedule_conflict_keeps_old(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'status' => 'confirmed' ) ) );
		$this->occupy( '2026-11-17 08:15:00' );
		$this->resched_events = array();
		try {
			ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-17 08:00:00' ), true, true );
			$this->fail( 'SlotTakenException bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			$this->assertSame( '2026-11-17 08:00:00', $e->taken[0]->format( 'Y-m-d H:i:s' ) );
		}
		$r = ReservationRepository::find( $ids[0] );
		$this->assertSame( '2026-11-17 07:00:00', $r?->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 0, $r?->sequence );
		$this->assertSame( 0, $r?->rescheduleCount );
		$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $ids[0] ) );
		$this->assertSame( array(), $this->resched_events );
	}

	public function test_reschedule_increments_sequence_and_count(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'status' => 'confirmed' ) ) );
		ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-18 07:00:00' ), true, false );
		ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-19 07:00:00' ), false, false );
		$r = ReservationRepository::find( $ids[0] );
		$this->assertSame( 2, $r?->sequence );
		$this->assertSame( 1, $r?->rescheduleCount );
	}

	public function test_token_is_32_hex(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'slots' => array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 08:00:00' ) ) ) ) );
		$a   = ReservationRepository::find( $ids[0] );
		$b   = ReservationRepository::find( $ids[1] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $a?->token );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $b?->token );
		$this->assertNotSame( $a?->token, $b?->token );
	}

	public function test_query_tabs(): void {
		$tz_before = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Europe/Istanbul' );
		try {
			$this->run_query_tabs();
		} finally {
			update_option( 'timezone_string', $tz_before );
		}
	}

	private function run_query_tabs(): void {
		Clock::freeze( self::utc( '2026-11-17 09:00:00' ) ); // 12:00 yerel.
		$mk = function ( string $start, string $status = 'confirmed' ): int {
			$ids = ReservationRepository::claim(
				$this->req(
					array(
						'slots'    => array( self::utc( $start ) ),
						'status'   => 'confirmed',
						'order_id' => null,
					)
				)
			);
			if ( 'confirmed' !== $status ) {
				ReservationRepository::release( $ids[0], $status );
			}
			return $ids[0];
		};
		$mk( '2026-11-16 21:30:00' ); // 00:30 yerel bugün (şimdiden önce → bugün + geçmiş).
		$mk( '2026-11-17 15:00:00' ); // 18:00 yerel bugün (şimdiden sonra → bugün + yaklaşan).
		$mk( '2026-11-17 21:00:00' ); // 00:00 yerel yarın → yaklaşan.
		$mk( '2026-11-20 07:00:00' );
		$mk( '2026-11-25 07:00:00' );
		$mk( '2026-11-10 07:00:00' ); // geçmiş.
		$mk( '2026-11-21 07:00:00', 'cancelled' );
		$mk( '2026-11-22 07:00:00', 'expired' );
		$mk( '2026-11-23 07:00:00', 'superseded' ); // hiçbir sekmede görünmez.

		$this->assertSame( 2, ReservationRepository::query( array( 'tab' => 'today' ) )['total'] );
		$this->assertSame( 4, ReservationRepository::query( array( 'tab' => 'upcoming' ) )['total'] );
		$this->assertSame( 2, ReservationRepository::query( array( 'tab' => 'past' ) )['total'] );
		$this->assertSame( 2, ReservationRepository::query( array( 'tab' => 'cancelled' ) )['total'] );

		$page = ReservationRepository::query(
			array(
				'tab'      => 'upcoming',
				'page'     => 2,
				'per_page' => 2,
			)
		);
		$this->assertSame( 4, $page['total'] );
		$this->assertCount( 2, $page['items'] );
		$this->assertSame( '2026-11-20 07:00:00', $page['items'][0]->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( '2026-11-25 07:00:00', $page['items'][1]->startsAt->format( 'Y-m-d H:i:s' ) );
	}

	public function test_event_log_roundtrip(): void {
		EventLog::add( 42, 'note', array( 'text' => 'Merhaba' ) );
		EventLog::add( 42, 'status', array( 'from' => 'held' ) );
		EventLog::add( 43, 'note' );
		$rows = EventLog::for( 42 );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'note', $rows[0]['type'] );
		$this->assertSame( array( 'text' => 'Merhaba' ), $rows[0]['data'] );
		$this->assertSame( 42, $rows[0]['reservation_id'] );
		$this->assertSame( 'status', $rows[1]['type'] );
		$this->assertSame( 'system', $rows[0]['actor'] );
		$this->assertArrayHasKey( 'created_at', $rows[0] );
		$this->assertCount( 1, EventLog::for( 43 ) );
	}

	public function test_claim_fires_status_event_with_empty_old(): void {
		$ids = ReservationRepository::claim( $this->req() );
		$this->assertCount( 1, $this->status_events );
		[ $id, $old, $new, $ctx ] = $this->status_events[0];
		$this->assertSame( $ids[0], $id );
		$this->assertSame( '', $old );
		$this->assertSame( 'held', $new );
		$this->assertTrue( $ctx['notify'] );
		$this->assertSame( 501, $ctx['order_id'] );
		$this->assertIsString( $ctx['reason'] );
		$this->assertNotEmpty( EventLog::for( $ids[0] ) );
	}

	public function test_reschedule_fires_rescheduled_event(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'status' => 'confirmed' ) ) );
		ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-18 07:00:00' ), true, false );
		$this->assertCount( 1, $this->resched_events );
		[ $id, $old_start, $notify ] = $this->resched_events[0];
		$this->assertSame( $ids[0], $id );
		$this->assertInstanceOf( \DateTimeImmutable::class, $old_start );
		$this->assertSame( '2026-11-17 07:00:00', $old_start->format( 'Y-m-d H:i:s' ) );
		$this->assertFalse( $notify );
	}

	public function test_release_skips_terminal_rows(): void {
		$slots = array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 08:00:00' ), self::utc( '2026-11-17 09:00:00' ) );
		$ids   = ReservationRepository::claim( $this->req( array( 'slots' => $slots ) ) );
		ReservationRepository::release( $ids[0], 'expired' );
		ReservationRepository::setStatus( $ids[1], 'confirmed', 'paid' );
		$this->status_events = array();

		$n = ReservationRepository::releaseForOrder( 501, 'cancelled' );
		$this->assertSame( 2, $n );
		$this->assertSame( 'expired', ReservationRepository::find( $ids[0] )?->status );
		$this->assertSame( 'cancelled', ReservationRepository::find( $ids[1] )?->status );
		$this->assertSame( 'cancelled', ReservationRepository::find( $ids[2] )?->status );
		$this->assertSame( 0, $this->count_rows( 'occupancy' ) );
		$this->assertCount( 2, $this->status_events );
		$this->assertSame( 501, $this->status_events[0][3]['order_id'] );

		// İkinci çağrı hiçbir şeye dokunmaz.
		$this->status_events = array();
		$this->assertSame( 0, ReservationRepository::releaseForOrder( 501, 'cancelled' ) );
		$this->assertSame( array(), $this->status_events );
	}

	public function test_for_order_active_only(): void {
		$slots = array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 08:00:00' ), self::utc( '2026-11-17 09:00:00' ) );
		$ids   = ReservationRepository::claim( $this->req( array( 'slots' => $slots ) ) );
		ReservationRepository::setStatus( $ids[1], 'confirmed', 'paid' );
		ReservationRepository::release( $ids[2], 'expired' );
		ReservationRepository::claim(
			$this->req(
				array(
					'order_id' => 777,
					'slots'    => array( self::utc( '2026-11-18 07:00:00' ) ),
				)
			)
		);

		$all = ReservationRepository::forOrder( 501 );
		$this->assertSame( $ids, array_map( static fn( $r ) => $r->id, $all ) );
		$active = ReservationRepository::forOrder( 501, true );
		$this->assertSame( array( $ids[0], $ids[1] ), array_map( static fn( $r ) => $r->id, $active ) );
	}

	public function test_replace_for_order_supersedes_old_and_claims_new(): void {
		$old = ReservationRepository::claim( $this->req( array( 'slots' => array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 08:00:00' ) ) ) ) );
		ReservationRepository::release( $old[1], 'expired' );
		$this->status_events = array();

		// Yeni seçim eskisiyle örtüşür (07:15): eski birimler aynı işlemde silindiği için çakışma olmaz.
		$new = ReservationRepository::replaceForOrder( 501, $this->req( array( 'slots' => array( self::utc( '2026-11-17 07:15:00' ) ) ) ) );
		$this->assertCount( 1, $new );
		$this->assertSame( 'superseded', ReservationRepository::find( $old[0] )?->status );
		$this->assertSame( 'superseded', ReservationRepository::find( $old[1] )?->status );
		$this->assertSame( array(), $this->units_of( $old[0] ) );
		$this->assertSame( array( '2026-11-17 07:15:00', '2026-11-17 07:30:00' ), $this->units_of( $new[0] ) );
		$this->assertSame( 2, $this->count_rows( 'occupancy' ) );
		$this->assertCount( 1, ReservationRepository::forOrder( 501, true ) );

		$news = array_map( static fn( $e ) => $e[2], $this->status_events );
		$this->assertSame( array( 'superseded', 'superseded', 'held' ), $news );
		$this->assertSame( 'expired', $this->status_events[1][1] );
	}

	public function test_replace_conflict_keeps_old_active(): void {
		$old = ReservationRepository::claim( $this->req() );
		$this->occupy( '2026-11-17 10:15:00' );
		$this->status_events = array();
		$this->with_autocommit(
			function (): void {
				try {
					ReservationRepository::replaceForOrder( 501, $this->req( array( 'slots' => array( self::utc( '2026-11-17 10:00:00' ) ) ) ) );
					$this->fail( 'SlotTakenException bekleniyordu' );
				} catch ( SlotTakenException $e ) {
					$this->assertSame( '2026-11-17 10:00:00', $e->taken[0]->format( 'Y-m-d H:i:s' ) );
				}
			}
		);
		$r = ReservationRepository::find( $old[0] );
		$this->assertSame( 'held', $r?->status );
		$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $old[0] ) );
		$this->assertSame( 1, $this->count_rows( 'reservations' ) );
		$this->assertSame( 3, $this->count_rows( 'occupancy' ) );
		$this->assertSame( array(), $this->status_events );
	}

	public function test_suppress_errors_state_restored_after_conflict(): void {
		global $wpdb;
		$before = $wpdb->suppress_errors( false );
		$this->occupy( '2026-11-17 07:00:00' );
		try {
			ReservationRepository::claim( $this->req() );
		} catch ( SlotTakenException $e ) {
			unset( $e );
		}
		$this->assertFalse( $wpdb->suppress_errors( $before ) );
	}

	/**
	 * Üretimdeki gibi autocommit=1 altında çalıştırır: açık START TRANSACTION olmasaydı ROLLBACK hiçbir şeyi geri almazdı.
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

	public function test_set_status_cannot_reactivate_without_units(): void {
		$ids = ReservationRepository::claim( $this->req() );
		ReservationRepository::release( $ids[0], 'expired' );
		foreach ( array( 'held', 'confirmed' ) as $active ) {
			try {
				ReservationRepository::setStatus( $ids[0], $active, 'paid' );
				$this->fail( 'LogicException bekleniyordu' );
			} catch ( \LogicException $e ) {
				$this->assertSame( 'slotiva_reactivate_requires_claim', $e->getMessage() );
			}
		}
		foreach ( array( 'held', 'confirmed' ) as $active ) {
			try {
				ReservationRepository::release( $ids[0], $active );
				$this->fail( 'InvalidArgumentException bekleniyordu' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'slotiva_release_to_active_status', $e->getMessage() );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		try {
			ReservationRepository::releaseForOrder( 501, 'held' );
		} finally {
			$r = ReservationRepository::find( $ids[0] );
			$this->assertSame( 'expired', $r?->status );
			$this->assertSame( 'pending', $r?->paymentState );
			$this->assertSame( 0, $this->count_rows( 'occupancy' ) );
		}
	}

	public function test_reclaim_succeeds_when_free(): void {
		$ids = ReservationRepository::claim( $this->req() );
		ReservationRepository::release( $ids[0], 'expired' );
		$this->status_events = array();
		ReservationRepository::reclaim(
			$ids[0],
			array(
				'payment_state' => 'paid',
				'reason'        => 'late_payment',
			)
		);
		$r = ReservationRepository::find( $ids[0] );
		$this->assertSame( 'confirmed', $r?->status );
		$this->assertSame( 'paid', $r?->paymentState );
		$this->assertNull( $r?->holdExpiresAt );
		$this->assertSame( array( '2026-11-17 07:00:00', '2026-11-17 07:15:00' ), $this->units_of( $ids[0] ) );
		$this->assertCount( 1, $this->status_events );
		$this->assertSame( array( 'expired', 'confirmed' ), array( $this->status_events[0][1], $this->status_events[0][2] ) );
		$this->assertSame( 'late_payment', $this->status_events[0][3]['reason'] );
	}

	public function test_reclaim_conflict_keeps_expired(): void {
		$ids = ReservationRepository::claim( $this->req() );
		ReservationRepository::release( $ids[0], 'expired' );
		$this->occupy( '2026-11-17 07:15:00' );
		$this->status_events = array();
		try {
			ReservationRepository::reclaim( $ids[0] );
			$this->fail( 'SlotTakenException bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			$this->assertSame( array( '2026-11-17 07:00:00' ), array_map( static fn( $d ) => $d->format( 'Y-m-d H:i:s' ), $e->taken ) );
		}
		$this->assertSame( 'expired', ReservationRepository::find( $ids[0] )?->status );
		$this->assertSame( array(), $this->units_of( $ids[0] ) );
		$this->assertSame( 1, $this->count_rows( 'occupancy' ) );
		$this->assertSame( array(), $this->status_events );
	}

	public function test_start_transaction_failure_writes_nothing(): void {
		$break = static fn( string $q ): string => 'START TRANSACTION' === $q ? 'START TRANSACTION_SLOTIVA_BROKEN' : $q;
		add_filter( 'query', $break );
		try {
			ReservationRepository::claim( $this->req() );
			$this->fail( 'RuntimeException bekleniyordu' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'slotiva_transaction_start_failed', $e->getMessage() );
		} finally {
			remove_filter( 'query', $break );
		}
		$this->assertSame( 0, $this->count_rows( 'reservations' ) );
		$this->assertSame( 0, $this->count_rows( 'occupancy' ) );
		$this->assertSame( array(), $this->status_events );
		// Depo zehirlenmedi: sonraki claim çalışır.
		$this->assertCount( 1, ReservationRepository::claim( $this->req() ) );
	}

	/**
	 * COMMIT sonucu doğrulanır (spec §15.5, Codex plan eki "yazım/COMMIT hatası"): COMMIT başarısızsa işlem geri alınır,
	 * hiçbir satır ve olay kalmaz, olay kancası çalışmaz; depo zehirlenmez.
	 */
	public function test_commit_failure_rolls_back_and_writes_nothing(): void {
		$break = static fn( string $q ): string => 'COMMIT' === $q ? 'COMMIT_SLOTIVA_BROKEN' : $q;
		$this->with_autocommit(
			function () use ( $break ): void {
				add_filter( 'query', $break );
				try {
					ReservationRepository::claim( $this->req() );
					$this->fail( 'RuntimeException bekleniyordu' );
				} catch ( \RuntimeException $e ) {
					$this->assertSame( 'slotiva_commit_failed', $e->getMessage() );
				} finally {
					remove_filter( 'query', $break );
				}
			}
		);
		$this->assertSame( 0, $this->count_rows( 'reservations' ) );
		$this->assertSame( 0, $this->count_rows( 'occupancy' ) );
		$this->assertSame( 0, $this->count_rows( 'events' ) );
		$this->assertSame( array(), $this->status_events );
		$this->assertCount( 1, ReservationRepository::claim( $this->req() ) );
	}

	public function test_failing_write_inside_transaction_rolls_back_everything(): void {
		$events = Schema::table( 'events' );
		$break  = static fn( string $q ): string => str_starts_with( $q, "INSERT INTO `{$events}`" ) ? 'INSERT INTO slotiva_no_such_table VALUES (1)' : $q;
		$this->with_autocommit(
			function () use ( $break ): void {
				add_filter( 'query', $break );
				try {
					ReservationRepository::claim( $this->req() );
					$this->fail( 'RuntimeException bekleniyordu' );
				} catch ( \RuntimeException $e ) {
					$this->assertStringStartsWith( 'slotiva_db_error', $e->getMessage() );
				} finally {
					remove_filter( 'query', $break );
				}
			}
		);
		$this->assertSame( 0, $this->count_rows( 'reservations' ) );
		$this->assertSame( 0, $this->count_rows( 'occupancy' ) );
		$this->assertSame( 0, $this->count_rows( 'events' ) );
		$this->assertSame( array(), $this->status_events );
		$this->assertCount( 1, ReservationRepository::claim( $this->req() ) );
	}

	public function test_error_kind_mapping(): void {
		$this->assertSame( 'retry', ReservationRepository::errorKind( 1213, true ) );
		$this->assertSame( 'retry', ReservationRepository::errorKind( 1213, false ) );
		$this->assertSame( 'retry', ReservationRepository::errorKind( 1205, true ) );
		$this->assertSame( 'retry', ReservationRepository::errorKind( 1205, false ) );
		$this->assertSame( 'conflict', ReservationRepository::errorKind( 1062, true ) );
		$this->assertSame( 'error', ReservationRepository::errorKind( 1062, false ) );
		$this->assertSame( 'error', ReservationRepository::errorKind( 1146, true ) );
		$this->assertSame( 'error', ReservationRepository::errorKind( 0, true ) );
	}

	public function test_lock_wait_timeout_retries_once_then_busy(): void {
		global $wpdb;
		// Rakip, ayrı bir bağlantıda aynı birimi COMMIT etmeden tutar → bizim INSERT kilit bekler → 1205.
		$rival = mysqli_init(); // phpcs:ignore WordPress.DB.RestrictedFunctions -- ikinci bağlantı yalnız test için.
		$this->assertTrue( @mysqli_real_connect( $rival, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions, WordPress.PHP.NoSilencedErrors -- ikinci bağlantı yalnız test için.
		mysqli_query( $rival, 'SET autocommit = 0' ); // phpcs:ignore WordPress.DB.RestrictedFunctions
		mysqli_query( $rival, 'INSERT INTO ' . Schema::table( 'occupancy' ) . " (resource_id, unit_start, seat, reservation_id) VALUES (1, '2026-11-17 07:00:00', 1, 9999)" ); // phpcs:ignore WordPress.DB.RestrictedFunctions
		$timeout_before = (int) $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$t0 = microtime( true );
		try {
			ReservationRepository::claim( $this->req() );
			$this->fail( 'LockBusyException bekleniyordu' );
		} catch ( \MHM\Slotiva\Reservation\LockBusyException $e ) {
			$this->assertSame( 'slotiva_busy', $e->getMessage() );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', max( 1, $timeout_before ) ) );
			mysqli_query( $rival, 'ROLLBACK' ); // phpcs:ignore WordPress.DB.RestrictedFunctions
			mysqli_close( $rival ); // phpcs:ignore WordPress.DB.RestrictedFunctions
		}
		// İki deneme × 1 sn bekleme: yeniden deneme gerçekten yapıldı.
		$this->assertGreaterThanOrEqual( 1.9, microtime( true ) - $t0 );
		$this->assertSame( 0, $this->count_rows( 'reservations' ) );
		$this->assertSame( 0, $this->count_rows( 'occupancy' ) );
		$this->assertSame( array(), $this->status_events );
	}

	public function test_confirmed_clears_hold(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'status' => 'confirmed' ) ) );
		$this->assertNull( ReservationRepository::find( $ids[0] )?->holdExpiresAt );

		$held = ReservationRepository::claim(
			$this->req(
				array(
					'order_id' => 502,
					'slots'    => array( self::utc( '2026-11-18 07:00:00' ) ),
				)
			)
		);
		$this->assertNotNull( ReservationRepository::find( $held[0] )?->holdExpiresAt );
		ReservationRepository::setStatus( $held[0], 'confirmed', 'paid' );
		$this->assertNull( ReservationRepository::find( $held[0] )?->holdExpiresAt );
	}

	public function test_notify_flag_reaches_event_ctx(): void {
		$ids = ReservationRepository::claim( $this->req( array( 'notify' => false ) ) );
		$this->assertFalse( $this->status_events[0][3]['notify'] );
		ReservationRepository::setStatus(
			$ids[0],
			'confirmed',
			'paid',
			array(
				'notify' => false,
				'reason' => 'paid',
			)
		);
		$this->assertFalse( $this->status_events[1][3]['notify'] );
		$this->assertSame( 'paid', $this->status_events[1][3]['reason'] );
		$this->assertSame( 'held', $this->status_events[1][1] );
		ReservationRepository::release( $ids[0], 'cancelled' );
		$this->assertTrue( $this->status_events[2][3]['notify'] );
		$this->assertSame( 501, $this->status_events[2][3]['order_id'] );
	}

	/**
	 * Hiç çağrılmaması gereken doğrulama (yeniden kullanım yolu).
	 *
	 * @return callable():list<\DateTimeImmutable>
	 */
	private function no_validate(): callable {
		return function (): array {
			$this->fail( 'yeniden kullanımda takvim doğrulaması çağrılmaz' );
		};
	}

	/**
	 * (Final review F13 / m-10) Order-pay, bellekteki bayat siparişle `held` isterken satır arada ödenip onaylandıysa
	 * (ağ geçidi geri çağrısı) ödenmiş satır supersede EDİLMEZ: kilit altında görülen confirmed+paid satır aynen kullanılır.
	 */
	public function test_order_pay_reuses_confirmed_paid_row(): void {
		$ids = ReservationRepository::claim( $this->req() );
		$this->assertTrue( ReservationRepository::setStatus( $ids[0], 'confirmed', 'paid', array( 'reason' => 'paid' ) ) );
		$out = ReservationRepository::claimForOrderPay( 501, $this->req( array( 'hold_expires_at' => self::utc( '2030-01-01 00:00:00' ) ) ), $this->no_validate() );
		$this->assertSame(
			array(
				'ids'    => $ids,
				'reused' => true,
			),
			$out
		);
		$r = ReservationRepository::find( $ids[0] );
		$this->assertSame( 'confirmed', $r?->status );
		$this->assertSame( 'paid', $r?->paymentState );
		$this->assertSame( 1, $this->count_rows( 'reservations' ), 'yeni tutma yok' );
		$this->assertSame( 0, $this->count_rows( 'reservations', "status = 'superseded'" ) );
	}

	/**
	 * (Final review F2) Çok dilimli siparişin son kuşağında bir dilim iptal edildiyse order-pay yeniden almaz (iptal
	 * edilen saat geri gelmez); hiçbir satır değişmez.
	 */
	public function test_order_pay_rejects_partially_cancelled_generation(): void {
		$req = $this->req(
			array(
				'slots'           => array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 08:00:00' ) ),
				'hold_expires_at' => self::utc( '2030-01-01 00:00:00' ),
			)
		);
		$ids = ReservationRepository::claim( $req );
		$this->assertTrue( ReservationRepository::release( $ids[0], 'cancelled', array( 'reason' => 'admin' ) ) );
		try {
			ReservationRepository::claimForOrderPay( 501, $req, static fn(): array => $req['slots'] );
			$this->fail( 'OrderCancelledException bekleniyordu' );
		} catch ( OrderCancelledException $e ) {
			$this->assertSame( 501, $e->orderId );
		}
		$this->assertSame( 'cancelled', ReservationRepository::find( $ids[0] )?->status );
		$this->assertSame( 'held', ReservationRepository::find( $ids[1] )?->status );
		$this->assertSame( 2, $this->count_rows( 'reservations' ) );
	}

	/**
	 * (Final review F2) Eski kuşaktaki iptal, sonradan ana ödeme yolundan yeniden verilmiş (yeni kuşak) siparişin
	 * order-pay'ini engellemez.
	 */
	public function test_order_pay_allows_new_generation_after_old_cancel(): void {
		Clock::freeze( self::utc( '2026-11-16 06:00:00' ) );
		$old = ReservationRepository::claim( $this->req( array( 'hold_expires_at' => self::utc( '2026-11-16 06:45:00' ) ) ) );
		$this->assertTrue( ReservationRepository::release( $old[0], 'cancelled', array( 'reason' => 'admin' ) ) );
		Clock::freeze( self::utc( '2026-11-16 06:05:00' ) );
		$req = $this->req( array( 'hold_expires_at' => self::utc( '2026-11-16 06:50:00' ) ) );
		$new = ReservationRepository::claimForCheckoutDraft( 501, $req, static fn(): array => $req['slots'] );
		$this->assertFalse( $new['reused'] );
		$pay = ReservationRepository::claimForOrderPay( 501, $req, $this->no_validate() );
		$this->assertTrue( $pay['reused'] );
		$this->assertSame( $new['ids'], $pay['ids'] );
	}

	/**
	 * (Final review F14 / Codex 4, §15.11) İptal, bildirim olsun olmasın iptal işleminde `sequence`'i bir artırır.
	 */
	public function test_cancel_increments_sequence_without_notify(): void {
		$a = ReservationRepository::claim( $this->req() )[0];
		$this->assertTrue( ReservationRepository::release( $a, 'cancelled', array( 'notify' => false ) ) );
		$this->assertSame( 1, ReservationRepository::find( $a )?->sequence, 'release' );

		$b = ReservationRepository::claim( $this->req( self::other( 502, '2026-11-18 07:00:00' ) ) )[0];
		$this->assertTrue( ReservationRepository::setStatus( $b, 'cancelled', 'refunded', array( 'notify' => false ) ) );
		$this->assertSame( 1, ReservationRepository::find( $b )?->sequence, 'setStatus' );

		$c = ReservationRepository::claim( $this->req( self::other( 503, '2026-11-19 07:00:00' ) ) )[0];
		$this->assertSame( 1, ReservationRepository::releaseForOrder( 503, 'cancelled' ) );
		$this->assertSame( 1, ReservationRepository::find( $c )?->sequence, 'releaseForOrder' );

		// Süre dolumu iptal değildir: sequence değişmez.
		$d = ReservationRepository::claim( $this->req( self::other( 504, '2026-11-20 07:00:00' ) ) )[0];
		$this->assertTrue( ReservationRepository::release( $d, 'expired' ) );
		$this->assertSame( 0, ReservationRepository::find( $d )?->sequence );
	}

	/**
	 * Başka sipariş + dilim ezmesi.
	 *
	 * @param int    $order Sipariş.
	 * @param string $slot  Dilim (UTC).
	 * @return array<string,mixed>
	 */
	private static function other( int $order, string $slot ): array {
		return array(
			'order_id' => $order,
			'slots'    => array( self::utc( $slot ) ),
		);
	}

	/**
	 * (Final review F6, F9) Sipariş geneli iptal TEK işlemde: tüm etkin (ve istenirse çakışmalı) satırlar birlikte
	 * `cancelled` olur; olaylar COMMIT'ten sonra, hepsi yazıldıktan sonra gelir.
	 */
	public function test_cancel_for_order_is_one_transaction(): void {
		$ids = ReservationRepository::claim(
			$this->req(
				array(
					'slots'         => array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 08:00:00' ), self::utc( '2026-11-17 09:00:00' ) ),
					'status'        => 'confirmed',
					'payment_state' => 'paid',
				)
			)
		);
		$this->assertTrue( ReservationRepository::setStatus( $ids[2], 'conflict', 'paid' ) );
		$seen = array();
		$spy  = static function () use ( &$seen, $ids ): void {
			$seen[] = array_map( static fn( int $i ): string => (string) ReservationRepository::find( $i )?->status, $ids );
		};
		add_action( 'slotiva_reservation_status', $spy );
		try {
			$n = ReservationRepository::cancelForOrder( 501, 'refunded', array( 'held', 'confirmed', 'conflict' ), 'refunded' );
		} finally {
			remove_action( 'slotiva_reservation_status', $spy );
		}
		$this->assertSame( 3, $n );
		$this->assertCount( 3, $seen );
		$this->assertSame( array( 'cancelled', 'cancelled', 'cancelled' ), $seen[0], 'ilk olayda bile hepsi iptal' );
		foreach ( $ids as $id ) {
			$this->assertSame( 'refunded', ReservationRepository::find( $id )?->paymentState );
			$this->assertSame( array(), $this->units_of( $id ) );
		}
		$this->assertSame( 0, ReservationRepository::cancelForOrder( 501, 'refunded', array( 'held', 'confirmed', 'conflict' ), 'refunded' ), 'yineleme zararsız' );
	}

	/**
	 * (Final review F12 / m-8) Kişisel alanların boşaltılması depo yolundan (satır kilidi + işlem): ad/e-posta/telefon,
	 * oturum ve IP özeti boşalır, randevu kaydı kalır.
	 */
	public function test_scrub_personal_clears_columns(): void {
		$ids = ReservationRepository::claim(
			$this->req(
				array(
					'session_hash' => md5( 's' ),
					'ip_hash'      => md5( 'i' ),
				)
			)
		);
		$this->assertSame( 1, ReservationRepository::scrubPersonal( $ids ) );
		$r = ReservationRepository::find( $ids[0] );
		$this->assertSame( '', $r?->customerName );
		$this->assertSame( '', $r?->customerEmail );
		$this->assertSame( '', $r?->customerPhone );
		$this->assertNull( $r?->sessionHash );
		$this->assertNull( $r?->ipHash );
		$this->assertSame( 'held', $r?->status, 'kayıt kalır' );
		$this->assertSame( 0, ReservationRepository::scrubPersonal( array() ) );
	}

	/**
	 * (Final review F12) Değişmez: `slotiva_reservations`/`slotiva_occupancy` tablolarına depo dışında hiçbir kaynak
	 * dosya yazmaz (UPDATE/INSERT/DELETE ya da `$wpdb->update|insert|delete|replace`).
	 */
	public function test_no_reservation_write_outside_repository(): void {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( ! str_ends_with( $path, '.php' ) || str_ends_with( $path, '/Reservation/ReservationRepository.php' ) || str_ends_with( $path, '/Db/Schema.php' ) ) {
				continue;
			}
			$src = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- yerel kaynak dosyası.
			// Tablo değişkeni adları depo kalıbıyla: $res = reservations, $occ = occupancy.
			if ( preg_match( '/(UPDATE|INSERT\s+INTO|DELETE\s+FROM|REPLACE\s+INTO)\s+\{\$(res|occ)\}/i', $src )
				|| preg_match( '/\$wpdb->(update|insert|delete|replace)\(\s*(\$(res|occ)\b|Schema::table\(\s*\'(reservations|occupancy)\')/', $src ) ) {
				$found[] = substr( $path, strlen( $root ) + 1 );
			}
		}
		$this->assertSame( array(), $found, 'rezervasyon yazımı yalnız ReservationRepository' );
	}
}
