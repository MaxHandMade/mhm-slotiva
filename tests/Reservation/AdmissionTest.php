<?php
/**
 * Task 11: kabulün yazma yollarına bağlanması — alma, sipariş yeniden alma (K5), değiştirme, saat değiştirme ve reclaim
 * (K4 taşıma kabulü), koltuk dağıtımı, her koltukta tembel süre dolumu (K2), D5(a)/(b) geçiş durumları.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Reservation;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;
use MHM\Slotiva\Tests\Support\TestCardGateway;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test yardımcıları özel tabloları doğrudan okur; tablo adları Schema'dan.

class AdmissionTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;

	/**
	 * Ürün başına süzgeç kapasitesi (yoksa 1).
	 *
	 * @var array<int,int>
	 */
	private array $caps = array();

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		$this->set_up_lifecycle();
		$this->caps = array();
		add_filter( 'slotiva_product_capacity', array( $this, 'cap_filter' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'slotiva_product_capacity', array( $this, 'cap_filter' ), 10 );
		$this->tear_down_lifecycle();
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
	 * Alma isteği.
	 *
	 * @param int                 $pid  Ürün.
	 * @param array<string,mixed> $over Ezmeler.
	 * @return array<string,mixed>
	 */
	private function req( int $pid, array $over = array() ): array {
		return array_merge(
			array(
				'product_id'      => $pid,
				'slots'           => array( self::utc( '2026-11-17 07:00:00' ) ),
				'duration'        => 30,
				'status'          => 'held',
				'payment_state'   => 'pending',
				'hold_expires_at' => Clock::now()->modify( '+45 minutes' ),
				'customer'        => array(
					'name'  => 'Ayşe Test',
					'email' => 'ayse@example.test',
					'phone' => '5550000000',
				),
			),
			$over
		);
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
	 * Reddi bekler (1062 ile aynı kullanıcı yolu, K2).
	 *
	 * @param callable $call Çağrı.
	 */
	private function assertSlotTaken( callable $call ): void {
		try {
			$call();
			$this->fail( 'SlotTakenException bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			$this->assertNotEmpty( $e->taken );
		}
	}

	public function test_group_claims_fill_seats_then_refuse(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$ids                = array();
		foreach ( array( 901, 902, 903 ) as $order ) {
			$ids[] = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => $order ) ) )[0];
		}
		$this->assertSame( array( 1, 2, 3 ), array_map( array( $this, 'seat_of' ), $ids ) );
		$this->assertSame( array( 3, 3, 3 ), array_map( array( $this, 'capacity_of' ), $ids ) );
		$this->assertSlotTaken( fn() => ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 904 ) ) ) );
		$this->assertCount( 3, ReservationRepository::upcomingActive() );
	}

	public function test_lite_default_stamps_capacity_one_and_refuses_second(): void {
		$pid = $this->bookable();
		$a   = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 901 ) ) )[0];
		$this->assertSame( 1, $this->capacity_of( $a ) );
		$this->assertSame( 1, $this->seat_of( $a ) );
		$this->assertSlotTaken( fn() => ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 902 ) ) ) );
	}

	public function test_same_customer_two_orders_get_two_seats(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$a                  = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 901 ) ) )[0];
		$b                  = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 902 ) ) )[0];
		// K7: kimlik denetimi yok; her sipariş ayrı koltuk.
		$this->assertSame( array( 1, 2 ), array( $this->seat_of( $a ), $this->seat_of( $b ) ) );
	}

	public function test_reschedule_member_off_group_start_is_refused(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$a                  = ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed' ) ) )[0];
		ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed' ) ) );
		// 2/3 dolu 10:00 grubundan bir üye 10:15'e: hedefte başka satır var ve başlangıç farklı → ret.
		$this->assertSlotTaken( fn() => ReservationRepository::reschedule( $a, self::utc( '2026-11-17 07:15:00' ), true, false ) );
		$this->assertSame( '2026-11-17 07:00:00', ReservationRepository::find( $a )?->startsAt->format( 'Y-m-d H:i:s' ) );
	}

	public function test_reschedule_lone_group_member_overlapping_own_units_is_allowed(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$a                  = ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed' ) ) )[0];
		$this->assertTrue( ReservationRepository::reschedule( $a, self::utc( '2026-11-17 07:15:00' ), true, false ) );
		$this->assertSame( 3, $this->capacity_of( $a ), 'taşıma yeniden damgalamaz' );
	}

	public function test_capacity_one_row_moves_only_into_empty_time(): void {
		$pid    = $this->bookable();
		$single = ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed', 'slots' => array( self::utc( '2026-11-17 08:00:00' ) ) ) ) )[0]; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		$this->assertSame( 1, $this->capacity_of( $single ) );
		$this->caps[ $pid ] = 3;
		$member             = ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed' ) ) )[0];
		$this->assertSame( 3, $this->capacity_of( $member ) );

		// 1/3 dolu gruba capacity=1 satır taşınamaz (Fable delta-3).
		$this->assertSlotTaken( fn() => ReservationRepository::reschedule( $single, self::utc( '2026-11-17 07:00:00' ), true, false ) );
		// Aynı gruba capacity=3 satır taşınır (yeni koltuk).
		$other = ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed', 'slots' => array( self::utc( '2026-11-17 08:30:00' ) ) ) ) )[0]; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		$this->assertTrue( ReservationRepository::reschedule( $other, self::utc( '2026-11-17 07:00:00' ), true, false ) );
		$this->assertSame( 2, $this->seat_of( $other ) );
		// capacity=1 satır boş saate taşınır.
		$this->assertTrue( ReservationRepository::reschedule( $single, self::utc( '2026-11-17 08:30:00' ), true, false ) );
	}

	public function test_order_pay_reclaim_of_same_start_keeps_seat_after_capacity_drop(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed' ) ) );
		$mine = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 951 ) ) )[0];
		ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed' ) ) );
		$seat               = $this->seat_of( $mine );
		$this->caps[ $pid ] = 1;

		// Ödeme durumu değişti (yeniden kullanılamaz) → satırlar yeniden yazılır; K5: kendi koltuğu, "dolu" yok.
		$out = ReservationRepository::claimForOrderPay( 951, $this->req( $pid, array( 'status' => 'confirmed', 'payment_state' => 'on_site' ) ), static fn( array $s ): array => $s ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		$this->assertFalse( $out['reused'] );
		$new = $out['ids'][0];
		$this->assertNotSame( $mine, $new );
		$this->assertSame( 'superseded', ReservationRepository::find( $mine )?->status );
		$this->assertSame( $seat, $this->seat_of( $new ), 'K5: koltuk korunur' );
		$this->assertSame( 3, $this->capacity_of( $new ), 'K4: yeniden ekleme mevcut kapasiteyi taşır' );
	}

	public function test_order_pay_reclaim_with_grown_buffer_checks_new_units(): void {
		$pid                  = $this->bookable();
		$other                = $this->bookable();
		$this->caps[ $pid ]   = 3;
		$this->caps[ $other ] = 3;
		$mine                 = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 981 ) ) )[0];
		// Başka ürün 10:30'u alır; ara 0 → 15 olunca siparişin yeni aralığı bu birime uzanır.
		ReservationRepository::claim( $this->req( $other, array( 'status' => 'confirmed', 'slots' => array( self::utc( '2026-11-17 07:30:00' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.

		// K5 muafiyeti yalnız kendi tuttuğu birimler içindir: yeni birim K3'ten geçer → ret (tek birimde iki sahip olmaz).
		$this->assertSlotTaken( fn() => ReservationRepository::claimForOrderPay( 981, $this->req( $pid, array( 'status' => 'confirmed', 'payment_state' => 'on_site', 'buffer' => 15 ) ), static fn( array $s ): array => $s ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		$this->assertSame( 'held', ReservationRepository::find( $mine )?->status, 'eski satır yerinde' );
		$this->assertSame( 1, $this->seat_of( $mine ) );
	}

	public function test_order_pay_reclaim_with_grown_buffer_into_empty_unit_keeps_seat(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed' ) ) );
		$mine = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 982 ) ) )[0];
		$this->assertSame( 2, $this->seat_of( $mine ) );

		$out = ReservationRepository::claimForOrderPay( 982, $this->req( $pid, array( 'status' => 'confirmed', 'payment_state' => 'on_site', 'buffer' => 15 ) ), static fn( array $s ): array => $s ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- kısa test girdisi.
		$this->assertSame( 2, $this->seat_of( $out['ids'][0] ), 'boş yeni birimde de kendi koltuğu' );
	}

	public function test_replace_for_order_to_new_start_carries_capacity(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$old                = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 961 ) ) )[0];
		$this->caps[ $pid ] = 2;
		$new                = ReservationRepository::replaceForOrder( 961, $this->req( $pid, array( 'slots' => array( self::utc( '2026-11-17 08:00:00' ) ) ) ) )[0];
		$this->assertSame( 'superseded', ReservationRepository::find( $old )?->status );
		$this->assertSame( 3, $this->capacity_of( $new ), 'yeniden damgalanmaz' );
	}

	public function test_expired_hold_on_any_seat_is_lazily_released(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$a                  = ReservationRepository::claim( $this->req( $pid, array( 'status' => 'confirmed' ) ) )[0];
		$b                  = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 971 ) ) )[0];
		$c                  = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 972 ) ) )[0];
		$this->assertSame( array( 1, 2, 3 ), array( $this->seat_of( $a ), $this->seat_of( $b ), $this->seat_of( $c ) ) );
		Clock::freeze( Clock::now()->modify( '+2 hours' ) ); // İki tutma doldu; süpürücü koşmadı.

		$d = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => 973 ) ) )[0];
		$this->assertSame( 2, $this->seat_of( $d ), 'süresi geçmiş tutmanın koltuğu alındı' );
		$this->assertSame( 'expired', ReservationRepository::find( $b )?->status, '1062 yolu: seçilen koltuk' );
		$this->assertSame( 'expired', ReservationRepository::find( $c )?->status, 'süpürme: diğer koltuk' );
		$this->assertSame( 'confirmed', ReservationRepository::find( $a )?->status );
	}

	public function test_hold_converts_on_payment_with_its_stamped_seat(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		$this->rival( $pid );
		[ $oid, $ids ] = $this->booked_order_for( $pid );
		$seat          = $this->seat_of( $ids[0] );
		$this->assertSame( 2, $seat );
		$this->caps[ $pid ] = 1; // D5(a): kapasite düştü; tutma zaten koltuğunu tutuyor.

		$this->order( $oid )->update_status( 'processing' );

		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status );
		$this->assertSame( $seat, $this->seat_of( $ids[0] ) );
		$this->assertSame( 3, $this->capacity_of( $ids[0] ) );
		$this->assertSame( array(), $this->conflicts );
	}

	public function test_late_payment_reclaim_into_full_group_becomes_conflict(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		[ $oid, $ids ]      = $this->booked_order_for( $pid );
		$this->assertSame( 3, $this->capacity_of( $ids[0] ) );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$rival              = $this->rival( $pid ); // Bırakılan koltuk başkasına geçti.
		$this->caps[ $pid ] = 1;                    // D5(b): kapasite şimdi 1.

		$this->order( $oid )->update_status( 'processing' );

		$r = $this->row( $ids[0] );
		$this->assertSame( 'conflict', $r->status );
		$this->assertSame( 'paid', $r->paymentState );
		$this->assertSame( 'confirmed', $this->row( $rival )->status );
		$this->assertCount( 1, $this->conflicts, 'mağazaya çakışma e-postası (slotiva_conflict)' );
		$this->assertSame( array( $oid, array( $ids[0] ), 'late_paid_taken' ), $this->conflicts[0] );
		$this->assertNotEmpty( preg_grep( '/taken/i', $this->notes( $oid ) ), 'kalıcı sipariş notu' );
	}

	public function test_late_payment_reclaim_joins_group_when_seat_left(): void {
		$pid                = $this->bookable();
		$this->caps[ $pid ] = 3;
		[ $oid, $ids ]      = $this->booked_order_for( $pid );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->rival( $pid );

		$this->order( $oid )->update_status( 'processing' );

		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status );
		$this->assertSame( 2, $this->seat_of( $ids[0] ) );
		$this->assertSame( array(), $this->conflicts );
	}

	/**
	 * `booked_order` ile aynı, ama verilen ürünle (kapasite süzgeci ürün kimliğine bağlı).
	 *
	 * @param int $pid Ürün.
	 * @return array{0:int,1:list<int>}
	 */
	private function booked_order_for( int $pid ): array {
		$order   = wc_create_order();
		$product = wc_get_product( $pid );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$order->add_product( $product, 1 );
		$order->set_created_via( 'store-api' );
		$order->set_payment_method( TestCardGateway::ID );
		$order->set_billing_email( 'ayse@example.test' );
		$order->update_meta_data( \MHM\Slotiva\Checkout\CheckoutFlow::META_REQUESTED, array( '2026-11-17T07:00:00Z' ) );
		$order->calculate_totals();
		$order->save();
		$ids = ReservationRepository::claim( $this->req( $pid, array( 'order_id' => $order->get_id() ) ) );
		return array( $order->get_id(), $ids );
	}
}
