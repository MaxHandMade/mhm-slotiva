<?php
/**
 * Eklenti açılış testi.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests;

use WP_UnitTestCase;

class PluginBootTest extends WP_UnitTestCase {

	public function test_plugin_boots_and_defines_version(): void {
		$this->assertTrue( did_action( 'plugins_loaded' ) > 0 );
		$this->assertSame( '1.1.0', SLOTIVA_VERSION );
		$this->assertTrue( class_exists( \MHM\Slotiva\Plugin::class ) );
	}

	public function test_woocommerce_loaded_in_tests(): void {
		$this->assertTrue( function_exists( 'WC' ) );
	}
}
