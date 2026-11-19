<?php
/**
 * Plugin Name:          MHM Slotiva
 * Description:          WooCommerce service appointment booking: time slots chosen at checkout, payment-aware holds, an admin calendar and reminders.
 * Version:              1.1.0
 * Requires at least:    7.0
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 11.2
 * WC tested up to:      11.3
 * Author:               MaxHandMade
 * Author URI:           https://wpalemi.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          mhm-slotiva
 * Domain Path:          /languages
 *
 * @package MHM\Slotiva
 */

defined( 'ABSPATH' ) || exit;

define( 'SLOTIVA_VERSION', '1.1.0' );
define( 'SLOTIVA_FILE', __FILE__ );
define( 'SLOTIVA_DIR', __DIR__ );
define( 'SLOTIVA_URL', plugin_dir_url( __FILE__ ) );

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	// composer install yapılmadan yüklenmiş paket: ölümcül hata yerine yöneticiye uyarı.
	add_action(
		'admin_notices',
		static function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'MHM Slotiva is missing its dependencies (vendor/). Run composer install.', 'mhm-slotiva' ) . '</p></div>';
			}
		}
	);
	return;
}

require __DIR__ . '/vendor/autoload.php';

// ui-core (yönetim kiti + React sayfa yükleyicisi): sürüm farkındalıklı kayıt; plugins_loaded/0'da en yüksek kopya açılır.
// Sayı vendor/mhm/ui-core/bootstrap.php MHMUICORE_VERSION ile aynı olmalı (eksik bildirmek eski kopyayı kazandırır).
if ( is_readable( __DIR__ . '/vendor/mhm/ui-core/register.php' ) ) {
	require_once __DIR__ . '/vendor/mhm/ui-core/register.php';
	mhmuicore_register( '0.23.0', __DIR__ . '/vendor/mhm/ui-core/bootstrap.php' );
}

register_activation_hook(
	SLOTIVA_FILE,
	static function ( $network_wide = false ): void {
		MHM\Slotiva\Db\Schema::install();
		MHM\Slotiva\Setup\ActivationRedirect::arm( (bool) $network_wide );
	}
);

// WooCommerce uyumluluk bildirimi: HPOS + blok tabanlı sepet/ödeme.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SLOTIVA_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SLOTIVA_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		MHM\Slotiva\Plugin::boot();
	}
);
