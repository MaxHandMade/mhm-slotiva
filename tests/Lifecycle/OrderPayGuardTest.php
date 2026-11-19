<?php
/**
 * Klasik "siparişi öde" formu (spec §6.4, §15.4): süresi dolmuş randevu ödeme öncesi yeniden alınır; saat doluysa
 * `wc_add_notice(…,'error')` ödemeyi durdurur (`class-wc-form-handler.php:605`).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Lifecycle;

use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Lifecycle\OrderPayGuard;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\CountingGateway;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.Security.NonceVerification -- test, form isteğini kendisi kurar; nonce'u WC doğrular.

class OrderPayGuardTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;

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
		$this->set_up_lifecycle();
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_counting_gateway' ) );
		WC()->payment_gateways()->init();
		CountingGateway::$calls = 0;
	}

	public function tear_down(): void {
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'add_counting_gateway' ) );
		unset( $_POST['woocommerce_pay'], $_POST['woocommerce-pay-nonce'], $_POST['payment_method'], $_GET['key'], $_REQUEST['woocommerce-pay-nonce'] );
		global $wp;
		unset( $wp->query_vars['order-pay'] );
		try {
			$this->tear_down_lifecycle();
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
		$this->assertFalse( has_filter( 'woocommerce_payment_gateways', array( $this, 'add_counting_gateway' ) ), 'sayaçlı ağ geçidi süzgeci kalktı' );
	}

	/**
	 * Ağ geçidi nesnelerini test öncesine döndürür (sonraki testler aynı nesneleri görsün).
	 */
	private function restore_gateways(): void {
		WC()->payment_gateways()->payment_gateways = $this->gateways_before;
	}

	/**
	 * Sayan ağ geçidini ekler.
	 *
	 * @param array<int,mixed> $gateways Ağ geçitleri.
	 * @return array<int,mixed>
	 */
	public function add_counting_gateway( array $gateways ): array {
		$gateways[] = CountingGateway::class;
		return $gateways;
	}

	/**
	 * Süresi dolmuş kart tutması (saat 06:50Z, tutma 06:45Z'de bitti).
	 *
	 * @return array{0:int,1:list<int>,2:int}
	 */
	private function expired_card_order(): array {
		$made = $this->booked_order( CountingGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:50:00Z' ) );
		$this->run_expiry( $this->row( $made[1][0] ) );
		$this->assertSame( 'expired', $this->row( $made[1][0] )->status );
		return $made;
	}

	/**
	 * Gerçek `WC_Form_Handler::pay_action()` (klasik order-pay formu POST'u).
	 *
	 * @param int    $oid    Sipariş.
	 * @param string $method Seçilen yöntem.
	 */
	private function pay( int $oid, string $method = CountingGateway::ID ): void {
		global $wp;
		$order                             = $this->order( $oid );
		$nonce                             = wp_create_nonce( 'woocommerce-pay' );
		$wp->query_vars['order-pay']       = $oid;
		$_GET['key']                       = $order->get_order_key();
		$_POST['woocommerce_pay']          = '1';
		$_POST['woocommerce-pay-nonce']    = $nonce;
		$_REQUEST['woocommerce-pay-nonce'] = $nonce;
		$_POST['payment_method']           = $method;
		$level                             = ob_get_level();
		try {
			\WC_Form_Handler::pay_action();
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	public function test_order_pay_guard_blocks_taken_slot(): void {
		[ $oid, $ids, $pid ] = $this->expired_card_order();
		$rival               = $this->rival( $pid );

		$this->pay( $oid );

		$this->assertSame( 0, CountingGateway::$calls, 'hata bildirimi ödemeyi durdurdu' );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$notice = wc_get_notices( 'error' )[0]['notice'] ?? '';
		$this->assertStringContainsString( 'no longer available', $notice );
		$this->assertStringContainsString( esc_url( wc_get_checkout_url() ), $notice, 'ödeme sayfasına dönüş bağlantısı' );
		$this->assertSame( array(), ReservationRepository::forOrder( $oid, true ), 'ikinci rezervasyon yok' );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertSame( 'confirmed', $this->row( $rival )->status );
	}

	public function test_order_pay_guard_reclaims_free_slot(): void {
		[ $oid ] = $this->expired_card_order();

		$this->pay( $oid );

		$this->assertSame( 0, wc_notice_count( 'error' ) );
		$this->assertSame( 1, CountingGateway::$calls, 'ödeme ilerledi' );
		$active = ReservationRepository::forOrder( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( 'held', $active[0]->status );
		$this->assertSame( 'pending', $active[0]->paymentState );
		$this->assertSame( '2026-11-16 07:35:00', $active[0]->holdExpiresAt?->format( 'Y-m-d H:i:s' ), 'şimdi + kart tutma' );
		$this->assertCount( 1, $this->expiry_jobs( $active[0]->id ), 'yeni tutmanın işi kuruldu' );
	}

	public function test_order_pay_guard_reuses_live_hold(): void {
		[ $oid, $ids ] = $this->booked_order( CountingGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:20:00Z' ) );
		OrderPayGuard::beforePay( $this->order( $oid ) );
		$this->assertSame( 0, wc_notice_count( 'error' ) );
		$active = ReservationRepository::forOrder( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( $ids[0], $active[0]->id, 'aynı tutma' );
	}

	public function test_order_pay_guard_ignores_non_bookable_order(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$product = wc_get_product( $this->plain_product() );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();
		OrderPayGuard::beforePay( $order );
		$this->assertSame( 0, wc_notice_count( 'error' ) );
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	/**
	 * R26: gönderilen yöntem yalnız geçerli `woocommerce-pay` nonce'uyla okunur; eksik nonce'ta yok sayılır (siparişin
	 * kayıtlı kart yöntemi kalır) ve ölümcül hata yoktur.
	 */
	public function test_order_pay_guard_ignores_posted_method_without_nonce(): void {
		$this->assert_posted_method_ignored( null );
	}

	/**
	 * R26: bozuk nonce'ta da gönderilen yöntem yok sayılır.
	 */
	public function test_order_pay_guard_ignores_posted_method_with_bad_nonce(): void {
		$this->assert_posted_method_ignored( 'bozuk' );
	}

	/**
	 * Kapıda ödeme gönderilir; nonce eksik/bozuk olduğundan kart yöntemiyle yeni tutma kurulur.
	 *
	 * @param string|null $nonce Gönderilen nonce (null = hiç yok).
	 */
	private function assert_posted_method_ignored( ?string $nonce ): void {
		[ $oid ]                 = $this->expired_card_order();
		$_POST['payment_method'] = 'cod';
		if ( null !== $nonce ) {
			$_REQUEST['woocommerce-pay-nonce'] = $nonce;
		}
		OrderPayGuard::beforePay( $this->order( $oid ) );
		$active = ReservationRepository::forOrder( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( 'held', $active[0]->status, 'nonce yok/bozuk: kayıtlı kart yöntemi geçerli' );
		$this->assertSame( 'pending', $active[0]->paymentState );
	}

	public function test_order_pay_guard_follows_posted_method(): void {
		[ $oid ]                           = $this->expired_card_order();
		$_POST['payment_method']           = 'cod';
		$_REQUEST['woocommerce-pay-nonce'] = wp_create_nonce( 'woocommerce-pay' );
		OrderPayGuard::beforePay( $this->order( $oid ) );
		$active = ReservationRepository::forOrder( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( 'confirmed', $active[0]->status, 'formda seçilen kapıda ödeme yöntemine göre' );
		$this->assertSame( 'on_site', $active[0]->paymentState );
	}

	/**
	 * (Codex C3) Klasik order-pay: kalem adedi 3, kayıtlı talep ve canlı tutma 2 dilim → yeniden kullanılmaz, hata
	 * bildirimi ödemeyi durdurur (§15.4).
	 */
	public function test_order_pay_guard_rejects_qty_change_on_live_hold(): void {
		$pid     = $this->bookable(
			array(
				'_slotiva_pricing'   => 'per_slot',
				'_slotiva_max_slots' => '5',
			)
		);
		$slots   = array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' );
		$order   = wc_create_order();
		$product = wc_get_product( $pid );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$order->add_product( $product, 3 );
		$order->set_created_via( 'store-api' );
		$order->set_payment_method( CountingGateway::ID );
		$order->set_billing_email( 'ayse@example.test' );
		$order->update_meta_data( CheckoutFlow::META_REQUESTED, $slots );
		$order->calculate_totals();
		$order->save();
		ReservationRepository::claim(
			array(
				'product_id'      => $pid,
				'order_id'        => $order->get_id(),
				'slots'           => array_map( static fn( string $s ): \DateTimeImmutable => new \DateTimeImmutable( $s ), $slots ),
				'duration'        => 30,
				'status'          => 'held',
				'payment_state'   => 'pending',
				'hold_expires_at' => Clock::now()->modify( '+45 minutes' ),
			)
		);

		$this->pay( $order->get_id() );

		$this->assertSame( 0, CountingGateway::$calls, 'hata bildirimi ödemeyi durdurdu' );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertStringContainsString( 'The quantity in this order', (string) ( wc_get_notices( 'error' )[0]['notice'] ?? '' ) );
		$this->assertCount( 2, ReservationRepository::forOrder( $order->get_id() ) );
	}

	/**
	 * (Codex C4) Klasik order-pay: oturumun yeni siparişi tarafından supersede edilmiş sipariş yeniden alınmaz.
	 */
	public function test_order_pay_guard_rejects_session_superseded_order(): void {
		$hash          = md5( 'oturum-a' );
		[ $oid, $ids ] = $this->booked_order( CountingGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z' ), $hash );
		$this->assertSame( 1, ReservationRepository::supersedeForSession( $hash, 0 ) );

		$this->pay( $oid );

		$this->assertSame( 0, CountingGateway::$calls );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$notice = (string) ( wc_get_notices( 'error' )[0]['notice'] ?? '' );
		$this->assertStringContainsString( 'replaced by a newer order', $notice );
		$this->assertStringContainsString( esc_url( wc_get_checkout_url() ), $notice, 'ödeme sayfasına yönlendirir' );
		$this->assertSame( array(), ReservationRepository::forOrder( $oid, true ), 'yeni tutma alınmadı' );
		$this->assertSame( 'superseded', $this->row( $ids[0] )->status );
	}

	/**
	 * Yönetim REST isteği (yönetici olarak).
	 *
	 * @param string              $path NS sonrası yol.
	 * @param array<string,mixed> $body JSON gövde.
	 */
	private function admin_post( string $path, array $body ): \WP_REST_Response {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$r = new \WP_REST_Request( 'POST', '/slotiva/v1/admin' . $path );
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( (string) wp_json_encode( $body ) );
		$res = rest_do_request( $r );
		wp_set_current_user( 0 );
		return $res;
	}

	/**
	 * (Final review F1 / I-1, Codex 2) Ödenmemiş kart randevusunun saatini yönetici değiştirdi; müşteri sonra klasik
	 * order-pay formundan ödüyor: yeni saat korunur (eski saate dönülmez), aynı satır yeniden kullanılır, ödeme ilerler.
	 */
	public function test_order_pay_after_admin_reschedule_keeps_new_time(): void {
		[ $oid, $ids ] = $this->booked_order( CountingGateway::ID );
		$res           = $this->admin_post(
			'/appointments/' . $ids[0] . '/reschedule',
			array(
				'start'           => '2026-11-17T08:00:00Z',
				'expect_sequence' => 0,
				'notify'          => false,
			)
		);
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( array( '2026-11-17T08:00:00Z' ), $this->order( $oid )->get_meta( CheckoutFlow::META_REQUESTED ), 'kayıtlı talep yeni saate yazıldı' );

		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:20:00Z' ) );
		$this->pay( $oid );

		$this->assertSame( 0, wc_notice_count( 'error' ) );
		$this->assertSame( 1, CountingGateway::$calls, 'ödeme ilerledi' );
		$active = ReservationRepository::forOrder( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( $ids[0], $active[0]->id, 'yönetici saatindeki satır yeniden kullanıldı' );
		$this->assertSame( '2026-11-17 08:00:00', $active[0]->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertCount( 1, ReservationRepository::forOrder( $oid ), 'eski saat yeniden alınmadı' );
	}

	/**
	 * (Final review F1, kilit yanı) Kayıtlı talep bayat kalsa bile (eşzamanlı saat değişimi, talep yazılmadan önce
	 * okundu) order-pay kararını kilit altındaki canlı satırlar verir: yeni saat korunur.
	 */
	public function test_order_pay_prefers_live_rows_over_stale_request(): void {
		[ $oid, $ids ] = $this->booked_order( CountingGateway::ID );
		$this->assertTrue( ReservationRepository::reschedule( $ids[0], new \DateTimeImmutable( '2026-11-17T08:00:00Z' ), true, false ) );
		$this->assertSame( array( '2026-11-17T07:00:00Z' ), $this->order( $oid )->get_meta( CheckoutFlow::META_REQUESTED ), 'talep bayat' );

		$this->pay( $oid );

		$this->assertSame( 1, CountingGateway::$calls );
		$active = ReservationRepository::forOrder( $oid, true );
		$this->assertCount( 1, $active );
		$this->assertSame( $ids[0], $active[0]->id );
		$this->assertSame( '2026-11-17 08:00:00', $active[0]->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( array( '2026-11-17T08:00:00Z' ), $this->order( $oid )->get_meta( CheckoutFlow::META_REQUESTED ), 'talep canlı satırlardan onarıldı' );
	}

	/**
	 * (Final review F2 / I-2, Codex 1) Yöneticinin iptal ettiği ödenmemiş randevu klasik order-pay ile geri gelmez: hata
	 * bildirimi ödemeyi durdurur, yeni tutma alınmaz.
	 */
	public function test_order_pay_rejects_admin_cancelled_booking(): void {
		[ $oid, $ids ] = $this->booked_order( CountingGateway::ID );
		$res           = $this->admin_post( '/appointments/' . $ids[0] . '/status', array( 'status' => 'cancelled' ) );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );

		$this->pay( $oid );

		$this->assertSame( 0, CountingGateway::$calls, 'hata bildirimi ödemeyi durdurdu' );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertStringContainsString( 'cancelled by the shop', (string) ( wc_get_notices( 'error' )[0]['notice'] ?? '' ) );
		$this->assertSame( array(), ReservationRepository::forOrder( $oid, true ), 'yeni tutma alınmadı' );
		$this->assertCount( 1, ReservationRepository::forOrder( $oid ) );
		$this->assertSame( 'cancelled', $this->row( $ids[0] )->status );
	}

	/**
	 * (Final review F4 / m-1) Ödeme yolunda beklenmeyen veritabanı hatası klasik order-pay'de ölümcül hata üretmez ve
	 * ham hata metnini müşteriye göstermez: genel bildirim, ödeme durur.
	 */
	public function test_order_pay_maps_unexpected_db_error_to_notice(): void {
		[ $oid ] = $this->expired_card_order();
		$boom    = static function (): int {
			throw new \RuntimeException( 'slotiva_db_error: Table wptests_slotiva_reservations is marked as crashed' );
		};
		add_filter( 'slotiva_lock_timeout', $boom );
		try {
			$this->pay( $oid );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $boom );
		}

		$this->assertSame( 0, CountingGateway::$calls );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$notice = (string) ( wc_get_notices( 'error' )[0]['notice'] ?? '' );
		$this->assertStringNotContainsString( 'slotiva_db_error', $notice );
		$this->assertStringNotContainsString( 'crashed', $notice );
		$this->assertStringContainsString( 'try again', $notice );
	}

	/**
	 * (Final review F4) Depo dışında bir Throwable (ör. \Error) da formu düşürmez: genel bildirim.
	 */
	public function test_order_pay_maps_any_throwable_to_notice(): void {
		[ $oid ] = $this->expired_card_order();
		$boom    = static function (): int {
			throw new \Error( 'internal detail' );
		};
		add_filter( 'slotiva_lock_timeout', $boom );
		try {
			$this->pay( $oid );
		} finally {
			remove_filter( 'slotiva_lock_timeout', $boom );
		}
		$this->assertSame( 0, CountingGateway::$calls );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$this->assertStringNotContainsString( 'internal detail', (string) ( wc_get_notices( 'error' )[0]['notice'] ?? '' ) );
	}
}
