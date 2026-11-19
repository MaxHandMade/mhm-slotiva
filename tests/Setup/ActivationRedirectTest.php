<?php
/**
 * Etkinleştirme yönlendirmesi (1.0.2): bir kez, yalnız uygun bağlamda.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Setup;

use MHM\Slotiva\Admin\Menu;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Setup\ActivationRedirect;
use MHM\Slotiva\Setup\SetupStatus;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek `wp_redirect` süzgeci.
// phpcs:disable WordPress.WP.Capabilities.Unknown -- WooCommerce yetkisi.

class ActivationRedirectTest extends SlotivaTestCase {

	/**
	 * Yakalanan yönlendirme konumları.
	 *
	 * @var string[]
	 */
	private array $redirects = array();

	/**
	 * Test öncesi ödeme sayfası seçeneği.
	 *
	 * @var mixed
	 */
	private $checkout_page;

	/**
	 * Test öncesi `$pagenow`.
	 *
	 * @var string|null
	 */
	private $pagenow;

	/**
	 * Etkinleştiren yönetici (bayrağın sahibi).
	 *
	 * @var int
	 */
	private int $activator = 0;

	public function set_up(): void {
		parent::set_up();
		$this->redirects     = array();
		$this->pagenow       = $GLOBALS['pagenow'] ?? null;
		$this->checkout_page = get_option( 'woocommerce_checkout_page_id' );
		delete_option( WeeklySchedule::OPTION );
		update_option( 'woocommerce_checkout_page_id', 0 );
		// Konumu yakalar, false döndürerek başlık göndermeyi ve çıkışı engeller (üretimde süzgeç yok).
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->redirects[] = (string) $location;
				return false;
			}
		);
		$this->activator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->activator );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::setPagenow( 'index.php' );
		ActivationRedirect::arm();
	}

	/**
	 * `$pagenow` testte elle kurulur (admin-post.php bağlamı).
	 *
	 * @param string|null $page Sayfa.
	 */
	private static function setPagenow( ?string $page ): void {
		$GLOBALS['pagenow'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test bağlamı, tear_down geri yükler.
	}

	public function tear_down(): void {
		update_option( 'woocommerce_checkout_page_id', $this->checkout_page );
		ActivationRedirect::overrideConstantsForTesting( null );
		unset( $_GET['activate-multi'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::setPagenow( $this->pagenow );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_redirect_once_to_setup_and_clears_option(): void {
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array( Menu::setupUrl() ), $this->redirects );
		$this->assertFalse( get_option( ActivationRedirect::OPTION ), 'bayrak silindi' );

		ActivationRedirect::maybeRedirect();
		$this->assertCount( 1, $this->redirects, 'ikinci yüklemede yönlendirme yok' );
	}

	public function test_no_redirect_unless_armed(): void {
		delete_option( ActivationRedirect::OPTION );
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects );
	}

	public function test_bulk_activation_skips_and_disarms(): void {
		$_GET['activate-multi'] = 'true';
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects );
		$this->assertFalse( get_option( ActivationRedirect::OPTION ), 'toplu etkinleştirme sonradan yönlendirmez' );
	}

	public function test_ajax_rest_and_cli_skip_but_keep_flag_for_next_admin_page(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		ActivationRedirect::maybeRedirect();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertSame( array(), $this->redirects, 'AJAX' );

		foreach ( array( 'REST_REQUEST', 'WP_CLI' ) as $name ) {
			ActivationRedirect::overrideConstantsForTesting( array( $name => true ) );
			ActivationRedirect::maybeRedirect();
			$this->assertSame( array(), $this->redirects, $name );
		}
		$this->assertSame( (string) $this->activator, (string) get_option( ActivationRedirect::OPTION ), 'bayrak korunur' );

		ActivationRedirect::overrideConstantsForTesting( null );
		ActivationRedirect::maybeRedirect();
		$this->assertCount( 1, $this->redirects, 'sonraki normal yüklemede yönlenir' );
	}

	/**
	 * Form gönderimi, admin-post.php, iframe ve WP-Cron isteği yönlendirilmez (gönderim düşmesin); bayrak sonraki
	 * normal yönetim sayfasına kalır.
	 */
	public function test_post_admin_post_iframe_and_cron_skip_but_keep_flag(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects, 'POST' );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		self::setPagenow( 'admin-post.php' );
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects, 'admin-post.php' );
		self::setPagenow( 'index.php' );

		ActivationRedirect::overrideConstantsForTesting( array( 'IFRAME_REQUEST' => true ) );
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects, 'IFRAME_REQUEST' );
		ActivationRedirect::overrideConstantsForTesting( null );

		add_filter( 'wp_doing_cron', '__return_true' );
		ActivationRedirect::maybeRedirect();
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertSame( array(), $this->redirects, 'cron' );

		$this->assertSame( (string) $this->activator, (string) get_option( ActivationRedirect::OPTION ), 'bayrak korunur' );
		ActivationRedirect::maybeRedirect();
		$this->assertCount( 1, $this->redirects, 'sonraki GET yönetim sayfasında yönlenir' );
	}

	/** Bayrak etkinleştiren kullanıcıya bağlıdır; başka yönetici yönlenmez, bayrak sahibini bekler (pre-ZIP Fable 3). */
	public function test_flag_belongs_to_the_activating_user(): void {
		$activator = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects, 'başka yönetici' );
		$this->assertSame( (string) $activator, (string) get_option( ActivationRedirect::OPTION ) );

		wp_set_current_user( $activator );
		ActivationRedirect::maybeRedirect();
		$this->assertCount( 1, $this->redirects, 'etkinleştiren kullanıcı' );
	}

	/** WP-CLI, ağ genelinde etkinleştirme ve oturumsuz etkinleştirme bayrak yazmaz (pre-ZIP Fable 3). */
	public function test_cli_network_and_anonymous_activation_do_not_arm(): void {
		delete_option( ActivationRedirect::OPTION );
		ActivationRedirect::overrideConstantsForTesting( array( 'WP_CLI' => true ) );
		ActivationRedirect::arm();
		$this->assertFalse( get_option( ActivationRedirect::OPTION ), 'WP-CLI' );
		ActivationRedirect::overrideConstantsForTesting( null );

		ActivationRedirect::arm( true );
		$this->assertFalse( get_option( ActivationRedirect::OPTION ), 'ağ genelinde' );

		wp_set_current_user( 0 );
		ActivationRedirect::arm();
		$this->assertFalse( get_option( ActivationRedirect::OPTION ), 'oturumsuz' );
	}

	/** Yönlendirme kurulamayan etkinleştirme (oturumsuz, ağ genelinde) eski bayrağı temizler; yeni bayrak yazmaz. */
	public function test_arm_without_user_clears_stale_flag(): void {
		update_option( ActivationRedirect::OPTION, 5 );
		wp_set_current_user( 0 );
		ActivationRedirect::arm();
		$this->assertFalse( get_option( ActivationRedirect::OPTION ), 'oturumsuz: eski bayrak silinir' );

		update_option( ActivationRedirect::OPTION, 5 );
		ActivationRedirect::arm( true );
		$this->assertFalse( get_option( ActivationRedirect::OPTION ), 'ağ genelinde: eski bayrak silinir' );
	}

	public function test_network_admin_skips(): void {
		set_current_screen( 'dashboard-network' );
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects );
	}

	public function test_unauthorized_user_is_not_redirected_and_flag_waits_for_an_authorized_one(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects );
		$this->assertSame( (string) $this->activator, (string) get_option( ActivationRedirect::OPTION ) );
	}

	public function test_complete_setup_skips_and_disarms(): void {
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
		$product = new \WC_Product_Simple();
		$product->set_name( 'Consulting' );
		$product->set_regular_price( '100' );
		$product->set_status( 'publish' );
		$product->update_meta_data( '_slotiva_enabled', 'yes' );
		$product->save();
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/checkout --><div></div><!-- /wp:woocommerce/checkout -->',
			)
		);
		update_option( 'woocommerce_checkout_page_id', $page );
		$this->assertTrue( SetupStatus::isComplete(), 'önkoşul: kurulum tamam' );

		ActivationRedirect::maybeRedirect();
		$this->assertSame( array(), $this->redirects );
		$this->assertFalse( get_option( ActivationRedirect::OPTION ) );
	}
}
