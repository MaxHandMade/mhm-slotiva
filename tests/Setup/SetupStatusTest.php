<?php
/**
 * Kurulum durumu servisi (1.0.2): hours/checkout/product/timezone/payment ölçümleri.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Setup;

use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Setup\SetupStatus;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class SetupStatusTest extends SlotivaTestCase {

	private const BLOCK = '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->';

	/**
	 * Test öncesi değerler.
	 *
	 * @var array<string,mixed>
	 */
	private array $backup = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'woocommerce_checkout_page_id', 'timezone_string', 'gmt_offset' ) as $k ) {
			$this->backup[ $k ] = get_option( $k );
		}
		delete_option( WeeklySchedule::OPTION );
		update_option( 'woocommerce_checkout_page_id', 0 );
	}

	public function tear_down(): void {
		foreach ( $this->backup as $k => $v ) {
			update_option( $k, $v );
		}
		parent::tear_down();
	}

	private function block_checkout(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => self::BLOCK,
			)
		);
		update_option( 'woocommerce_checkout_page_id', $id );
	}

	private function hours(): void {
		WeeklySchedule::save(
			array(
				0 => array(
					array(
						'from' => '09:00',
						'to'   => '18:00',
					),
				),
			)
		);
	}

	private function product( string $status = 'publish' ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Danışmanlık' );
		$p->set_regular_price( '100' );
		$p->set_status( $status );
		$p->update_meta_data( '_slotiva_enabled', 'yes' );
		return $p->save();
	}

	public function test_empty_site_is_incomplete_with_three_todos(): void {
		$checks = SetupStatus::checks();
		$this->assertSame( array( 'hours', 'checkout', 'product', 'timezone', 'payment' ), array_keys( $checks ) );
		$this->assertSame( 'todo', $checks['hours']['state'] );
		$this->assertSame( 'todo', $checks['checkout']['state'] );
		$this->assertSame( 'todo', $checks['product']['state'] );
		$this->assertFalse( SetupStatus::isComplete() );
	}

	public function test_complete_when_hours_product_and_block_checkout(): void {
		$this->hours();
		$this->product();
		$this->block_checkout();
		$checks = SetupStatus::checks();
		$this->assertSame( 'ok', $checks['hours']['state'] );
		$this->assertSame( 'ok', $checks['product']['state'] );
		$this->assertSame( 'ok', $checks['checkout']['state'] );
		$this->assertTrue( SetupStatus::isComplete() );
	}

	public function test_offset_timezone_is_warn_not_todo(): void {
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 3 );
		$this->hours();
		$this->product();
		$this->block_checkout();
		$this->assertSame( 'warn', SetupStatus::checks()['timezone']['state'] );
		$this->assertTrue( SetupStatus::isComplete() );
		update_option( 'timezone_string', 'Europe/Istanbul' );
		$this->assertSame( 'ok', SetupStatus::checks()['timezone']['state'] );
	}

	public function test_timezone_detail_names_zone_and_offset(): void {
		update_option( 'timezone_string', 'Europe/Istanbul' );
		$detail = SetupStatus::checks()['timezone']['detail'];
		$this->assertStringContainsString( 'Europe/Istanbul (UTC+03:00)', $detail );
		$this->assertStringContainsString( 'customers see', $detail );
	}

	public function test_payment_detail_counts_and_names_enabled_methods(): void {
		$backup = get_option( 'woocommerce_bacs_settings' );
		update_option(
			'woocommerce_bacs_settings',
			array(
				'enabled' => 'yes',
				'title'   => 'Havale <b>EFT</b>',
			)
		);
		WC()->payment_gateways()->init();
		try {
			$check = SetupStatus::checks()['payment'];
			$count = count(
				array_filter(
					WC()->payment_gateways()->payment_gateways(),
					static fn( $g ) => 'yes' === $g->enabled
				)
			);
			$this->assertSame( 'ok', $check['state'] );
			$this->assertStringContainsString( $count . ' ', $check['detail'] );
			$this->assertStringContainsString( 'Havale EFT', $check['detail'] );
			$this->assertStringNotContainsString( '<b>', $check['detail'] );
		} finally {
			update_option( 'woocommerce_bacs_settings', $backup );
			WC()->payment_gateways()->init();
		}
	}

	public function test_payment_warn_detail_tells_where_to_turn_one_on(): void {
		$backup  = array();
		$enabled = array();
		// WC `init()` önceki testlerin (Checkout / OrderPayGuard) kaydettiği geçit nesnelerini listeden silmez; bunlar
		// `enabled = yes` değerini kurucuda sabitler. Seçenekler yetmez: bellekteki nesnelerin açık/kapalı değeri de kapatılır.
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$key            = 'woocommerce_' . $id . '_settings';
			$backup[ $key ] = get_option( $key );
			$opts           = is_array( $backup[ $key ] ) ? $backup[ $key ] : array();
			update_option( $key, array_merge( $opts, array( 'enabled' => 'no' ) ) );
		}
		WC()->payment_gateways()->init();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$enabled[ $id ]   = $gateway->enabled;
			$gateway->enabled = 'no';
		}
		try {
			$check = SetupStatus::checks()['payment'];
			$this->assertSame( 'warn', $check['state'] );
			$this->assertSame( 'Turn one on in WooCommerce → Settings → Payments.', $check['detail'] );
		} finally {
			foreach ( $backup as $key => $value ) {
				false === $value ? delete_option( $key ) : update_option( $key, $value );
			}
			WC()->payment_gateways()->init();
			foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
				if ( isset( $enabled[ $id ] ) ) {
					$gateway->enabled = $enabled[ $id ];
				}
			}
		}
	}

	/**
	 * Maliyet (pre-ZIP Fable 7): tamamlık yalnız üç zorunlu kontrolü hesaplar (ödeme yöntemlerine dokunmaz; etkinleştirme
	 * sonrası admin_init yolunda da çalışır); Randevular özeti kontrolleri BİR kez hesaplar.
	 */
	public function test_is_complete_skips_optional_checks_and_summary_computes_once(): void {
		update_option( 'timezone_string', 'Europe/Istanbul' );
		$calls = 0;
		$count = static function ( $v ) use ( &$calls ) {
			++$calls;
			return $v;
		};
		add_filter( 'option_timezone_string', $count );
		SetupStatus::isComplete();
		$this->assertSame( 0, $calls, 'isComplete isteğe bağlı kontrolleri (saat dilimi, ödeme, lisans) hesaplamaz' );
		SetupStatus::checks();
		$one = $calls;
		$this->assertGreaterThan( 0, $one );
		$calls = 0;
		\MHM\Slotiva\Setup\SetupRest::setupSummary();
		$this->assertSame( $one, $calls, 'özet kontrolleri bir kez hesaplar' );
		remove_filter( 'option_timezone_string', $count );
	}

	/**
	 * Yönetim bağlamında WC oturumu yok: `get_available_payment_gateways()` kullanılmaz (WC sözleşmesi,
	 * class-wc-payment-gateways.php; pre-ZIP Codex 4 / Fable 7b). Oturuma dayanan bir süzgeç hata fırlatsa da kontrol çalışır.
	 */
	public function test_payment_check_does_not_use_session_bound_available_gateways(): void {
		$boom = static function () {
			throw new \RuntimeException( 'WC()->session is null' );
		};
		add_filter( 'woocommerce_available_payment_gateways', $boom );
		try {
			$check = SetupStatus::checks()['payment'];
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', $boom );
		}
		$this->assertContains( $check['state'], array( 'ok', 'warn' ) );
	}

	public function test_draft_bookable_product_does_not_count(): void {
		$this->product( 'draft' );
		$this->assertSame( 'todo', SetupStatus::checks()['product']['state'] );
	}

	public function test_checkout_missing_page_links_to_wc_advanced_settings(): void {
		update_option( 'woocommerce_checkout_page_id', 0 );
		$check = SetupStatus::checks()['checkout'];
		$this->assertSame( 'todo', $check['state'] );
		$this->assertStringContainsString( 'wc-settings&tab=advanced', $check['action'] );
		$this->assertStringContainsString( 'wc-settings&tab=advanced', SetupStatus::checkoutAction() );
	}

	public function test_checkout_with_page_links_to_editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'klasik',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $id );
		$this->assertStringContainsString( 'post=' . $id, SetupStatus::checkoutAction() );
	}

	public function test_state_defaults(): void {
		$this->assertSame(
			array(
				'completed' => false,
				'dismissed' => false,
			),
			SetupStatus::state()
		);
		update_option(
			SetupStatus::OPTION,
			array(
				'completed_at' => 1700000000,
				'dismissed'    => true,
			)
		);
		$this->assertSame(
			array(
				'completed' => true,
				'dismissed' => true,
			),
			SetupStatus::state()
		);
		$this->assertArrayNotHasKey( 'license', SetupStatus::checks() );
	}
}
