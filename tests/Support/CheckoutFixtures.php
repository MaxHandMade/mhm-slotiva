<?php
/**
 * Ödeme testleri için ortak kurulum: saat dilimi, dondurulmuş saat, düzen, ağ geçitleri, Store API istekleri.
 *
 * Takvim: Pzt 2026-11-16 09:00 İstanbul (06:00Z) donuk; hafta içi 10:00–12:00 açık; ön süre 24 sa.
 * Salı 2026-11-17 10:00 yerel = 07:00Z (ilk alınabilir gün).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Support\Clock;

trait CheckoutFixtures {

	/**
	 * Saat dilimi yedeği.
	 *
	 * @var array<string,mixed>
	 */
	private array $tz_backup = array();

	private function set_up_checkout(): void {
		$this->tz_backup = array(
			'timezone_string' => get_option( 'timezone_string' ),
			'gmt_offset'      => get_option( 'gmt_offset' ),
		);
		update_option( 'timezone_string', 'Europe/Istanbul' );
		update_option( 'gmt_offset', 0 );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:00:00Z' ) );
		$day = array(
			array(
				'from' => '10:00',
				'to'   => '12:00',
			),
		);
		WeeklySchedule::save(
			array(
				0 => $day,
				1 => $day,
				2 => $day,
				3 => $day,
				4 => $day,
			)
		);
		Rules::save(
			array(
				'leadHours'     => 24,
				'horizonDays'   => 14,
				'bufferMin'     => 0,
				'cardHoldMin'   => 45,
				'bankHoldHours' => 24,
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_test_gateway' ) );
		update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes' ) );
		update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
		WC()->payment_gateways()->init();

		WC()->cart->empty_cart();
		WC()->session->set( 'store_api_draft_order', null );
		WC()->session->set( 'order_awaiting_payment', null );
		wc_clear_notices();

		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
	}

	private function tear_down_checkout(): void {
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		WC()->cart->empty_cart();
		WC()->session->set( 'store_api_draft_order', null );
		wc_clear_notices();
		remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		remove_filter( 'woocommerce_payment_gateways', array( $this, 'add_test_gateway' ) );
		WC()->payment_gateways()->init();
		unset( $_SERVER['REMOTE_ADDR'] );
		update_option( 'timezone_string', $this->tz_backup['timezone_string'] );
		update_option( 'gmt_offset', $this->tz_backup['gmt_offset'] );
	}

	/**
	 * Test kart ağ geçidini ekler.
	 *
	 * @param array<int,mixed> $gateways Ağ geçitleri.
	 * @return array<int,mixed>
	 */
	public function add_test_gateway( array $gateways ): array {
		$gateways[] = TestCardGateway::class;
		return $gateways;
	}

	/**
	 * Randevulu ürün.
	 *
	 * @param array<string,mixed> $meta Ek/ezen meta.
	 * @param string              $price Fiyat.
	 */
	private function bookable( array $meta = array(), string $price = '100' ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Danışmanlık' );
		$p->set_regular_price( $price );
		$p->set_virtual( true );
		$meta += array(
			'_slotiva_enabled'      => 'yes',
			'_slotiva_duration'     => '30',
			'_slotiva_pricing'      => 'fixed',
			'_slotiva_min_slots'    => '1',
			'_slotiva_max_slots'    => '1',
			'_slotiva_location'     => 'Atölye, Kadıköy',
			'_slotiva_horizon_days' => '14',
		);
		foreach ( $meta as $k => $v ) {
			$p->update_meta_data( $k, $v );
		}
		return $p->save();
	}

	private function plain_product(): int {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Seramik kupa' );
		$p->set_regular_price( '50' );
		$p->set_virtual( true );
		return $p->save();
	}

	/**
	 * Store API isteği (JSON gövde).
	 *
	 * @param string              $method Yöntem.
	 * @param string              $route  Yol.
	 * @param array<string,mixed> $body   Gövde.
	 */
	private function store( string $method, string $route, array $body = array() ): \WP_REST_Response {
		// Her istek gerçek HTTP gibi taze rota nesneleriyle: Store API Checkout rotası `$this->order`u nesnede tutar;
		// aynı sunucu nesnesiyle ikinci istek önceki siparişi WC'nin yeniden kullanma kuralına bakmadan devralırdı.
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.

		$r = new \WP_REST_Request( $method, $route );
		if ( $body ) {
			$r->set_header( 'Content-Type', 'application/json' );
			$r->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_do_request( $r );
	}

	/**
	 * Fatura adresi.
	 *
	 * @return array<string,string>
	 */
	private function billing(): array {
		return array(
			'first_name' => 'Ayşe',
			'last_name'  => 'Yılmaz',
			'email'      => 'ayse@example.test',
			'phone'      => '05551112233',
			'address_1'  => 'Moda Cd. 1',
			'city'       => 'İstanbul',
			'postcode'   => '34710',
			'country'    => 'TR',
			'state'      => 'TR34',
		);
	}

	/**
	 * Ödeme isteği.
	 *
	 * @param list<string>|null   $slots   Dilimler (null = uzantı yok).
	 * @param string              $gateway Ödeme yöntemi.
	 * @param array<string,mixed> $ext     `slotiva` ad alanına eklenecek fazladan alanlar.
	 */
	private function checkout( ?array $slots, string $gateway = 'bacs', array $ext = array() ): \WP_REST_Response {
		$body = array(
			'billing_address' => $this->billing(),
			'payment_method'  => $gateway,
		);
		if ( null !== $slots ) {
			$body['extensions'] = array( 'slotiva' => array( 'slots' => $slots ) + $ext );
		}
		return $this->store( 'POST', '/wc/store/v1/checkout', $body );
	}

	/**
	 * Yanıt hata kodu.
	 *
	 * @param \WP_REST_Response $res Yanıt.
	 */
	private static function code( \WP_REST_Response $res ): string {
		$data = (array) $res->get_data();
		return (string) ( $data['code'] ?? '' );
	}

	/**
	 * Slotiva tablosundaki satır sayısı.
	 *
	 * @param string $short reservations|occupancy.
	 */
	private static function rows( string $short ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \MHM\Slotiva\Db\Schema::table( $short ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test; tablo adı beyaz listeden.
	}
}
