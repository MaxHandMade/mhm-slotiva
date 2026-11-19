<?php
/**
 * Tutma süresi işleri ve süpürücü (spec §6.4, §15.2, §15.7): kart siparişi asla otomatik iptal edilmez; havale/çek
 * (`on-hold`) son tarihte iptal; işler `reservation_id` + beklenen `sequence` + `hold_expires_at` taşır, bayat iş no-op.
 *
 * İşleyiciler doğrudan çağrılır: `Clock::freeze` Action Scheduler'ın saatini değiştirmez.
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
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- test kurgusu sipariş tarihini doğrudan geri alır.

class HoldSchedulerTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;

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

	public function test_claim_schedules_expiry_job_with_reservation_args(): void {
		[ , $ids ] = $this->booked_order( TestCardGateway::ID );
		$r         = $this->row( $ids[0] );
		$jobs      = $this->expiry_jobs( $ids[0] );
		$this->assertCount( 1, $jobs );
		$this->assertSame(
			array(
				'reservation_id'  => $r->id,
				'sequence'        => $r->sequence,
				'hold_expires_at' => '2026-11-16 06:45:00',
			),
			$jobs[0]['args']
		);
		$this->assertSame( $r->holdExpiresAt?->getTimestamp(), $jobs[0]['ts'], 'tamsayı zaman damgası = tutma bitişi' );
	}

	public function test_card_hold_expiry_keeps_order_pending(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:46:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 0, self::rows( 'occupancy' ), 'dilim yeniden açık' );
		$this->assertSame( 'pending', $this->order( $oid )->get_status(), 'kart siparişi iptal edilmez (§15.2)' );
	}

	public function test_expiry_before_deadline_is_noop(): void {
		[ , $ids ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:30:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'held', $this->row( $ids[0] )->status );
	}

	public function test_card_orders_stay_pending_beyond_24h_even_with_stock_management(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-18T06:00:00Z' ) ); // 48 saat sonra.
		$this->run_expiry( $this->row( $ids[0] ) );
		HoldScheduler::sweep();

		// Kontrol: Slotiva dışı bir Store API siparişi aynı temizlikte iptal edilmeli (test boş geçmesin).
		$plain = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $plain );
		$plain->set_created_via( 'store-api' );
		$plain->set_payment_method( TestCardGateway::ID );
		$plain->save();
		$this->backdate( $oid );
		$this->backdate( $plain->get_id() );

		$this->assertFalse( (bool) apply_filters( 'woocommerce_cancel_unpaid_order', true, $this->order( $oid ) ), 'süzgeç Slotiva siparişini korur' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC kancası.
		$this->assertTrue( (bool) apply_filters( 'woocommerce_cancel_unpaid_order', true, $this->order( $plain->get_id() ) ), 'süzgeç diğer siparişlere dokunmaz' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC kancası.

		$stock = get_option( 'woocommerce_manage_stock' );
		$hold  = get_option( 'woocommerce_hold_stock_minutes' );
		update_option( 'woocommerce_manage_stock', 'yes' );
		update_option( 'woocommerce_hold_stock_minutes', '60' );
		try {
			wc_cancel_unpaid_orders();
		} finally {
			update_option( 'woocommerce_manage_stock', $stock );
			update_option( 'woocommerce_hold_stock_minutes', $hold );
			as_unschedule_all_actions( 'woocommerce_cancel_unpaid_orders' );
		}

		$this->assertSame( 'cancelled', $this->order( $plain->get_id() )->get_status(), 'kontrol: WC temizliği gerçekten koştu' );
		$this->assertSame( 'pending', $this->order( $oid )->get_status(), 'kart siparişi 24 saati aşsa da bekler' );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
	}

	public function test_bacs_expiry_cancels_order(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 0, self::rows( 'occupancy' ) );
		$this->assertSame( 'cancelled', $this->order( $oid )->get_status() );
		$this->assertNotEmpty( preg_grep( '/transfer/i', $this->notes( $oid ) ), 'sipariş notu' );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status, 'sonraki cancelled geçişi expired satırı ezmez' );
	}

	public function test_bacs_expiry_sets_expired_with_bank_reason(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$reasons = array();
		foreach ( EventLog::for( $ids[0] ) as $e ) {
			if ( 'status' === $e['type'] && 'expired' === ( $e['data']['to'] ?? '' ) ) {
				$reasons[] = $e['data']['reason'] ?? '';
			}
		}
		$this->assertSame( array( 'bank_expired' ), $reasons );
	}

	public function test_card_order_in_on_hold_is_never_auto_cancelled(): void {
		// PayTR `wc-on-hold` ayarı / yalnız yetkilendirme kartı: kart ağ geçidi siparişi `on-hold`a çeker.
		[ $oid, $ids, $pid ] = $this->booked_order( TestCardGateway::ID );
		$this->order( $oid )->update_status( 'on-hold' );
		$this->assertSame( 'awaiting_transfer', $this->row( $ids[0] )->paymentState, 'tutma her ağ geçidinde uzar' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );

		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status, 'dilim bırakıldı' );
		$this->assertSame( 'on-hold', $this->order( $oid )->get_status(), 'kart siparişi iptal edilmez (§15.2)' );
		$this->assertNotEmpty( preg_grep( '/not cancelled/', $this->notes( $oid ) ), 'sipariş notu' );
		$reasons = array_column( array_column( EventLog::for( $ids[0] ), 'data' ), 'reason' );
		$this->assertContains( 'hold_expired', $reasons );
		$this->assertNotContains( 'bank_expired', $reasons );

		HoldScheduler::sweep();
		$this->assertSame( 'on-hold', $this->order( $oid )->get_status(), 'süpürücü de iptal etmez' );
		unset( $pid );
	}

	public function test_lazily_expired_card_on_hold_not_cancelled_by_sweeper(): void {
		[ $oid, $ids, $pid ] = $this->booked_order( TestCardGateway::ID );
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->rival( $pid );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		HoldScheduler::sweep();
		$this->assertSame( 'on-hold', $this->order( $oid )->get_status() );
	}

	public function test_bacs_with_payment_trace_not_auto_cancelled(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$order         = $this->order( $oid );
		$order->set_transaction_id( 'EFT-123' );
		$order->save();
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 'on-hold', $this->order( $oid )->get_status(), 'ödeme izi olan sipariş iptal edilmez' );
	}

	public function test_custom_transfer_method_via_filter_is_cancelled(): void {
		add_filter( 'slotiva_transfer_methods', array( $this, 'add_eft' ) );
		try {
			[ $oid, $ids ] = $this->booked_order( 'ozel_eft' );
			$this->order( $oid )->update_status( 'on-hold' );
			Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
			$this->run_expiry( $this->row( $ids[0] ) );
			$this->assertSame( 'cancelled', $this->order( $oid )->get_status() );
		} finally {
			remove_filter( 'slotiva_transfer_methods', array( $this, 'add_eft' ) );
		}
	}

	/**
	 * Süzgeç: özel EFT ağ geçidini havale sayar.
	 *
	 * @param array<int,string> $methods Yöntemler.
	 * @return list<string>
	 */
	public function add_eft( array $methods ): array {
		$methods[] = 'ozel_eft';
		return $methods;
	}

	public function test_bank_hold_on_pending_order_expires_without_cancel(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 'pending', $this->order( $oid )->get_status(), 'yalnız on-hold sipariş iptal edilir' );
	}

	public function test_unschedule_on_confirm(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$this->assertCount( 1, $this->expiry_jobs( $ids[0] ) );
		$this->order( $oid )->update_status( 'processing' );
		$this->assertSame( array(), $this->expiry_jobs( $ids[0] ) );
	}

	public function test_unschedule_matches_exact_reservation_id(): void {
		$first = array();
		for ( $i = 0; $i < 12; $i++ ) { // Kimlik 1 ve 11/12: "reservation_id":1 öneki 11'i de eşleştirmemeli.
			[ , $ids ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '2026-11-17T07:00:00Z' ) + 86400 * ( $i % 4 ) + 1800 * intdiv( $i, 4 ) ) ) );
			$first[]   = $ids[0];
		}
		$this->assertContains( 1, $first );
		$this->assertContains( 11, $first );
		ReservationRepository::setStatus( 1, 'confirmed', 'paid' );
		$this->assertSame( array(), $this->expiry_jobs( 1 ) );
		$this->assertCount( 1, $this->expiry_jobs( 11 ), 'başka satırın işi kaldırılmadı' );
		$this->assertCount( 1, $this->expiry_jobs( 12 ) );
	}

	public function test_extend_hold_reschedules_job(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$until         = new \DateTimeImmutable( '2026-11-18T06:00:00Z' );
		$this->assertSame( 1, HoldScheduler::extend( $oid, $until ) );
		$this->assertSame( '2026-11-18 06:00:00', $this->row( $ids[0] )->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
		$jobs = $this->expiry_jobs( $ids[0] );
		$this->assertCount( 1, $jobs, 'eski iş kaldırıldı' );
		$this->assertSame( '2026-11-18 06:00:00', $jobs[0]['args']['hold_expires_at'] );
		$this->assertSame( $until->getTimestamp(), $jobs[0]['ts'] );
	}

	public function test_hold_refreshed_event_reschedules_job(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$until         = Clock::now()->modify( '+60 minutes' );
		ReservationRepository::refreshHold( $oid, $until );
		do_action( 'slotiva_hold_refreshed', $oid, $until, $ids );
		$jobs = $this->expiry_jobs( $ids[0] );
		$this->assertCount( 1, $jobs );
		$this->assertSame( $until->getTimestamp(), $jobs[0]['ts'] );
	}

	public function test_stale_expiry_job_noop_after_extend(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$old           = $this->row( $ids[0] );
		HoldScheduler::extend( $oid, new \DateTimeImmutable( '2026-11-18T06:00:00Z' ) );
		Clock::freeze( new \DateTimeImmutable( '2026-11-18T07:00:00Z' ) ); // İki bitişi de geçti.

		HoldScheduler::expire( $old->id, $old->sequence, (string) $old->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'held', $this->row( $ids[0] )->status, 'bayat iş (eski hold_expires_at) no-op' );

		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status, 'güncel iş uygulanır' );
	}

	public function test_stale_expiry_job_noop_after_reschedule(): void {
		[ , $ids ] = $this->booked_order( TestCardGateway::ID );
		$old       = $this->row( $ids[0] );
		$this->assertTrue( ReservationRepository::reschedule( $ids[0], new \DateTimeImmutable( '2026-11-17T08:00:00Z' ), false, false ) );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );

		HoldScheduler::expire( $old->id, $old->sequence, (string) $old->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 'held', $this->row( $ids[0] )->status, 'bayat sequence → no-op' );
		$jobs = $this->expiry_jobs( $ids[0] );
		$this->assertCount( 1, $jobs, 'saat değişince iş yeniden kuruldu' );
		$this->assertSame( $old->sequence + 1, $jobs[0]['args']['sequence'] );
	}

	public function test_sweeper_expires_missed_holds(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		as_unschedule_all_actions( HoldScheduler::EXPIRE_HOOK, array(), HoldScheduler::GROUP ); // İş kaçtı.
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		HoldScheduler::sweep();
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 'pending', $this->order( $oid )->get_status() );
	}

	public function test_sweeper_cancels_lazily_expired_bank_order(): void {
		[ $oid, $ids, $pid ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->rival( $pid ); // Başka alma tembel süre dolumuyla tutmayı düşürür (iş henüz koşmadı).
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 'on-hold', $this->order( $oid )->get_status() );

		HoldScheduler::sweep();
		$this->assertSame( 'cancelled', $this->order( $oid )->get_status(), 'son tarihi geçmiş havale siparişi iptal' );
	}

	public function test_sweeper_never_cancels_card_orders(): void {
		[ $oid, $ids, $pid ] = $this->booked_order( TestCardGateway::ID );
		$this->order( $oid )->update_status( 'failed' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-20T06:00:00Z' ) );
		$this->rival( $pid ); // Tembel süre dolumu.
		HoldScheduler::sweep();
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 'failed', $this->order( $oid )->get_status() );
	}

	public function test_expiry_of_paid_order_confirms_instead(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		// Dinleyici kaçırdı (ör. önceki istek hata verdi): ödeme kaydı var, satır hâlâ held.
		remove_action( 'woocommerce_order_status_changed', array( PaymentListener::class, 'onStatusChanged' ), 10 );
		$this->order( $oid )->update_status( 'processing' );
		PaymentListener::register();
		$this->assertSame( 'held', $this->row( $ids[0] )->status, 'kurgu: dinleyici devre dışıydı' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status, 'ödenmiş siparişin tutması düşürülmez' );
	}

	public function test_recurring_sweep_scheduled_once(): void {
		as_unschedule_all_actions( HoldScheduler::SWEEP_HOOK, array(), HoldScheduler::GROUP );
		delete_option( 'slotiva_sweep_checked' );
		HoldScheduler::ensureRecurring();
		delete_option( 'slotiva_sweep_checked' );
		HoldScheduler::ensureRecurring();
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => HoldScheduler::SWEEP_HOOK,
				'group'    => HoldScheduler::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		$this->assertCount( 1, $ids );
		$this->assertSame( 10, has_action( 'init', array( HoldScheduler::class, 'ensureRecurring' ) ) );
	}

	/**
	 * Siparişin değiştirilme tarihini 3 gün geri alır (WC temizliği `post_modified`/`date_updated_gmt`e bakar).
	 *
	 * @param int $id Sipariş.
	 */
	private function backdate( int $id ): void {
		global $wpdb;
		$old = gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $old,
				'post_modified_gmt' => $old,
			),
			array( 'ID' => $id )
		);
		$orders = $wpdb->prefix . 'wc_orders';
		if ( $orders === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders ) ) ) {
			$wpdb->update( $orders, array( 'date_updated_gmt' => $old ), array( 'id' => $id ) );
		}
		clean_post_cache( $id );
	}

	/**
	 * (Final review F8 / m-4) Süpürücü denetimi her `init`te Action Scheduler sorgusu yapmaz: saatte en çok bir kez
	 * (Retention/License ile aynı seçenek kalıbı).
	 */
	public function test_ensure_recurring_is_throttled(): void {
		as_unschedule_all_actions( HoldScheduler::SWEEP_HOOK, array(), HoldScheduler::GROUP );
		delete_option( 'slotiva_sweep_checked' );
		HoldScheduler::ensureRecurring();
		$this->assertNotFalse( as_next_scheduled_action( HoldScheduler::SWEEP_HOOK, array(), HoldScheduler::GROUP ) );
		$this->assertGreaterThan( 0, (int) get_option( 'slotiva_sweep_checked' ), 'denetim zamanı yazıldı' );

		$queries = 0;
		$count   = static function ( $q ) use ( &$queries ) {
			++$queries;
			return $q;
		};
		add_filter( 'query', $count );
		try {
			HoldScheduler::ensureRecurring();
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertSame( 0, $queries, 'saat dolmadan sorgu yok' );

		// Saat dolunca yeniden denetler; iş yoksa kurar.
		as_unschedule_all_actions( HoldScheduler::SWEEP_HOOK, array(), HoldScheduler::GROUP );
		update_option( 'slotiva_sweep_checked', time() - 3601 );
		HoldScheduler::ensureRecurring();
		$this->assertNotFalse( as_next_scheduled_action( HoldScheduler::SWEEP_HOOK, array(), HoldScheduler::GROUP ) );
	}
}
