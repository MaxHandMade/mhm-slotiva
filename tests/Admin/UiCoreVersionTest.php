<?php
/**
 * Ui-core kayıt sürümü (mhm-slotiva.php `mhmuicore_register( '<sürüm>' )`) vendor kopyasının kendi sürümüne eşit olmalı:
 * kayıt defteri en yüksek sürümü açar, eksik bildirmek eski bir kopyayı kazandırır (Rentiva Pro bin/check-uicore-version.php).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Admin;

use WP_UnitTestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- yerel dosya okuma.

class UiCoreVersionTest extends WP_UnitTestCase {

	public function test_registered_ui_core_version_matches_vendored_copy(): void {
		$root      = dirname( __DIR__, 2 );
		$bootstrap = (string) file_get_contents( $root . '/vendor/mhm/ui-core/bootstrap.php' );
		$plugin    = (string) file_get_contents( $root . '/mhm-slotiva.php' );

		$this->assertSame( 1, preg_match( "/define\(\s*'MHMUICORE_VERSION',\s*'([^']+)'\s*\)/", $bootstrap, $vendored ), 'vendor kopyası MHMUICORE_VERSION tanımlar' );
		$this->assertSame( 1, preg_match( "/mhmuicore_register\(\s*'([^']+)'/", $plugin, $registered ), 'mhm-slotiva.php mhmuicore_register() çağırır' );
		$this->assertSame( $vendored[1], $registered[1], 'mhmuicore_register() sürümü vendor/mhm/ui-core sürümüyle aynı olmalı' );

		// Çalışma anı: bu kopya kazandıysa sabit de aynı sürümü söyler.
		if ( defined( 'MHMUICORE_VERSION' ) ) {
			$this->assertTrue( version_compare( MHMUICORE_VERSION, $registered[1], '>=' ) );
		}
	}
}
