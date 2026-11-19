<?php
/**
 * Checkout block integration (slot picker script loader).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;
use MHM\Slotiva\Checkout\BlockRegistration;
use WP_UnitTestCase;

class BlockRegistrationTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'slotiva_contact_url' );
		wp_deregister_script( BlockRegistration::HANDLE );
		wp_dequeue_style( BlockRegistration::HANDLE );
		wp_deregister_style( BlockRegistration::HANDLE );
		parent::tear_down();
	}

	public function test_registers_with_checkout_block_registry(): void {
		$registry = new IntegrationRegistry();
		$registry->initialize( 'checkout_block' );

		$this->assertTrue( $registry->is_registered( BlockRegistration::HANDLE ) );
		$this->assertArrayHasKey( BlockRegistration::HANDLE . '_data', $registry->get_all_registered_script_data() );
	}

	public function test_contact_url_defaults_to_store_sender_address(): void {
		update_option( 'woocommerce_email_from_address', 'shop@example.test' );
		$data = ( new BlockRegistration() )->get_script_data();
		$this->assertSame( 'mailto:shop@example.test', $data['contactUrl'] );
	}

	public function test_contact_url_filter_and_scheme_whitelist(): void {
		add_filter( 'slotiva_contact_url', static fn() => 'https://example.test/contact' );
		$this->assertSame( 'https://example.test/contact', BlockRegistration::contact_url() );

		remove_all_filters( 'slotiva_contact_url' );
		add_filter( 'slotiva_contact_url', static fn() => 'javascript:alert(1)' );
		$this->assertSame( '', BlockRegistration::contact_url() );
	}

	public function test_initialize_registers_script_with_wc_dependencies(): void {
		if ( ! is_readable( SLOTIVA_DIR . '/build/checkout/index.asset.php' ) ) {
			$this->markTestSkipped( 'JS build missing (npm run build).' );
		}
		$integration = new BlockRegistration();
		$integration->initialize();

		$this->assertSame( array( BlockRegistration::HANDLE ), $integration->get_script_handles() );
		$this->assertSame( array(), $integration->get_editor_script_handles() );
		$deps = wp_scripts()->registered[ BlockRegistration::HANDLE ]->deps;
		foreach ( array( 'wc-blocks-checkout', 'wc-settings', 'wc-blocks-data-store', 'wp-data', 'wp-i18n', 'wp-api-fetch' ) as $dep ) {
			$this->assertContains( $dep, $deps );
		}
		$this->assertSame( 'mhm-slotiva', wp_scripts()->registered[ BlockRegistration::HANDLE ]->textdomain );
		$this->assertTrue( wp_style_is( BlockRegistration::HANDLE, 'registered' ) );
	}

	/**
	 * WooCommerce calls initialize() on every request (block type registration), so it must not enqueue anything;
	 * the stylesheet is enqueued only when the Checkout block enqueues its scripts.
	 */
	public function test_style_enqueued_only_with_checkout_block(): void {
		if ( ! is_readable( SLOTIVA_DIR . '/build/checkout/style-index.css' ) ) {
			$this->markTestSkipped( 'JS build missing (npm run build).' );
		}
		wp_dequeue_style( BlockRegistration::HANDLE );
		( new BlockRegistration() )->initialize();
		$this->assertFalse( wp_style_is( BlockRegistration::HANDLE, 'enqueued' ), 'normal page: not enqueued' );

		do_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC kancası.
		$this->assertTrue( wp_style_is( BlockRegistration::HANDLE, 'enqueued' ), 'checkout block: enqueued' );
	}

	public function test_real_registry_initialize_does_not_enqueue_style(): void {
		wp_dequeue_style( BlockRegistration::HANDLE );
		$registry = new IntegrationRegistry();
		$registry->initialize( 'checkout_block' );
		$this->assertFalse( wp_style_is( BlockRegistration::HANDLE, 'enqueued' ) );
	}

	/**
	 * Missing build (no `build/checkout/index.asset.php`): initialize() returns safely and registers nothing.
	 */
	public function test_no_script_without_build(): void {
		wp_deregister_script( BlockRegistration::HANDLE );
		wp_deregister_style( BlockRegistration::HANDLE );
		$missing = get_temp_dir() . 'slotiva-no-build-' . wp_generate_password( 8, false );
		$this->assertFileDoesNotExist( $missing . '/build/checkout/index.asset.php' );

		$integration = new BlockRegistration( $missing );
		$integration->initialize();

		$this->assertSame( array(), $integration->get_script_handles() );
		$this->assertFalse( wp_script_is( BlockRegistration::HANDLE, 'registered' ) );
		$this->assertFalse( wp_style_is( BlockRegistration::HANDLE, 'registered' ) );
	}
}
