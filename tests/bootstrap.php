<?php
/**
 * PHPUnit bootstrap: WP test kütüphanesi (WP_TESTS_DIR) + WooCommerce + mhm-slotiva.
 *
 * @package MHM\Slotiva\Tests
 */

$slotiva_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $slotiva_tests_dir ) {
	$slotiva_tests_dir = '/opt/wordpress-tests-lib';
}

if ( ! file_exists( $slotiva_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP test kütüphanesi bulunamadı: {$slotiva_tests_dir}\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Test kapıları (ör. ActivationRedirect::overrideConstantsForTesting) yalnız bu sabitle etkilidir (pre-ZIP gate G1).
define( 'SLOTIVA_TESTING', true );
require_once $slotiva_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		$wp_content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( ABSPATH ) . '/wp-content';
		require $wp_content . '/plugins/woocommerce/woocommerce.php';
		require dirname( __DIR__ ) . '/mhm-slotiva.php';
	}
);

/*
 * WC'nin test işareti: wc_get_product_visibility_term_ids() kimlikleri süreç boyunca statik saklar, bu sınıf varsa
 * saklamaz. WP 7.2 test kütüphanesi terimleri her sınıftan önce siler, Support\WooInstallState yeniden kurar →
 * kimlikler değişir, statik önbellek eskiyi döndürürdü.
 */
if ( ! class_exists( 'WC_Unit_Tests_Bootstrap', false ) ) {
	class WC_Unit_Tests_Bootstrap {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- WC'nin aradığı ad.
}

require $slotiva_tests_dir . '/includes/bootstrap.php';
