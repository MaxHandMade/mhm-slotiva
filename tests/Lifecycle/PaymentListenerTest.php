<?php
/**
 * Ödeme algılama (spec §6.4, §15.2, §15.3): durum değişimi ana dinleyici, `payment_complete` ikincil, iptal, iade,
 * geç ödeme (yeniden al / çakışma), `on-hold` havale süresi.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Lifecycle;

use MHM\Slotiva\Lifecycle\HoldScheduler;
use MHM\Slotiva\Lifecycle\PaymentListener;
use MHM\Slotiva\Reservation\EventLog;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;
use MHM\Slotiva\Tests\Support\TestCardGateway;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.RestrictedClasses, WordPress.DB.DirectDatabaseQuery -- bağımsız ikinci bağlantı (mysqli) ve oturum kilit beklemesi yalnız test için.

class PaymentListenerTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;

	/**
	 * Satır kilidi testinde ana bağlantının önceki `innodb_lock_wait_timeout` değeri.
	 *
	 * @var string
	 */
	private string $lock_wait = '50';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		$this->set_up_lifecycle();
	}

	public function tear_down(): void {
		$this->tear_down_lifecycle();
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * PayTR başarıda `payment_complete()` çağırmaz, yalnız `update_status($options['paytr_order_status'])` (spec B1).
	 *
	 * @dataProvider paid_statuses
	 * @param string $to PayTR ayarındaki durum.
	 */
	public function test_paytr_style_status_change_confirms( string $to ): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$this->assertCount( 1, $this->expiry_jobs( $ids[0] ), 'alma anında süre dolumu işi kuruldu' );
		$before = did_action( 'woocommerce_payment_complete' );

		$this->order( $oid )->update_status( $to );

		$this->assertSame( $before, did_action( 'woocommerce_payment_complete' ), 'payment_complete yolu kullanılmadı' );
		$r = $this->row( $ids[0] );
		$this->assertSame( 'confirmed', $r->status );
		$this->assertSame( 'paid', $r->paymentState );
		$this->assertNull( $r->holdExpiresAt );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
	}

	/**
	 * Ödenmiş sayılan durumlar.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function paid_statuses(): array {
		return array(
			'processing' => array( 'processing' ),
			'completed'  => array( 'completed' ),
		);
	}

	public function test_payment_complete_path_idempotent(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$this->order( $oid )->payment_complete( 'tx-1' ); // Durum değişimi + woocommerce_payment_complete.
		do_action( 'woocommerce_payment_complete', $oid, 'tx-1' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC kancası.
		PaymentListener::onPaid( $oid );
		$this->order( $oid )->update_status( 'completed' ); // İkinci ödenmiş durum.

		$this->assertSame( 1, $this->transitions_to( 'confirmed' ), 'tek onay olayı' );
		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status );
		$this->assertSame( array(), $this->conflicts );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
	}

	public function test_cod_processing_not_paid(): void {
		[ $oid, $ids ] = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$this->order( $oid )->update_status( 'processing' );
		$this->order( $oid )->payment_complete();
		$r = $this->row( $ids[0] );
		$this->assertSame( 'confirmed', $r->status );
		$this->assertSame( 'on_site', $r->paymentState, 'kapıda ödeme "ödendi" değildir' );
	}

	public function test_failed_keeps_hold(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$hold          = $this->row( $ids[0] )->holdExpiresAt;
		$this->order( $oid )->update_status( 'failed' );
		$r = $this->row( $ids[0] );
		$this->assertSame( 'held', $r->status );
		$this->assertEquals( $hold, $r->holdExpiresAt );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
		$this->assertCount( 1, $this->expiry_jobs( $ids[0] ), 'iş yerinde' );
	}

	public function test_cancelled_order_releases(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$this->order( $oid )->update_status( 'cancelled' );
		$this->assertSame( 'cancelled', $this->row( $ids[0] )->status );
		$this->assertSame( 0, self::rows( 'occupancy' ) );
		$this->assertSame( array(), $this->expiry_jobs( $ids[0] ) );
	}

	public function test_late_paid_reclaims_when_free(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 0, self::rows( 'occupancy' ) );

		$this->order( $oid )->update_status( 'processing' );

		$r = $this->row( $ids[0] );
		$this->assertSame( 'confirmed', $r->status );
		$this->assertSame( 'paid', $r->paymentState );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
		$this->assertCount( 1, ReservationRepository::forOrder( $oid ), 'ikinci rezervasyon yazılmadı' );
		$this->assertSame( array(), $this->conflicts );
	}

	public function test_late_paid_conflict_when_taken(): void {
		[ $oid, $ids, $pid ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$rival = $this->rival( $pid );

		$this->order( $oid )->update_status( 'processing' );
		$this->order( $oid )->payment_complete(); // İkinci bildirim: yeni çakışma yok.
		PaymentListener::onPaid( $oid );

		$r = $this->row( $ids[0] );
		$this->assertSame( 'conflict', $r->status );
		$this->assertSame( 'paid', $r->paymentState );
		$this->assertCount( 1, ReservationRepository::forOrder( $oid ), 'ikinci rezervasyon yazılmadı' );
		$this->assertSame( 'confirmed', $this->row( $rival )->status, 'başka randevunun üstüne yazılmadı' );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
		$this->assertCount( 1, $this->conflicts, 'slotiva_conflict bir kez' );
		$this->assertSame( array( $oid, array( $ids[0] ), 'late_paid_taken' ), $this->conflicts[0] );
		$this->assertNotEmpty( preg_grep( '/taken/i', $this->notes( $oid ) ), 'sipariş notu' );
	}

	public function test_paid_after_resubmit_ignores_replaced_rows(): void {
		// Gerçek akış: Store API ödemesi 10:00 alır, aynı sipariş 11:00 ile yeniden gönderilir → CheckoutFlow::claim →
		// replaceForOrder eski satırı `superseded` yapar (expired DEĞİL). Eski saat sonra başkasına geçer.
		$pid = $this->bookable();
		$this->assertIsString( WC()->cart->add_to_cart( $pid, 1 ) );
		$first = $this->checkout( array( '2026-11-17T07:00:00Z' ), TestCardGateway::ID );
		$this->assertSame( 200, $first->get_status(), (string) wp_json_encode( $first->get_data() ) );
		$oid    = (int) $first->get_data()['order_id'];
		$second = $this->checkout( array( '2026-11-17T08:00:00Z' ), TestCardGateway::ID );
		$this->assertSame( $oid, (int) $second->get_data()['order_id'], 'aynı sipariş yeniden kullanıldı' );
		$rows = ReservationRepository::forOrder( $oid );
		$this->assertSame( array( 'superseded', 'held' ), array_map( static fn( $r ): string => $r->status, $rows ) );
		$this->rival( $pid ); // Eski saat (10:00) başkasında.

		$this->order( $oid )->update_status( 'processing' );

		$this->assertSame( 'confirmed', $this->row( $rows[1]->id )->status );
		$this->assertSame( 'superseded', $this->row( $rows[0]->id )->status, 'yeniden gönderimin eski satırı yok sayıldı' );
		$this->assertSame( array(), $this->conflicts, 'yanlış çakışma yok' );
	}

	public function test_partial_lazy_expiry_then_late_paid_marks_conflict_for_taken_slot(): void {
		// per_slot iki dilim; tutma geçti, iş koşmadı; rakip yalnız 10:30'u alır → yalnız o satır tembel düşer.
		[ $oid, $ids, $pid ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ) );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:50:00Z' ) );
		$rival = $this->rival( $pid, '2026-11-17T07:30:00Z' );
		$this->assertSame( 'held', $this->row( $ids[0] )->status );
		$this->assertSame( 'expired', $this->row( $ids[1] )->status );

		$this->order( $oid )->update_status( 'processing' );
		PaymentListener::onPaid( $oid ); // Yinelenen bildirim: yeni çakışma yok.

		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status );
		$this->assertSame( 'conflict', $this->row( $ids[1] )->status, 'ödenmiş dilim sessizce kaybolmaz' );
		$this->assertSame( 'paid', $this->row( $ids[1] )->paymentState );
		$this->assertSame( 'confirmed', $this->row( $rival )->status );
		$this->assertCount( 1, $this->conflicts, 'slotiva_conflict bir kez' );
		$this->assertSame( array( $oid, array( $ids[1] ), 'late_paid_taken' ), $this->conflicts[0] );
		$this->assertCount( 2, ReservationRepository::forOrder( $oid ), 'ikinci rezervasyon yazılmadı' );
	}

	public function test_partial_lazy_expiry_then_late_paid_reclaims_free_slot(): void {
		[ $oid, $ids, $pid ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ) );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:50:00Z' ) );
		$rival = $this->rival( $pid, '2026-11-17T07:30:00Z' );
		$this->assertTrue( ReservationRepository::release( $rival, 'cancelled' ) ); // Rakip vazgeçti, saat yine boş.

		$this->order( $oid )->update_status( 'processing' );

		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status );
		$this->assertSame( 'confirmed', $this->row( $ids[1] )->status, 'boş dilim yeniden alındı' );
		$this->assertSame( 'paid', $this->row( $ids[1] )->paymentState );
		$this->assertSame( array(), $this->conflicts );
		$this->assertSame( 4, self::rows( 'occupancy' ) );
	}

	public function test_superseded_order_paid_marks_conflict(): void {
		$session       = md5( 'oturum-a' );
		[ $a, $a_ids ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z' ), $session );
		[ $b, $b_ids ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z' ), $session );
		$this->assertSame( 'superseded', $this->row( $a_ids[0] )->status, 'kurgu: aynı oturumun yeni siparişi eskisini bıraktı' );

		$this->order( $a )->update_status( 'processing' );
		PaymentListener::onPaid( $a ); // Tekrar: yeni çakışma yok.

		$r = $this->row( $a_ids[0] );
		$this->assertSame( 'conflict', $r->status, 'superseded yeniden alınmaz → çakışma' );
		$this->assertSame( 'paid', $r->paymentState );
		$this->assertSame( 'held', $this->row( $b_ids[0] )->status, 'yeni sipariş etkilenmedi' );
		$this->assertCount( 1, ReservationRepository::forOrder( $a ) );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
		$this->assertCount( 1, $this->conflicts );
		$this->assertSame( array( $a, array( $a_ids[0] ), 'superseded_paid' ), $this->conflicts[0] );
		unset( $b );
	}

	public function test_full_refund_cancels_partial_does_not(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$this->order( $oid )->update_status( 'processing' );
		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status );

		$partial = wc_create_refund(
			array(
				'order_id' => $oid,
				'amount'   => '10',
			)
		);
		$this->assertNotWPError( $partial );
		$r = $this->row( $ids[0] );
		$this->assertSame( 'confirmed', $r->status, 'kısmi iade randevuya dokunmaz' );
		$this->assertSame( 'paid', $r->paymentState );

		$full = wc_create_refund(
			array(
				'order_id' => $oid,
				'amount'   => '90',
			)
		);
		$this->assertNotWPError( $full );
		$r = $this->row( $ids[0] );
		$this->assertSame( 'cancelled', $r->status );
		$this->assertSame( 'refunded', $r->paymentState );
		$this->assertSame( 0, self::rows( 'occupancy' ) );
	}

	public function test_on_hold_transition_any_gateway_sets_bank_hold(): void {
		// Özel EFT ağ geçidi: yöntem kimliği havale listesinde değil, alma anında kart gibi (45 dk) tutuldu.
		[ $oid, $ids ] = $this->booked_order( 'ozel_eft' );
		$this->assertSame( '2026-11-16 06:45:00', $this->row( $ids[0] )->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );

		$this->order( $oid )->update_status( 'on-hold' );

		$r = $this->row( $ids[0] );
		$this->assertSame( 'held', $r->status );
		$this->assertSame( 'awaiting_transfer', $r->paymentState );
		$this->assertSame( '2026-11-17 06:00:00', $r->holdExpiresAt?->format( 'Y-m-d H:i:s' ), 'şimdi + havale süresi (24 sa)' );
		$jobs = $this->expiry_jobs( $ids[0] );
		$this->assertCount( 1, $jobs, 'eski iş kaldırıldı, yenisi kuruldu' );
		$this->assertSame( '2026-11-17 06:00:00', $jobs[0]['args']['hold_expires_at'] );
		$this->assertSame( $r->holdExpiresAt?->getTimestamp(), $jobs[0]['ts'] );
	}

	public function test_bacs_on_hold_then_admin_marks_paid_confirms(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->order( $oid )->update_status( 'on-hold' );
		$this->order( $oid )->update_status( 'processing' );
		$r = $this->row( $ids[0] );
		$this->assertSame( 'confirmed', $r->status );
		$this->assertSame( 'paid', $r->paymentState );
		$this->assertSame( array(), $this->expiry_jobs( $ids[0] ) );
	}

	public function test_lock_busy_twice_then_third_run_confirms_late_paid(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );

		$rival = $this->hold_order_lock( $oid );
		try {
			$this->order( $oid )->update_status( 'processing' );
			$this->assertSame( 1, $this->pending_sync( $oid )['attempt'] ?? null, 'ilk yeniden deneme' );
			$this->run_pending_sync( $oid ); // Kilit hâlâ meşgul: koşan iş kendi yerine yenisini kurar.
			$this->assertSame( 'expired', $this->row( $ids[0] )->status );
			$this->assertSame( 2, $this->pending_sync( $oid )['attempt'] ?? null, 'zincir kopmadı' );
		} finally {
			$this->release_lock( $rival );
		}
		$this->run_pending_sync( $oid );
		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status );
		$this->assertNull( $this->pending_sync( $oid ) );
	}

	public function test_lock_busy_twice_then_third_run_releases_cancelled(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$rival         = $this->hold_order_lock( $oid );
		try {
			$this->order( $oid )->update_status( 'cancelled' );
			$this->assertSame( 'held', $this->row( $ids[0] )->status, 'kilit meşgul: karar verilmedi' );
			$this->run_pending_sync( $oid );
			$this->assertSame( 2, $this->pending_sync( $oid )['attempt'] ?? null );
		} finally {
			$this->release_lock( $rival );
		}
		$this->run_pending_sync( $oid );
		$this->assertSame( 'cancelled', $this->row( $ids[0] )->status );
		$this->assertSame( 0, self::rows( 'occupancy' ) );
		$this->assertNull( $this->pending_sync( $oid ) );
	}

	public function test_lock_busy_twice_then_third_run_cancels_refunded(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$this->order( $oid )->update_status( 'processing' );
		$rival = $this->hold_row_lock( $ids[0] );
		try {
			$this->assertNotWPError(
				wc_create_refund(
					array(
						'order_id' => $oid,
						'amount'   => '100',
					)
				)
			);
			$this->assertSame( 'confirmed', $this->row( $ids[0] )->status, 'satır kilitli: karar verilmedi' );
			$this->run_pending_sync( $oid );
			$this->assertSame( 2, $this->pending_sync( $oid )['attempt'] ?? null );
		} finally {
			$this->release_row_lock( $rival );
		}
		$this->run_pending_sync( $oid );
		$r = $this->row( $ids[0] );
		$this->assertSame( 'cancelled', $r->status );
		$this->assertSame( 'refunded', $r->paymentState );
		$this->assertNull( $this->pending_sync( $oid ) );
	}

	public function test_retry_chain_stops_at_max_attempts_with_note(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		remove_action( 'woocommerce_order_status_changed', array( PaymentListener::class, 'onStatusChanged' ), 10 );
		$this->order( $oid )->update_status( 'processing' );
		PaymentListener::register();

		$rival = $this->hold_order_lock( $oid );
		try {
			PaymentListener::sync( $oid, PaymentListener::MAX_ATTEMPTS );
		} finally {
			$this->release_lock( $rival );
		}
		$this->assertNull( $this->pending_sync( $oid ), 'sınırda yeni iş yok' );
		$this->assertNotEmpty( preg_grep( '/repeated attempts/', $this->notes( $oid ) ), 'yönetici notu' );
	}

	/**
	 * Bekleyen yeniden deneme işinin argümanları (yoksa null).
	 *
	 * @param int $oid Sipariş.
	 * @return array<string,mixed>|null
	 */
	private function pending_sync( int $oid ): ?array {
		foreach ( $this->sync_actions( $oid ) as $action ) {
			return $action->get_args();
		}
		return null;
	}

	/**
	 * Siparişin bekleyen yeniden deneme işleri (kimlik → iş).
	 *
	 * @param int $oid Sipariş.
	 * @return array<int,\ActionScheduler_Action>
	 */
	private function sync_actions( int $oid ): array {
		$out = array();
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => PaymentListener::SYNC_HOOK,
				'group'    => HoldScheduler::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		) as $id => $action ) {
			if ( (int) ( $action->get_args()['order_id'] ?? 0 ) === $oid ) {
				$out[ (int) $id ] = $action;
			}
		}
		return $out;
	}

	/**
	 * Bekleyen tek yeniden deneme işini Action Scheduler'daki gibi koşar: önce RUNNING işaretlenir (as_has_scheduled_action
	 * bu işi de sayar), sonra işleyici, sonra tamamlandı.
	 *
	 * @param int $oid Sipariş.
	 */
	private function run_pending_sync( int $oid ): void {
		$actions = $this->sync_actions( $oid );
		$this->assertCount( 1, $actions );
		$id    = (int) array_key_first( $actions );
		$args  = $actions[ $id ]->get_args();
		$store = \ActionScheduler::store();
		$store->log_execution( $id );
		$this->assertSame( \ActionScheduler_Store::STATUS_RUNNING, $store->get_status( (string) $id ) );
		try {
			do_action_ref_array( PaymentListener::SYNC_HOOK, array_values( $args ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- sabit kanca adı.
		} finally {
			$store->mark_complete( $id );
		}
	}

	/**
	 * Bağımsız bağlantıyla siparişin adlı kilidini tutar; deponun kilit beklemesi 0 sn.
	 *
	 * @param int $oid Sipariş.
	 */
	private function hold_order_lock( int $oid ): \mysqli {
		$rival = $this->rival_connection();
		$name  = $GLOBALS['wpdb']->prefix . 'slotiva_order_' . $oid;
		$got   = mysqli_query( $rival, "SELECT GET_LOCK('" . mysqli_real_escape_string( $rival, $name ) . "', 0)" );
		$this->assertInstanceOf( \mysqli_result::class, $got );
		$this->assertSame( '1', (string) mysqli_fetch_row( $got )[0] );
		add_filter( 'slotiva_lock_timeout', '__return_zero' );
		return $rival;
	}

	/**
	 * Adlı kilidi bırakır.
	 *
	 * @param \mysqli $rival Bağlantı.
	 */
	private function release_lock( \mysqli $rival ): void {
		remove_filter( 'slotiva_lock_timeout', '__return_zero' );
		mysqli_query( $rival, 'DO RELEASE_ALL_LOCKS()' );
		mysqli_close( $rival );
	}

	/**
	 * Bağımsız bağlantıyla rezervasyon satırını FOR UPDATE kilitler; bu bağlantının kilit beklemesi 1 sn.
	 *
	 * @param int $id Rezervasyon.
	 */
	private function hold_row_lock( int $id ): \mysqli {
		global $wpdb;
		$rival = $this->rival_connection();
		mysqli_query( $rival, 'START TRANSACTION' );
		$got = mysqli_query( $rival, 'SELECT id FROM ' . \MHM\Slotiva\Db\Schema::table( 'reservations' ) . ' WHERE id = ' . (int) $id . ' FOR UPDATE' );
		$this->assertInstanceOf( \mysqli_result::class, $got );
		$this->lock_wait = (string) $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );
		$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		return $rival;
	}

	/**
	 * Satır kilidini bırakır, bekleme süresini geri yükler.
	 *
	 * @param \mysqli $rival Bağlantı.
	 */
	private function release_row_lock( \mysqli $rival ): void {
		global $wpdb;
		mysqli_query( $rival, 'ROLLBACK' );
		mysqli_close( $rival );
		$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', (int) $this->lock_wait ) );
	}

	/**
	 * Test veritabanına ikinci bağlantı.
	 */
	private function rival_connection(): \mysqli {
		$rival = mysqli_init();
		$this->assertInstanceOf( \mysqli::class, $rival );
		$this->assertTrue( @mysqli_real_connect( $rival, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- test bağlantısı.
		return $rival;
	}

	public function test_non_slotiva_order_untouched(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_payment_method( TestCardGateway::ID );
		$order->save();
		$order->update_status( 'processing' );
		$order->update_status( 'cancelled' );
		$this->assertSame( 0, self::rows( 'reservations' ) );
		$this->assertSame( array(), EventLog::for( 1 ) );
	}

	/**
	 * Depolama kipleri.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function storage_modes(): array {
		return array(
			'legacy' => array( false ),
			'hpos'   => array( true ),
		);
	}

	/**
	 * Satırın son durum olayının nedeni ve müşteri iptal e-postası işareti.
	 *
	 * @param int $id Rezervasyon.
	 * @return array{0:string,1:bool} [neden, mailed_cancelled var mı]
	 */
	private function cancel_trace( int $id ): array {
		$reason = '';
		$mailed = false;
		foreach ( EventLog::for( $id ) as $e ) {
			if ( 'status' === $e['type'] && 'cancelled' === ( $e['data']['to'] ?? null ) ) {
				$reason = (string) ( $e['data']['reason'] ?? '' );
			}
			$mailed = $mailed || 'mailed_cancelled' === $e['type'];
		}
		return array( $reason, $mailed );
	}

	/**
	 * (Final review F9 / m-5, Codex 3) Çöpe atılan ya da kalıcı silinen sipariş saatini bırakır: etkin satırlar depo
	 * yolundan `cancelled` (reason `order_deleted`, müşteriye e-posta yok); yineleme zararsız. Çöpten geri alma satırı
	 * SESSİZCE canlandırmaz (bilinçli: saat bu arada başkasına verilmiş olabilir; yönetici yeni randevu ekler).
	 * Eski depolama ve HPOS; eski depolamada yazı listesinden doğrudan `wp_trash_post` da.
	 *
	 * @dataProvider storage_modes
	 *
	 * @param bool $hpos HPOS açık mı.
	 */
	public function test_trashed_or_deleted_order_releases_booking( bool $hpos ): void {
		$sync   = \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class;
		$ctrl   = \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class;
		$before = array(
			'enabled' => get_option( 'woocommerce_custom_orders_table_enabled' ),
			'sync'    => get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ),
		);
		if ( $hpos ) {
			wc_get_container()->get( $sync )->create_database_tables();
		}
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		try {
			$this->assertSame( $hpos, wc_get_container()->get( $ctrl )->custom_orders_table_usage_is_enabled() );

			// Çöp: kapıda ödemeli onaylı randevu.
			[ $a, $ra ] = $this->booked_order( 'cod', 'confirmed', 'on_site', null, array( '2026-11-17T07:00:00Z' ) );
			$this->order( $a )->delete( false );
			$this->assertSame( 'cancelled', $this->row( $ra[0] )->status, 'çöp saati bırakır' );
			$this->assertSame( array( 'order_deleted', false ), $this->cancel_trace( $ra[0] ) );
			$this->assertSame( array(), ReservationRepository::forOrder( $a, true ) );
			// Saat yeniden alınabilir.
			$this->assertSame( 'confirmed', $this->row( $this->rival( $this->row( $ra[0] )->productId, '2026-11-17T07:00:00Z' ) )->status );

			// Çöpten geri alma canlandırmaz.
			$trashed = wc_get_order( $a );
			$this->assertInstanceOf( \WC_Order::class, $trashed );
			$this->assertTrue( $trashed->untrash() );
			$this->assertSame( 'cancelled', $this->row( $ra[0] )->status, 'geri alma sessizce yeniden almaz' );

			// Kalıcı silme: ödenmiş kart randevusu.
			[ $b, $rb ] = $this->booked_order( TestCardGateway::ID, 'confirmed', 'paid', null, array( '2026-11-18T07:00:00Z' ) );
			$this->order( $b )->delete( true );
			$this->assertSame( 'cancelled', $this->row( $rb[0] )->status, 'silme saati bırakır' );
			$this->assertSame( array( 'order_deleted', false ), $this->cancel_trace( $rb[0] ) );

			if ( ! $hpos ) {
				// Eski depolama: yazı listesindeki "Çöpe taşı" doğrudan wp_trash_post çağırır (WC kancası yok).
				[ $c, $rc ] = $this->booked_order( 'cod', 'confirmed', 'on_site', null, array( '2026-11-19T07:00:00Z' ) );
				wp_trash_post( $c );
				$this->assertSame( 'cancelled', $this->row( $rc[0] )->status, 'wp_trash_post' );
				// Yazı listesinden kalıcı silme: wp_delete_post.
				[ $d, $rd ] = $this->booked_order( 'cod', 'confirmed', 'on_site', null, array( '2026-11-20T07:00:00Z' ) );
				wp_delete_post( $d, true );
				$this->assertSame( 'cancelled', $this->row( $rd[0] )->status, 'wp_delete_post' );
			}
		} finally {
			$ids = wc_get_orders(
				array(
					'limit'  => -1,
					'return' => 'ids',
					'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
				)
			);
			foreach ( (array) $ids as $id ) {
				wc_get_order( (int) $id )?->delete( true );
			}
			update_option( 'woocommerce_custom_orders_table_enabled', $before['enabled'] );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', $before['sync'] );
		}
	}

	/**
	 * (Pre-ZIP gate G4) Depodan beklenmedik bir istisna (ör. LogicException) sipariş silmeyi DURDURMAZ: günlüğe yazılır,
	 * sipariş silinir; satır yönetici listesinde kalır.
	 */
	public function test_unexpected_error_while_releasing_does_not_abort_order_deletion(): void {
		[ $oid, $ids ] = $this->booked_order( 'cod', 'confirmed', 'on_site', null, array( '2026-11-17T07:00:00Z' ) );
		$boom          = static function (): int {
			throw new \LogicException( 'slotiva_nested_transaction' );
		};
		add_filter( 'slotiva_lock_timeout', $boom );
		try {
			$this->order( $oid )->delete( true );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $boom );
		}
		$this->assertFalse( wc_get_order( $oid ), 'sipariş silindi' );
		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status, 'satır yerinde (yönetici listesinde)' );
	}
}
