<?php
/**
 * Yaşam döngüsü testleri için: siparişi ve tutmasını doğrudan kurar (Store API'den geçmeden), işleri ve olayları sayar.
 *
 * `CheckoutFixtures` ile birlikte kullanılır (takvim, donuk saat Pzt 2026-11-16 06:00Z, ağ geçitleri).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Lifecycle\HoldScheduler;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Support\Fresh;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.

trait LifecycleFixtures {

	/**
	 * `slotiva_reservation_status` olayları: [id, eski, yeni].
	 *
	 * @var list<array{0:int,1:string,2:string}>
	 */
	private array $status_events = array();

	/**
	 * `slotiva_conflict` çağrıları: [order_id, ids, reason].
	 *
	 * @var list<array{0:int,1:list<int>,2:string}>
	 */
	private array $conflicts = array();

	private function set_up_lifecycle(): void {
		$this->status_events = array();
		$this->conflicts     = array();
		add_action( 'slotiva_reservation_status', array( $this, 'record_status' ), 99, 3 );
		add_action( 'slotiva_conflict', array( $this, 'record_conflict' ), 10, 3 );
	}

	private function tear_down_lifecycle(): void {
		remove_action( 'slotiva_reservation_status', array( $this, 'record_status' ), 99 );
		remove_action( 'slotiva_conflict', array( $this, 'record_conflict' ), 10 );
	}

	/**
	 * Olay kaydedici.
	 *
	 * @param int    $id  Rezervasyon.
	 * @param string $old Eski durum.
	 * @param string $to  Yeni durum.
	 */
	public function record_status( $id, $old, $to ): void {
		$this->status_events[] = array( (int) $id, (string) $old, (string) $to );
	}

	/**
	 * Çakışma kaydedici.
	 *
	 * @param int       $order_id Sipariş.
	 * @param list<int> $ids      Rezervasyonlar.
	 * @param string    $reason   Neden.
	 */
	public function record_conflict( $order_id, $ids, $reason ): void {
		$this->conflicts[] = array( (int) $order_id, array_map( 'intval', (array) $ids ), (string) $reason );
	}

	/**
	 * Yeni duruma geçiş sayısı.
	 *
	 * @param string $to Durum.
	 */
	private function transitions_to( string $to ): int {
		return count( array_filter( $this->status_events, static fn( array $e ): bool => $e[2] === $to ) );
	}

	/**
	 * Randevulu kalemli sipariş + tutma. Store API sipariş kaynağı (`store-api`) taklit edilir.
	 *
	 * @param string      $method        Ödeme yöntemi.
	 * @param string      $status        Rezervasyon durumu (held|confirmed).
	 * @param string      $payment_state Ödeme durumu.
	 * @param string|null $hold          Tutma süresi (`+45 minutes`), null = yok.
	 * @param string[]    $slots         Dilimler (UTC ISO).
	 * @param string|null $session_hash  Oturum özeti.
	 * @return array{0:int,1:list<int>,2:int} [sipariş, rezervasyonlar, ürün]
	 */
	private function booked_order( string $method, string $status = 'held', string $payment_state = 'pending', ?string $hold = '+45 minutes', array $slots = array( '2026-11-17T07:00:00Z' ), ?string $session_hash = null ): array {
		$pid   = $this->bookable();
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$product = wc_get_product( $pid );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$order->add_product( $product, 1 );
		$order->set_created_via( 'store-api' );
		$order->set_payment_method( $method );
		$order->set_billing_email( 'ayse@example.test' );
		$order->set_billing_first_name( 'Ayşe' );
		$order->update_meta_data( CheckoutFlow::META_REQUESTED, $slots );
		$order->calculate_totals();
		$order->save();
		$ids = ReservationRepository::claim(
			array(
				'product_id'      => $pid,
				'order_id'        => $order->get_id(),
				'slots'           => array_map( static fn( string $s ): \DateTimeImmutable => new \DateTimeImmutable( $s ), $slots ),
				'duration'        => 30,
				'buffer'          => 0,
				'status'          => $status,
				'payment_state'   => $payment_state,
				'hold_expires_at' => null === $hold ? null : Clock::now()->modify( $hold ),
				'session_hash'    => $session_hash,
			)
		);
		return array( $order->get_id(), $ids, $pid );
	}

	/**
	 * Siparişsiz rakip (elle eklenmiş onaylı) randevu: saati doldurur.
	 *
	 * @param int    $pid  Ürün.
	 * @param string $slot Dilim.
	 */
	private function rival( int $pid, string $slot = '2026-11-17T07:00:00Z' ): int {
		return ReservationRepository::claim(
			array(
				'product_id'    => $pid,
				'slots'         => array( new \DateTimeImmutable( $slot ) ),
				'duration'      => 30,
				'status'        => 'confirmed',
				'payment_state' => 'manual',
			)
		)[0];
	}

	/**
	 * Taze sipariş (yoksa test düşer).
	 *
	 * @param int $id Sipariş.
	 */
	private function order( int $id ): \WC_Order {
		$order = Fresh::order( $id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		return $order;
	}

	/**
	 * Rezervasyon (yoksa test düşer).
	 *
	 * @param int $id Kimlik.
	 */
	private function row( int $id ): Reservation {
		$r = ReservationRepository::find( $id );
		$this->assertInstanceOf( Reservation::class, $r );
		return $r;
	}

	/**
	 * Bekleyen süre dolumu işlerinin argümanları (zaman damgasıyla).
	 *
	 * @param int|null $reservation_id Süzgeç (null = hepsi).
	 * @return list<array{args:array<string,mixed>,ts:int}>
	 */
	private function expiry_jobs( ?int $reservation_id = null ): array {
		$out = array();
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => HoldScheduler::EXPIRE_HOOK,
				'group'    => HoldScheduler::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		) as $action ) {
			$args = $action->get_args();
			if ( null !== $reservation_id && (int) ( $args['reservation_id'] ?? 0 ) !== $reservation_id ) {
				continue;
			}
			$date  = $action->get_schedule()->get_date();
			$out[] = array(
				'args' => $args,
				'ts'   => $date ? $date->getTimestamp() : 0,
			);
		}
		return $out;
	}

	/**
	 * Satırın şu anki argümanlarıyla süre dolumu işleyicisini doğrudan çağırır (Action Scheduler'ın saati Clock değildir).
	 *
	 * @param Reservation $r Satır (çağrı anındaki).
	 */
	private function run_expiry( Reservation $r ): void {
		HoldScheduler::expire( $r->id, $r->sequence, (string) $r->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
	}

	/**
	 * Siparişin sipariş notları (metin).
	 *
	 * @param int $order_id Sipariş.
	 * @return list<string>
	 */
	private function notes( int $order_id ): array {
		return array_values( array_map( static fn( $n ): string => (string) $n->content, wc_get_order_notes( array( 'order_id' => $order_id ) ) ) );
	}
}
