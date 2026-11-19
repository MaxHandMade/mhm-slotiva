<?php
/**
 * Yönetici uyarıları (spec §6.4, senaryo 17): PayTR başarı durumu ödenmiş sayılmıyorsa; ödeme sayfası blok değilse.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Lifecycle;

use MHM\Slotiva\Lifecycle\GatewayNotice;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class GatewayNoticeTest extends SlotivaTestCase {

	private const PAYTR = 'woocommerce_paytr_payment_gateway_settings';

	/**
	 * Ödeme sayfası seçeneğinin test öncesi değeri.
	 *
	 * @var mixed
	 */
	private $checkout_page;

	public function set_up(): void {
		parent::set_up();
		$this->checkout_page = get_option( 'woocommerce_checkout_page_id' );
		$this->use_checkout_page( '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' );
	}

	public function tear_down(): void {
		update_option( 'woocommerce_checkout_page_id', $this->checkout_page );
		parent::tear_down();
	}

	private function use_checkout_page( string $content ): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		update_option( 'woocommerce_checkout_page_id', $id );
	}

	public function test_gateway_notice_for_on_hold_setting(): void {
		$this->assertArrayNotHasKey( 'paytr_status', GatewayNotice::messages(), 'PayTR yokken uyarı yok' );

		update_option(
			self::PAYTR,
			array(
				'enabled'            => 'yes',
				'paytr_order_status' => 'wc-on-hold',
			)
		);
		$messages = GatewayNotice::messages();
		$this->assertArrayHasKey( 'paytr_status', $messages );
		$this->assertStringContainsString( 'PayTR', $messages['paytr_status'] );

		update_option(
			self::PAYTR,
			array(
				'enabled'            => 'yes',
				'paytr_order_status' => 'wc-completed',
			)
		);
		$this->assertArrayNotHasKey( 'paytr_status', GatewayNotice::messages(), 'completed ödenmiş sayılır' );

		update_option(
			self::PAYTR,
			array(
				'enabled'            => 'no',
				'paytr_order_status' => 'wc-on-hold',
			)
		);
		$this->assertArrayNotHasKey( 'paytr_status', GatewayNotice::messages(), 'kapalı ağ geçidi' );
	}

	public function test_gateway_notice_for_classic_checkout(): void {
		$this->assertArrayNotHasKey( 'classic_checkout', GatewayNotice::messages(), 'blok ödeme sayfası' );
		$this->use_checkout_page( '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->' );
		$this->assertArrayHasKey( 'classic_checkout', GatewayNotice::messages() );
	}

	public function test_notices_render_only_for_shop_managers(): void {
		$this->use_checkout_page( '[woocommerce_checkout]' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		GatewayNotice::render();
		$this->assertSame( '', (string) ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		GatewayNotice::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'MHM Slotiva', $html );
		$this->assertSame( 10, has_action( 'admin_notices', array( GatewayNotice::class, 'render' ) ) );
	}
}
