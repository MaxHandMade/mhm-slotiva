<?php
/**
 * Store API ödeme akışı: şema → yakalama → doğrulama → atomik alma (spec §6.3, §6.4, §15).
 *
 * İstekler `rest_do_request` ile gerçek Store API rotalarından geçer (WC 11.2.1 `Checkout` / `CheckoutOrder`).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Checkout;

use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Support\Fresh;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;
use MHM\Slotiva\Tests\Support\TestCardGateway;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation/BookableProduct alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.DB.RestrictedFunctions -- bağımsız ikinci bağlantı (mysqli) yalnız test için.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- klasik ödeme testi $_POST'u kendisi kurar; WC nonce'u kendisi doğrular.

class CheckoutFlowTest extends SlotivaTestCase {

	use CheckoutFixtures;

	private const T10 = '2026-11-17T07:00:00Z'; // Salı 10:00 İstanbul.
	private const T11 = '2026-11-17T08:00:00Z'; // Salı 11:00.

	/**
	 * Test öncesi ağ geçidi nesneleri (1.0.4 D6: testler birbirine ağ geçidi sızdırmaz).
	 *
	 * @var array<int|string,mixed>
	 */
	private array $gateways_before = array();

	public function set_up(): void {
		parent::set_up();
		$this->gateways_before = WC()->payment_gateways()->payment_gateways;
		$this->set_up_checkout();
	}

	public function tear_down(): void {
		try {
			$this->tear_down_checkout();
			$this->assert_gateways_clean();
		} finally {
			$this->restore_gateways();
			parent::tear_down();
		}
	}

	/**
	 * Söküm sonrası test ağ geçidi süzgeçleri kalkmış mı. Kümeyi önceki kümeyle karşılaştırmak olmaz: WC `init()` önceki diziyi
	 * temizlemeden doldurur, bu yüzden söküm sonrası da test geçitleri nesne olarak kalır; `restore_gateways` bunu düzeltir.
	 */
	private function assert_gateways_clean(): void {
		$this->assertFalse( has_filter( 'woocommerce_payment_gateways', array( $this, 'add_test_gateway' ) ), 'test ağ geçidi süzgeci sökümde kalktı' );
	}

	/**
	 * Ağ geçidi nesnelerini test öncesine döndürür (sonraki testler aynı nesneleri görsün).
	 */
	private function restore_gateways(): void {
		WC()->payment_gateways()->payment_gateways = $this->gateways_before;
	}

	private function cart( int $product_id, int $qty = 1 ): string {
		$key = WC()->cart->add_to_cart( $product_id, $qty );
		$this->assertIsString( $key );
		return $key;
	}

	/**
	 * Başarılı ödeme yanıtından sipariş kimliği.
	 *
	 * @param \WP_REST_Response $res Yanıt.
	 */
	private function order_id( \WP_REST_Response $res ): int {
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		return (int) $res->get_data()['order_id'];
	}

	/**
	 * Siparişin rezervasyonları.
	 *
	 * @param int  $order_id Sipariş.
	 * @param bool $active   Yalnız etkin.
	 * @return list<Reservation>
	 */
	private function res( int $order_id, bool $active = false ): array {
		return ReservationRepository::forOrder( $order_id, $active );
	}

	/**
	 * Bekleyen kart siparişi (varsayılan 10:00).
	 *
	 * @param string[] $slots Dilimler.
	 */
	private function card_order( array $slots = array( self::T10 ) ): int {
		return $this->order_id( $this->checkout( $slots, TestCardGateway::ID ) );
	}

	/**
	 * "Kasım/Salı" çevirisi: test ortamında tr_TR dil paketi yok; WP_Locale ay/gün adlarını gettext'ten okur.
	 *
	 * @param callable():void $work Çalıştırılacak iş.
	 */
	private function in_turkish( callable $work ): void {
		$map                  = array(
			'November' => 'Kasım',
			'Tuesday'  => 'Salı',
		);
		$filter               = static fn( $t, $text ) => $map[ $text ] ?? $t;
		add_filter( 'gettext', $filter, 10, 2 );
		$GLOBALS['wp_locale'] = new \WP_Locale(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test yerel ayarı.
		try {
			$work();
		} finally {
			remove_filter( 'gettext', $filter, 10 );
			$GLOBALS['wp_locale'] = new \WP_Locale(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_checkout_without_slots_rejected(): void {
		$this->cart( $this->bookable() );
		$res = $this->checkout( null );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_no_slots', self::code( $res ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );

		// Boş dizi de aynı.
		$res = $this->checkout( array() );
		$this->assertSame( 'slotiva_no_slots', self::code( $res ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	public function test_checkout_claims_and_snapshots(): void {
		$pid = $this->bookable();
		$this->cart( $pid );
		$this->in_turkish(
			function () use ( $pid ) {
				$oid   = $this->order_id( $this->checkout( array( self::T10 ) ) );
				$order = Fresh::order( $oid );
				$this->assertNotNull( $order );
				$this->assertSame( array( self::T10 ), $order->get_meta( CheckoutFlow::META_REQUESTED ) );
				$this->assertSame( '17 Kasım Salı, 10:00', $order->get_meta( CheckoutFlow::META_LABEL ) );
				$this->assertSame( 30, (int) $order->get_meta( CheckoutFlow::META_DURATION ) );
				$this->assertSame( 'Atölye, Kadıköy', $order->get_meta( CheckoutFlow::META_LOCATION ) );
				$items = array_values( $order->get_items() );
				$this->assertSame( '17 Kasım Salı, 10:00', $items[0]->get_meta( CheckoutFlow::META_LABEL ) );
				$this->assertSame( 30, (int) $items[0]->get_meta( CheckoutFlow::META_DURATION ) );
				$this->assertSame( 'Atölye, Kadıköy', $items[0]->get_meta( CheckoutFlow::META_LOCATION ) );

				$rows = $this->res( $oid );
				$this->assertCount( 1, $rows );
				$r = $rows[0];
				$this->assertSame( $pid, $r->productId );
				$this->assertSame( $items[0]->get_id(), $r->orderItemId );
				$this->assertSame( self::T10, $r->startsAt->format( 'Y-m-d\TH:i:s\Z' ) );
				$this->assertSame( '2026-11-17 07:30:00', $r->endsAt->format( 'Y-m-d H:i:s' ) );
				$this->assertSame( 'Danışmanlık', $r->productName );
				$this->assertSame( 'Atölye, Kadıköy', $r->locationText );
				$this->assertSame( md5( '203.0.113.7' ), $r->ipHash );
				$this->assertSame( 2, self::rows( 'occupancy' ) );
			}
		);
	}

	public function test_guest_snapshot_from_billing(): void {
		$this->cart( $this->bookable() );
		$this->assertSame( 0, get_current_user_id() );
		$oid = $this->order_id( $this->checkout( array( self::T10 ) ) );
		$r   = $this->res( $oid )[0];
		$this->assertSame( 'Ayşe Yılmaz', $r->customerName );
		$this->assertSame( 'ayse@example.test', $r->customerEmail );
		$this->assertSame( '05551112233', $r->customerPhone );
	}

	public function test_conflict_returns_409_and_no_reservation(): void {
		$pid = $this->bookable();
		ReservationRepository::claim(
			array(
				'product_id' => $pid,
				'slots'      => array( new \DateTimeImmutable( self::T10 ) ),
				'duration'   => 30,
				'status'     => 'confirmed',
			)
		);
		$this->cart( $pid );
		$res = $this->checkout( array( self::T10 ), TestCardGateway::ID );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'slotiva_slot_taken', self::code( $res ) );
		$this->assertSame( array( self::T10 ), $res->get_data()['data']['taken'] );
		$this->assertSame( 1, self::rows( 'reservations' ) ); // Yalnız önceden var olan.

		// Sipariş `pending` ve rezervasyonsuz kalır; yeni dilimle yeniden gönderim aynı siparişi kullanır (§6.3).
		$draft = (int) WC()->session->get( 'store_api_draft_order' );
		$this->assertGreaterThan( 0, $draft );
		$this->assertSame( array(), $this->res( $draft ) );
		$oid = $this->order_id( $this->checkout( array( self::T11 ), TestCardGateway::ID ) );
		$this->assertSame( $draft, $oid );
		$this->assertCount( 1, $this->res( $oid, true ) );
	}

	public function test_stale_taken_slot_returns_409_not_400(): void {
		$pid = $this->bookable();
		$this->cart( $pid );
		// Başka müşteri aynı saati tutuyor (açık kalmış eski sayfa senaryosu 3).
		ReservationRepository::claim(
			array(
				'product_id'      => $pid,
				'order_id'        => 999001,
				'slots'           => array( new \DateTimeImmutable( self::T10 ) ),
				'duration'        => 30,
				'status'          => 'held',
				'hold_expires_at' => Clock::now()->modify( '+45 minutes' ),
			)
		);
		$res = $this->checkout( array( self::T10 ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'slotiva_slot_taken', self::code( $res ) );
	}

	public function test_same_order_resubmit_reuses_hold(): void {
		$this->cart( $this->bookable() );
		$oid    = $this->card_order();
		$before = $this->res( $oid );
		$this->assertCount( 1, $before );
		$this->assertSame( '2026-11-16 06:45:00', $before[0]->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );

		$refreshed = array();
		$spy       = static function ( $order_id ) use ( &$refreshed ) {
			$refreshed[] = $order_id;
		};
		add_action( 'slotiva_hold_refreshed', $spy );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:10:00Z' ) );
		$again = $this->card_order();
		remove_action( 'slotiva_hold_refreshed', $spy );

		$this->assertSame( $oid, $again, 'WC aynı bekleyen siparişi yeniden kullanmalı' );
		$after = $this->res( $oid );
		$this->assertCount( 1, $after, 'yeni satır yazılmamalı' );
		$this->assertSame( $before[0]->id, $after[0]->id );
		$this->assertSame( 'held', $after[0]->status );
		$this->assertSame( '2026-11-16 06:55:00', $after[0]->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( array( $oid ), $refreshed );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
	}

	public function test_double_click_same_instant_reuses_hold(): void {
		$this->cart( $this->bookable() );
		$oid   = $this->card_order();
		$first = $this->res( $oid )[0];
		// Saat ilerlemez: yeni bitiş eskisiyle aynı, UPDATE 0 satır etkiler; yine de aynı tutma kullanılmalı.
		$this->assertSame( $oid, $this->card_order() );
		$rows = $this->res( $oid );
		$this->assertCount( 1, $rows );
		$this->assertSame( $first->id, $rows[0]->id );
	}

	public function test_resubmit_different_slots_moves_hold(): void {
		$this->cart( $this->bookable() );
		$oid   = $this->card_order();
		$again = $this->card_order( array( self::T11 ) );
		$this->assertSame( $oid, $again );
		$rows = $this->res( $oid );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'superseded', $rows[0]->status );
		$this->assertSame( 'held', $rows[1]->status );
		$this->assertSame( self::T11, $rows[1]->startsAt->format( 'Y-m-d\TH:i:s\Z' ) );
		$this->assertSame( 2, self::rows( 'occupancy' ), 'yalnız yeni dilimin birimleri' );
		$this->assertSame( array( self::T11 ), Fresh::order( $oid )?->get_meta( CheckoutFlow::META_REQUESTED ) );
	}

	public function test_per_slot_qty_mismatch_rejected(): void {
		$this->cart(
			$this->bookable(
				array(
					'_slotiva_pricing'   => 'per_slot',
					'_slotiva_max_slots' => '5',
				)
			),
			1
		);
		$res = $this->checkout( array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z', '2026-11-17T08:00:00Z' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_qty_mismatch', self::code( $res ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	public function test_per_slot_three_slots_quantity_three(): void {
		$this->cart(
			$this->bookable(
				array(
					'_slotiva_pricing'   => 'per_slot',
					'_slotiva_max_slots' => '5',
				)
			),
			3
		);
		$oid = $this->order_id( $this->checkout( array( '2026-11-17T08:00:00Z', '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ) ) );
		$this->assertCount( 3, $this->res( $oid, true ) );
		$this->assertSame( 6, self::rows( 'occupancy' ) );
	}

	public function test_fixed_qty_is_one(): void {
		$key = $this->cart( $this->bookable() );
		WC()->cart->set_quantity( $key, 2 );
		$res = $this->checkout( array( self::T10 ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_qty_mismatch', self::code( $res ) );
	}

	public function test_slot_outside_horizon_rejected(): void {
		$this->cart( $this->bookable() );
		$res = $this->checkout( array( '2026-12-15T07:00:00Z' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_not_bookable', self::code( $res ) );
	}

	public function test_offgrid_slot_rejected(): void {
		$this->cart( $this->bookable() );
		$res = $this->checkout( array( '2026-11-17T07:10:00Z' ) ); // 10:10.
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_not_bookable', self::code( $res ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	public function test_cross_product_slot_rejected(): void {
		// 45 dk ürünün 10:45 dilimi 30 dk ürünün ızgarasında yok.
		$this->bookable( array( '_slotiva_duration' => '45' ) );
		$this->cart( $this->bookable() );
		$res = $this->checkout( array( '2026-11-17T07:45:00Z' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_not_bookable', self::code( $res ) );
	}

	public function test_overlapping_slots_rejected(): void {
		\MHM\Slotiva\Schedule\Rules::save( array( 'bufferMin' => 15 ) );
		$this->cart(
			$this->bookable(
				array(
					'_slotiva_pricing'   => 'per_slot',
					'_slotiva_max_slots' => '3',
				)
			),
			2
		);
		$res = $this->checkout( array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_overlap', self::code( $res ) );
		$res = $this->checkout( array( self::T10, self::T10 ) );
		$this->assertSame( 'slotiva_duplicate', self::code( $res ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	public function test_too_many_slots_rejected_by_schema(): void {
		$this->cart( $this->bookable() );
		$res = $this->checkout( array_fill( 0, 11, self::T10 ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	public function test_client_duration_ignored(): void {
		$this->cart( $this->bookable() );
		$oid = $this->order_id( $this->checkout( array( self::T10 ), 'bacs', array( 'duration' => 120 ) ) );
		$r   = $this->res( $oid )[0];
		$this->assertSame( '2026-11-17 07:30:00', $r->endsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
	}

	public function test_cod_confirmed_on_site(): void {
		$this->cart( $this->bookable() );
		$oid = $this->order_id( $this->checkout( array( self::T10 ), 'cod' ) );
		$r   = $this->res( $oid )[0];
		$this->assertSame( 'confirmed', $r->status );
		$this->assertSame( 'on_site', $r->paymentState );
		$this->assertNull( $r->holdExpiresAt );
		$this->assertSame( md5( (string) WC()->session->get_customer_id() ), $r->sessionHash, 'her yöntem oturuma bağlı (§15.3)' );
	}

	public function test_bacs_held_awaiting_transfer_24h(): void {
		$this->cart( $this->bookable() );
		$oid = $this->order_id( $this->checkout( array( self::T10 ), 'bacs' ) );
		$r   = $this->res( $oid )[0];
		$this->assertSame( 'held', $r->status );
		$this->assertSame( 'awaiting_transfer', $r->paymentState );
		$this->assertSame( '2026-11-17 06:00:00', $r->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( md5( (string) WC()->session->get_customer_id() ), $r->sessionHash, 'havale oturum kotasına sayılır (§15.8)' );
		$this->assertSame( 'on-hold', Fresh::order( $oid )?->get_status() );
	}

	public function test_card_held_45min(): void {
		$this->cart( $this->bookable() );
		$oid = $this->card_order();
		$r   = $this->res( $oid )[0];
		$this->assertSame( 'held', $r->status );
		$this->assertSame( 'pending', $r->paymentState );
		$this->assertSame( '2026-11-16 06:45:00', $r->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( md5( (string) WC()->session->get_customer_id() ), $r->sessionHash );
		$this->assertSame( 'pending', Fresh::order( $oid )?->get_status() );
	}

	public function test_card_hold_follows_paytr_timeout(): void {
		$this->assertSame( 45, CheckoutFlow::cardHoldMinutes() );
		update_option( 'woocommerce_paytr_payment_gateway_settings', array( 'timeout_limit' => '30' ) );
		$this->assertSame( 45, CheckoutFlow::cardHoldMinutes() );
		update_option( 'woocommerce_paytr_payment_gateway_settings', array( 'timeout_limit' => '60' ) );
		$this->assertSame( 75, CheckoutFlow::cardHoldMinutes() );
		update_option( 'woocommerce_paytr_payment_gateway_settings', array( 'timeout_limit' => 'abc' ) );
		$this->assertSame( 45, CheckoutFlow::cardHoldMinutes() );
	}

	public function test_zero_total_order_confirms(): void {
		$this->cart( $this->bookable( array(), '0' ) );
		$oid = $this->order_id( $this->checkout( array( self::T10 ), TestCardGateway::ID ) );
		$r   = $this->res( $oid )[0];
		$this->assertSame( 'confirmed', $r->status );
		$this->assertSame( 'paid', $r->paymentState );
		$this->assertNull( $r->holdExpiresAt );
	}

	public function test_card_to_cod_retry_confirms(): void {
		$this->cart( $this->bookable() );
		$oid = $this->card_order();
		$this->assertSame( 'held', $this->res( $oid )[0]->status );

		$again = $this->order_id( $this->checkout( array( self::T10 ), 'cod' ) );
		$this->assertSame( $oid, $again );
		$active = $this->res( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( 'confirmed', $active[0]->status );
		$this->assertSame( 'on_site', $active[0]->paymentState );
		$this->assertNull( $active[0]->holdExpiresAt, 'tutma süresi silinir' );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
		$this->assertSame( 'superseded', $this->res( $oid )[0]->status, 'eski kart satırı (ve onun süre işi) geçersiz' );
	}

	public function test_bank_to_card_retry_resets_hold(): void {
		$this->cart( $this->bookable() );
		$oid   = $this->order_id( $this->checkout( array( self::T10 ), 'bacs' ) );
		$order = Fresh::order( $oid );
		$this->assertNotNull( $order );
		// Havale siparişi yönetici tarafından yeniden ödemeye açıldı (on-hold → pending); müşteri kartla tekrar gönderir.
		$order->update_status( 'pending' );
		WC()->session->set( 'store_api_draft_order', $oid );
		// Havale başarısı sepeti boşaltır; aynı sepet (aynı özet) yeniden kurulur ki WC siparişi yeniden kullansın.
		$this->cart( $this->res( $oid )[0]->productId );

		$again = $this->card_order();
		$this->assertSame( $oid, $again );
		$active = $this->res( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( 'held', $active[0]->status );
		$this->assertSame( 'pending', $active[0]->paymentState );
		$this->assertSame( '2026-11-16 06:45:00', $active[0]->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_bacs_hold_survives_later_card_checkout_in_same_session(): void {
		$this->cart( $this->bookable() );
		$bacs = $this->order_id( $this->checkout( array( self::T10 ), 'bacs' ) );
		WC()->cart->empty_cart();
		$this->cart( $this->bookable() );
		$card = $this->card_order( array( self::T11 ) );
		$this->assertNotSame( $bacs, $card );
		$this->assertSame( 'held', $this->res( $bacs )[0]->status, 'havale siparişi oturum taslağı değildir' );
		$this->assertSame( 'held', $this->res( $card )[0]->status );
	}

	public function test_cod_booking_survives_later_card_checkout_in_same_session(): void {
		$this->cart( $this->bookable() );
		$cod = $this->order_id( $this->checkout( array( self::T10 ), 'cod' ) );
		WC()->cart->empty_cart();
		$this->cart( $this->bookable() );
		$card = $this->card_order( array( self::T11 ) );
		$this->assertNotSame( $cod, $card );
		$this->assertSame( 'confirmed', $this->res( $cod )[0]->status );
		$this->assertSame( 'held', $this->res( $card )[0]->status );
	}

	public function test_session_supersede_spares_bank_and_confirmed_rows(): void {
		$hash = md5( 'oturum-x' );
		$base = array(
			'product_id'   => $this->bookable(),
			'duration'     => 30,
			'session_hash' => $hash,
		);
		$bank = ReservationRepository::claim(
			$base + array(
				'order_id'        => 999202,
				'slots'           => array( new \DateTimeImmutable( self::T11 ) ),
				'status'          => 'held',
				'payment_state'   => 'awaiting_transfer',
				'hold_expires_at' => Clock::now()->modify( '+24 hours' ),
			)
		);
		$cod  = ReservationRepository::claim(
			$base + array(
				'order_id'      => 999203,
				'slots'         => array( new \DateTimeImmutable( '2026-11-18T07:00:00Z' ) ),
				'status'        => 'confirmed',
				'payment_state' => 'on_site',
			)
		);
		// Havale ve onaylı alımlar (oturum supersede'i kendi işlemlerinde koşar) birbirini bozmaz; kart taslağı en son.
		$card = ReservationRepository::claim(
			$base + array(
				'order_id'        => 999201,
				'slots'           => array( new \DateTimeImmutable( self::T10 ) ),
				'status'          => 'held',
				'payment_state'   => 'pending',
				'hold_expires_at' => Clock::now()->modify( '+45 minutes' ),
			)
		);
		$this->assertSame( 1, ReservationRepository::supersedeForSession( $hash, 0 ) );
		$this->assertSame( 'superseded', ReservationRepository::find( $card[0] )?->status );
		$this->assertSame( 'held', ReservationRepository::find( $bank[0] )?->status );
		$this->assertSame( 'confirmed', ReservationRepository::find( $cod[0] )?->status );
	}

	public function test_non_card_claim_conflict_keeps_old_card_draft(): void {
		$this->cart( $this->bookable() );
		$draft = $this->card_order();
		ReservationRepository::claim(
			array(
				'product_id' => $this->bookable(),
				'slots'      => array( new \DateTimeImmutable( self::T11 ) ),
				'duration'   => 30,
				'status'     => 'confirmed',
			)
		);
		WC()->cart->empty_cart();
		$this->cart( $this->bookable() );
		$res = $this->checkout( array( self::T11 ), 'cod' );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'slotiva_slot_taken', self::code( $res ) );
		$this->assertSame( 'held', $this->res( $draft )[0]->status, 'supersede alma ile aynı işlemde geri alındı' );
		$this->assertSame( 2 + 2, self::rows( 'occupancy' ) );
	}

	public function test_bank_holds_count_toward_session_quota(): void {
		// Her sipariş farklı IP'den: IP kotası boş kalır, sınırı yalnız oturum kotası koyabilir.
		foreach ( array(
			'203.0.113.11' => self::T10,
			'203.0.113.12' => self::T11,
		) as $ip => $slot ) {
			$_SERVER['REMOTE_ADDR'] = $ip;
			WC()->cart->empty_cart();
			$this->cart( $this->bookable() );
			$oid = $this->order_id( $this->checkout( array( $slot ), 'bacs' ) );
			$this->assertSame( 'held', $this->res( $oid )[0]->status );
		}
		$_SERVER['REMOTE_ADDR'] = '203.0.113.13';
		WC()->cart->empty_cart();
		$this->cart( $this->bookable() );
		$res = $this->checkout( array( '2026-11-18T07:00:00Z' ), 'bacs' );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'slotiva_hold_quota', self::code( $res ) );
	}

	public function test_put_payment_method_change_keeps_stored_slots(): void {
		$pid = $this->bookable();
		ReservationRepository::claim(
			array(
				'product_id' => $pid,
				'slots'      => array( new \DateTimeImmutable( self::T10 ) ),
				'duration'   => 30,
				'status'     => 'confirmed',
			)
		);
		$this->cart( $pid );
		$this->assertSame( 409, $this->checkout( array( self::T10 ), TestCardGateway::ID )->get_status() );
		$draft = (int) WC()->session->get( 'store_api_draft_order' );
		$this->assertSame( array( self::T10 ), Fresh::order( $draft )?->get_meta( CheckoutFlow::META_REQUESTED ) );

		// Ödeme bloğu yöntem değişince taslağa uzantısız PUT gönderir.
		$res = $this->store( 'PUT', '/wc/store/v1/checkout', array( 'payment_method' => 'cod' ) );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( array( self::T10 ), Fresh::order( $draft )?->get_meta( CheckoutFlow::META_REQUESTED ) );
		$this->assertSame( array(), $this->res( $draft ) );
	}

	public function test_new_order_supersedes_previous_card_draft_of_session(): void {
		$this->cart( $this->bookable() );
		$first = $this->card_order();
		WC()->cart->empty_cart();
		$this->cart( $this->bookable() ); // Farklı ürün → sepet özeti değişir → yeni sipariş.
		$second = $this->card_order();
		$this->assertNotSame( $first, $second );
		$this->assertSame( 'superseded', $this->res( $first )[0]->status, 'aynı saat yeni siparişte alınabildi' );
		$this->assertSame( 'held', $this->res( $second )[0]->status );
	}

	public function test_resubmit_after_expiry_reclaims(): void {
		$this->cart( $this->bookable() );
		$oid = $this->card_order();
		$old = $this->res( $oid )[0];
		$this->assertTrue( ReservationRepository::release( $old->id, 'expired' ) );
		$this->assertSame( 0, self::rows( 'occupancy' ) );

		$again = $this->card_order();
		$this->assertSame( $oid, $again );
		$active = $this->res( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertNotSame( $old->id, $active[0]->id );
		$this->assertSame( 'held', $active[0]->status );
		$this->assertSame( 2, self::rows( 'occupancy' ) );
	}

	public function test_resubmit_after_expiry_conflict_409(): void {
		$pid = $this->bookable();
		$this->cart( $pid );
		$oid = $this->card_order();
		$this->assertTrue( ReservationRepository::release( $this->res( $oid )[0]->id, 'expired' ) );
		ReservationRepository::claim(
			array(
				'product_id' => $pid,
				'slots'      => array( new \DateTimeImmutable( self::T10 ) ),
				'duration'   => 30,
				'status'     => 'confirmed',
			)
		);
		$res = $this->checkout( array( self::T10 ), TestCardGateway::ID );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'slotiva_slot_taken', self::code( $res ) );
		$this->assertSame( array(), $this->res( $oid, true ) );
		$this->assertSame( 'expired', $this->res( $oid )[0]->status, 'çakışmada eski satır olduğu gibi kalır' );
	}

	public function test_store_api_order_pay_reuses_or_reclaims(): void {
		$this->cart( $this->bookable() );
		$oid   = $this->card_order();
		$order = Fresh::order( $oid );
		$this->assertNotNull( $order );
		$first = $this->res( $oid )[0];
		// Order-pay sepete bakmaz (§15.4): sepet boş.
		WC()->cart->empty_cart();

		$pay = function () use ( $order ): \WP_REST_Response {
			return $this->store(
				'POST',
				'/wc/store/v1/checkout/' . $order->get_id(),
				array(
					'key'             => $order->get_order_key(),
					'billing_email'   => $order->get_billing_email(),
					'billing_address' => $this->billing(),
					'payment_method'  => TestCardGateway::ID,
				)
			);
		};

		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:20:00Z' ) );
		$res = $pay();
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$rows = $this->res( $oid );
		$this->assertCount( 1, $rows, 'aynı tutma kullanıldı' );
		$this->assertSame( $first->id, $rows[0]->id );
		$this->assertSame( '2026-11-16 07:05:00', $rows[0]->holdExpiresAt?->format( 'Y-m-d H:i:s' ) );

		// Süre doldu, saat boş → yeniden alınır.
		$this->assertTrue( ReservationRepository::release( $first->id, 'expired' ) );
		$res = $pay();
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$active = $this->res( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( self::T10, $active[0]->startsAt->format( 'Y-m-d\TH:i:s\Z' ) );
		$this->assertSame( 'held', $active[0]->status );

		// Süre doldu, saat başkasının → 409, ödeme başlamaz.
		$this->assertTrue( ReservationRepository::release( $active[0]->id, 'expired' ) );
		ReservationRepository::claim(
			array(
				'product_id' => $first->productId,
				'slots'      => array( new \DateTimeImmutable( self::T10 ) ),
				'duration'   => 30,
				'status'     => 'confirmed',
			)
		);
		$res = $pay();
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'slotiva_slot_taken', self::code( $res ) );
	}

	public function test_store_api_order_pay_without_stored_request_rejected(): void {
		$this->cart( $this->bookable() );
		$oid   = $this->card_order();
		$order = Fresh::order( $oid );
		$this->assertNotNull( $order );
		$order->delete_meta_data( CheckoutFlow::META_REQUESTED );
		$order->save();
		$res = $this->store(
			'POST',
			'/wc/store/v1/checkout/' . $oid,
			array(
				'key'             => $order->get_order_key(),
				'billing_email'   => $order->get_billing_email(),
				'billing_address' => $this->billing(),
				'payment_method'  => TestCardGateway::ID,
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_no_slots', self::code( $res ) );
	}

	public function test_main_checkout_ignores_stored_request_without_extension(): void {
		$this->cart( $this->bookable() );
		$oid = $this->card_order();
		// Aynı taslak yeniden gönderilir ama seçim yok: saklı talep ana ödeme yolunda kullanılmaz (§15.12).
		$res = $this->checkout( null, TestCardGateway::ID );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_no_slots', self::code( $res ) );
		$this->assertSame( 'held', $this->res( $oid )[0]->status );
	}

	public function test_hold_quota_returns_429(): void {
		$pid = $this->bookable();
		foreach ( array( 999101, 999102 ) as $i => $other ) {
			ReservationRepository::claim(
				array(
					'product_id'      => $pid,
					'order_id'        => $other,
					'slots'           => array( new \DateTimeImmutable( '2026-11-18T0' . ( 7 + $i ) . ':00:00Z' ) ),
					'duration'        => 30,
					'status'          => 'held',
					'hold_expires_at' => Clock::now()->modify( '+45 minutes' ),
					'session_hash'    => md5( 'baska-oturum-' . $i ),
					'ip_hash'         => md5( '203.0.113.7' ),
				)
			);
		}
		$this->cart( $pid );
		$res = $this->checkout( array( self::T10 ), TestCardGateway::ID );
		$this->assertSame( 429, $res->get_status() );
		$this->assertSame( 'slotiva_hold_quota', self::code( $res ) );
	}

	public function test_order_lock_busy_returns_409_busy(): void {
		$this->cart( $this->bookable() );
		$oid   = $this->card_order();
		$rival = mysqli_init();
		$this->assertTrue( @mysqli_real_connect( $rival, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- test bağlantısı.
		$name = $GLOBALS['wpdb']->prefix . 'slotiva_order_' . $oid;
		$got  = mysqli_query( $rival, "SELECT GET_LOCK('" . mysqli_real_escape_string( $rival, $name ) . "', 0)" );
		$this->assertSame( '1', (string) mysqli_fetch_row( $got )[0] );
		add_filter( 'slotiva_lock_timeout', '__return_zero' );
		try {
			$res = $this->checkout( array( self::T11 ), TestCardGateway::ID );
		} finally {
			remove_filter( 'slotiva_lock_timeout', '__return_zero' );
			mysqli_query( $rival, 'DO RELEASE_ALL_LOCKS()' );
			mysqli_close( $rival );
		}
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'slotiva_busy', self::code( $res ) );
		$this->assertSame( 'held', $this->res( $oid )[0]->status, 'eski tutma bozulmadı' );
	}

	public function test_second_bookable_line_blocked(): void {
		$this->cart( $this->bookable() );
		$this->cart( $this->bookable( array( '_slotiva_duration' => '60' ) ) );
		$res = $this->checkout( array( self::T10 ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'slotiva_one_bookable_line', self::code( $res ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	public function test_add_to_cart_blocks_second_bookable_product(): void {
		$first  = $this->bookable();
		$second = $this->bookable( array( '_slotiva_duration' => '60' ) );
		$plain  = $this->plain_product();
		$this->cart( $first );

		// Klasik süzgeç.
		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $second, 1 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC kancası.
		$this->assertTrue( wc_has_notice( wp_strip_all_tags( __( 'Your cart can hold only one bookable service at a time. Please remove the other one from your cart.', 'mhm-slotiva' ) ), 'error' ) );
		wc_clear_notices();
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $first, 1 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- aynı ürün serbest.
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $plain, 1 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// Store API: uyarı RouteException'a çevrilir.
		$res = $this->store(
			'POST',
			'/wc/store/v1/cart/add-item',
			array(
				'id'       => $second,
				'quantity' => 1,
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertStringContainsString( 'only one bookable service at a time', (string) $res->get_data()['message'] );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_non_bookable_checkout_unaffected(): void {
		$this->cart( $this->plain_product() );
		$oid = $this->order_id( $this->checkout( null ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
		$order = Fresh::order( $oid );
		$this->assertSame( '', $order?->get_meta( CheckoutFlow::META_REQUESTED ) );
		$this->assertSame( '', $order?->get_meta( CheckoutFlow::META_LABEL ) );
	}

	public function test_classic_checkout_rejects_bookable(): void {
		$this->cart( $this->bookable() );
		$before  = (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts}" );
		$created = false;
		// Bizim ret olmasaydı sipariş oluşturulurdu: bu nöbetçi onu yakalar (çıkış/yönlendirme yerine istisna).
		$sentry = static function () use ( &$created ) {
			$created = true;
			throw new \Exception( 'slotiva_test_order_created' );
		};
		add_action( 'woocommerce_checkout_create_order', $sentry );

		$_POST    = array(
			'billing_first_name'                 => 'Ayşe',
			'billing_last_name'                  => 'Yılmaz',
			'billing_email'                      => 'ayse@example.test',
			'billing_phone'                      => '05551112233',
			'billing_address_1'                  => 'Moda Cd. 1',
			'billing_city'                       => 'İstanbul',
			'billing_postcode'                   => '34710',
			'billing_country'                    => 'TR',
			'billing_state'                      => 'TR34',
			'payment_method'                     => 'bacs',
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		);
		$_REQUEST = $_POST;
		try {
			WC()->checkout()->process_checkout();
		} finally {
			remove_action( 'woocommerce_checkout_create_order', $sentry );
			$_POST    = array();
			$_REQUEST = array();
		}

		$errors = array_map( static fn( $n ) => wp_strip_all_tags( (string) $n['notice'] ), wc_get_notices( 'error' ) );
		$this->assertContains( 'Bookable services can only be bought on the current checkout page. Please contact the site administrator.', $errors );
		$this->assertNotContains( 'slotiva_test_order_created', $errors );
		$this->assertFalse( $created );
		$this->assertSame( $before, (int) $GLOBALS['wpdb']->get_var( "SELECT MAX(ID) FROM {$GLOBALS['wpdb']->posts}" ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	public function test_classic_checkout_allows_non_bookable_cart(): void {
		// Negatif kontrol: nöbetçi gerçekten tetiklenir (yukarıdaki test boş geçmiyor).
		$this->cart( $this->plain_product() );
		$created = false;
		$sentry  = static function () use ( &$created ) {
			$created = true;
			throw new \Exception( 'slotiva_test_order_created' );
		};
		add_action( 'woocommerce_checkout_create_order', $sentry );
		$_POST    = array(
			'billing_first_name'                 => 'Ayşe',
			'billing_last_name'                  => 'Yılmaz',
			'billing_email'                      => 'ayse@example.test',
			'billing_phone'                      => '05551112233',
			'billing_address_1'                  => 'Moda Cd. 1',
			'billing_city'                       => 'İstanbul',
			'billing_postcode'                   => '34710',
			'billing_country'                    => 'TR',
			'billing_state'                      => 'TR34',
			'payment_method'                     => 'bacs',
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		);
		$_REQUEST = $_POST;
		try {
			WC()->checkout()->process_checkout();
		} finally {
			remove_action( 'woocommerce_checkout_create_order', $sentry );
			$_POST    = array();
			$_REQUEST = array();
		}
		$this->assertTrue( $created );
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
	 * Aynı akış eski depolamada ve HPOS'ta.
	 *
	 * @dataProvider storage_modes
	 *
	 * @param bool $hpos HPOS açık mı.
	 */
	public function test_claim_flow_hpos_and_legacy( bool $hpos ): void {
		$ctrl   = \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class;
		$sync   = \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class;
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
			$this->cart( $this->bookable() );
			$oid = $this->card_order();
			global $wpdb;
			$in_hpos = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $oid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- depolama kanıtı.
			$this->assertSame( $hpos ? 1 : 0, $in_hpos, 'sipariş istenen depoda' );
			$this->assertSame( $hpos ? 'shop_order_placehold' : 'shop_order', get_post_type( $oid ) );

			$order = Fresh::order( $oid );
			$this->assertNotNull( $order );
			$this->assertSame( array( self::T10 ), $order->get_meta( CheckoutFlow::META_REQUESTED ) );
			$first = $this->res( $oid )[0];

			// Aynı sipariş aynı anda yeniden (çift tıklama; donuk saat = aynı bitiş, MySQL 0 satır etkiler): tutma kullanılır.
			$this->assertSame( $oid, $this->card_order() );
			$this->assertSame( $first->id, $this->res( $oid, true )[0]->id );

			// Order-pay yolu saklı talebi okur.
			$res = $this->store(
				'POST',
				'/wc/store/v1/checkout/' . $oid,
				array(
					'key'             => $order->get_order_key(),
					'billing_email'   => $order->get_billing_email(),
					'billing_address' => $this->billing(),
					'payment_method'  => TestCardGateway::ID,
				)
			);
			$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
			$this->assertSame( $first->id, $this->res( $oid, true )[0]->id );
			$this->assertSame( '17 November Tuesday, 10:00', Fresh::order( $oid )?->get_meta( CheckoutFlow::META_LABEL ) );
		} finally {
			WC()->cart->empty_cart();
			// Siparişleri kendi kiplerinde sil (kip geri alındıktan sonra HPOS satırı yetim kalırdı).
			$ids = wc_get_orders(
				array(
					'limit'  => -1,
					'return' => 'ids',
					'status' => array_keys( wc_get_order_statuses() ),
				)
			);
			foreach ( (array) $ids as $id ) {
				Fresh::order( (int) $id )?->delete( true );
			}
			update_option( 'woocommerce_custom_orders_table_enabled', $before['enabled'] );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', $before['sync'] );
		}
	}

	/**
	 * Store API order-pay isteği (uzantısız; §15.4).
	 *
	 * @param int $oid Sipariş.
	 */
	private function order_pay( int $oid ): \WP_REST_Response {
		$order = Fresh::order( $oid );
		$this->assertNotNull( $order );
		return $this->store(
			'POST',
			'/wc/store/v1/checkout/' . $oid,
			array(
				'key'             => $order->get_order_key(),
				'billing_email'   => $order->get_billing_email(),
				'billing_address' => $this->billing(),
				'payment_method'  => TestCardGateway::ID,
			)
		);
	}

	/**
	 * (Codex C2) Aynı dilimle yeniden gönderim (yeniden kullanım yolu) de sipariş kilidi altında karar verir: kilidi başka
	 * bir bağlantı tutarken tutma çıplak UPDATE ile yenilenmez → 409 slotiva_busy, tutma değişmez.
	 */
	public function test_identical_resubmit_respects_order_lock(): void {
		$this->cart( $this->bookable() );
		$oid    = $this->card_order();
		$before = $this->res( $oid )[0];
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:10:00Z' ) );
		$rival = mysqli_init();
		$this->assertTrue( @mysqli_real_connect( $rival, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- test bağlantısı.
		$name = $GLOBALS['wpdb']->prefix . 'slotiva_order_' . $oid;
		$got  = mysqli_query( $rival, "SELECT GET_LOCK('" . mysqli_real_escape_string( $rival, $name ) . "', 0)" );
		$this->assertSame( '1', (string) mysqli_fetch_row( $got )[0] );
		add_filter( 'slotiva_lock_timeout', '__return_zero' );
		try {
			$res = $this->checkout( array( self::T10 ), TestCardGateway::ID );
		} finally {
			remove_filter( 'slotiva_lock_timeout', '__return_zero' );
			mysqli_query( $rival, 'DO RELEASE_ALL_LOCKS()' );
			mysqli_close( $rival );
		}
		$this->assertSame( 409, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'slotiva_busy', self::code( $res ) );
		$after = $this->res( $oid );
		$this->assertCount( 1, $after );
		$this->assertSame( $before->id, $after[0]->id );
		$this->assertSame( '2026-11-16 06:45:00', $after[0]->holdExpiresAt?->format( 'Y-m-d H:i:s' ), 'tutma kilitsiz yenilenmedi' );
	}

	/**
	 * (Codex C3) Order-pay: bekleyen iki dilimli siparişin kalem adedi 3'e çıkarılırsa canlı tutma yeniden kullanılamaz;
	 * kayıtlı talep (2 dilim) kalemle uyuşmuyor → 400 slotiva_qty_mismatch, ödeme başlamaz.
	 */
	public function test_store_api_order_pay_qty_change_rejected_on_reuse(): void {
		$this->cart(
			$this->bookable(
				array(
					'_slotiva_pricing'   => 'per_slot',
					'_slotiva_max_slots' => '5',
				)
			),
			2
		);
		$oid   = $this->card_order( array( self::T10, '2026-11-17T07:30:00Z' ) );
		$order = Fresh::order( $oid );
		$this->assertNotNull( $order );
		foreach ( $order->get_items() as $item ) {
			$item->set_quantity( 3 );
			$item->save();
		}
		$order->calculate_totals();
		$order->save();
		WC()->cart->empty_cart();

		$res = $this->order_pay( $oid );
		$this->assertSame( 400, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'slotiva_qty_mismatch', self::code( $res ) );
		$this->assertStringContainsString( 'The quantity in this order', (string) ( $res->get_data()['message'] ?? '' ), 'order-pay iletisi sepetten söz etmez' );
		$this->assertCount( 2, $this->res( $oid ), 'yeni satır yazılmadı' );
	}

	/**
	 * (Codex C4, §15.3) Aynı oturumun yeni siparişi B, A'yı supersede etti; A'nın saati boş. A'yı Store API order-pay ile
	 * ödemek yeni tutma ALMAZ (409 slotiva_superseded) ve B'ye dokunmaz. Sonradan gelen ödeme Task 10 çakışma yolundadır.
	 */
	public function test_store_api_pay_of_session_superseded_order_rejected(): void {
		$this->cart( $this->bookable() );
		$a = $this->card_order();
		WC()->cart->empty_cart();
		$this->cart( $this->bookable() ); // Farklı ürün → yeni sipariş.
		$b = $this->card_order( array( self::T11 ) );
		$this->assertNotSame( $a, $b );
		$this->assertSame( array( 'superseded' ), array_map( static fn( Reservation $r ): string => $r->status, $this->res( $a ) ) );
		WC()->cart->empty_cart();

		$res = $this->order_pay( $a );
		$this->assertSame( 409, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'slotiva_superseded', self::code( $res ) );
		$this->assertSame( array( 'superseded' ), array_map( static fn( Reservation $r ): string => $r->status, $this->res( $a ) ), 'süpersede sipariş yeniden alınmadı' );
		$this->assertSame( array( 'held' ), array_map( static fn( Reservation $r ): string => $r->status, $this->res( $b ) ), 'yeni sipariş korunur' );
		$this->assertSame( 2, self::rows( 'occupancy' ), 'yalnız B\'nin birimleri' );

		// Ödeme yine de gelirse (ör. ağ geçidi geri çağrısı): Task 10 çakışma yolu, yeniden alma yok (§15.3).
		$conflicts = array();
		$spy       = static function ( $order_id, $ids, $reason ) use ( &$conflicts ) {
			unset( $ids );
			$conflicts[] = array( (int) $order_id, (string) $reason );
		};
		add_action( 'slotiva_conflict', $spy, 10, 3 );
		Fresh::order( $a )?->payment_complete();
		remove_action( 'slotiva_conflict', $spy, 10 );
		$this->assertSame( array( 'conflict' ), array_map( static fn( Reservation $r ): string => $r->status, $this->res( $a ) ) );
		$this->assertSame( array( array( $a, 'superseded_paid' ) ), $conflicts );
		$this->assertSame( 2, self::rows( 'occupancy' ), 'A için birim alınmadı' );
	}

	/**
	 * (Fix round 2, K1) Kart ödemesi başarısız → müşteri ödeme sayfasında yeniden seçer: ilk seçim adedi düşürür
	 * (`cart/extensions` → oturum taslağının satırları supersede), ikincisi geri yükseltir (sepet özeti eşleşir, WC aynı
	 * taslağı kullanır). Ana ödeme yolunda bu yeni bir yerleştirmedir: 200, taslak yeni dilimlerle `held`.
	 */
	public function test_failed_card_repick_count_down_and_up_reuses_draft(): void {
		$this->cart(
			$this->bookable(
				array(
					'_slotiva_pricing'   => 'per_slot',
					'_slotiva_max_slots' => '5',
				)
			),
			2
		);
		$oid   = $this->card_order( array( self::T10, '2026-11-17T07:30:00Z' ) );
		$order = Fresh::order( $oid );
		$this->assertNotNull( $order );
		$order->update_status( 'failed' );

		foreach ( array( 1, 2 ) as $count ) {
			$res = $this->store(
				'POST',
				'/wc/store/v1/cart/extensions',
				array(
					'namespace' => 'slotiva',
					'data'      => array( 'count' => $count ),
				)
			);
			$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		}
		$this->assertSame( array( 'superseded', 'superseded' ), array_map( static fn( Reservation $r ): string => $r->status, $this->res( $oid ) ) );

		$again = $this->card_order( array( self::T11, '2026-11-17T08:30:00Z' ) );
		$this->assertSame( $oid, $again, 'WC aynı taslağı kullandı' );
		$active = $this->res( $oid, true );
		$this->assertCount( 2, $active );
		$this->assertSame( array( 'held', 'held' ), array_map( static fn( Reservation $r ): string => $r->status, $active ) );
		$this->assertSame( self::T11, $active[0]->startsAt->format( 'Y-m-d\TH:i:s\Z' ) );
		$this->assertSame( 4, self::rows( 'occupancy' ), 'yalnız yeni dilimlerin birimleri' );
	}

	/**
	 * (Fix round 3, I1) WP REST rotaları büyük/küçük harf duyarsız eşleşir: `/CHECKOUT/{id}` de order-pay işleyicisine
	 * (CheckoutOrder) gider. Uzantıyla dilim gönderilse bile supersede edilmiş sipariş yeni tutma ALMAZ (409).
	 */
	public function test_case_variant_order_pay_route_still_rejects_superseded(): void {
		$this->cart( $this->bookable() );
		$a = $this->card_order();
		WC()->cart->empty_cart();
		$this->cart( $this->bookable() );
		$b = $this->card_order( array( self::T11 ) );
		$this->assertNotSame( $a, $b );
		WC()->cart->empty_cart();

		$order = Fresh::order( $a );
		$this->assertNotNull( $order );
		$res = $this->store(
			'POST',
			'/wc/store/v1/CHECKOUT/' . $a,
			array(
				'key'             => $order->get_order_key(),
				'billing_email'   => $order->get_billing_email(),
				'billing_address' => $this->billing(),
				'payment_method'  => TestCardGateway::ID,
				'extensions'      => array( 'slotiva' => array( 'slots' => array( self::T10 ) ) ),
			)
		);
		$this->assertSame( 409, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'slotiva_superseded', self::code( $res ) );
		$this->assertSame( array(), $this->res( $a, true ), 'yeni tutma alınmadı' );
		$this->assertSame( array( 'held' ), array_map( static fn( Reservation $r ): string => $r->status, $this->res( $b ) ) );
	}

	/**
	 * (Final review F2 / I-2, Codex 1) Store API order-pay (`/checkout/{id}`): yöneticinin iptal ettiği ödenmemiş randevu
	 * yeniden alınmaz → 409 slotiva_cancelled, ödeme başlamaz.
	 */
	public function test_store_api_order_pay_rejects_admin_cancelled_booking(): void {
		$this->cart( $this->bookable() );
		$oid = $this->card_order();
		$id  = $this->res( $oid )[0]->id;
		$this->assertTrue(
			ReservationRepository::release(
				$id,
				'cancelled',
				array(
					'reason'        => 'admin',
					'expect_status' => ReservationRepository::ACTIVE,
				)
			)
		);
		WC()->cart->empty_cart();

		$res = $this->order_pay( $oid );
		$this->assertSame( 409, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'slotiva_cancelled', self::code( $res ) );
		$this->assertSame( array(), $this->res( $oid, true ), 'yeni tutma alınmadı' );
		$this->assertCount( 1, $this->res( $oid ) );
	}

	/**
	 * (Final review F4 / m-1, T8 M-2, Codex M1) Ödeme yolunda eşlenmemiş depo istisnası müşteriye 500 + ham veritabanı
	 * metni olarak gitmez: 503 slotiva_busy, genel ileti.
	 */
	public function test_unexpected_repository_error_is_mapped_without_db_text(): void {
		$this->cart( $this->bookable() );
		$boom = static function (): int {
			throw new \RuntimeException( 'slotiva_db_error: Table wptests_slotiva_occupancy is marked as crashed' );
		};
		add_filter( 'slotiva_lock_timeout', $boom );
		try {
			$res = $this->checkout( array( self::T10 ), TestCardGateway::ID );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $boom );
		}
		$this->assertSame( 503, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'slotiva_busy', self::code( $res ) );
		$body = (string) wp_json_encode( $res->get_data() );
		$this->assertStringNotContainsString( 'slotiva_db_error', $body );
		$this->assertStringNotContainsString( 'crashed', $body );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	/**
	 * (Pre-ZIP gate G3) `\Error` (ör. TypeError) de eşlenmemiş istisna gibi eşlenir: 503 slotiva_busy, genel ileti, ham
	 * metin yok, hiçbir satır yazılmaz.
	 */
	public function test_php_error_in_claim_is_mapped_like_other_unmapped_errors(): void {
		$this->cart( $this->bookable() );
		$boom = static function (): int {
			throw new \TypeError( 'slotiva_type_error: Argument #1 must be of type int' );
		};
		add_filter( 'slotiva_lock_timeout', $boom );
		try {
			$res = $this->checkout( array( self::T10 ), TestCardGateway::ID );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $boom );
		}
		$this->assertSame( 503, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'slotiva_busy', self::code( $res ) );
		$this->assertStringNotContainsString( 'slotiva_type_error', (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}
}
