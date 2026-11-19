<?php
/**
 * WC kurulum verisini (terimler + rol yetkileri) her test sınıfından önce geri getirir.
 *
 * WP 7.2 test kütüphanesi (trunk, 2026-11) set_up_before_class() içinde _delete_all_data() çağırır: WC'nin
 * kurulumda yazdığı product_visibility / product_type terimleri her sınıftan önce silinir. Aynı sürümde bellekteki
 * WP_Roles nesnesi kurulum öncesinden kalır (shop_manager DB'de 93, bellekte 39 yetki; edit_products yok).
 * Ölçüm: yerel trunk kopyası, WC 11.2.2 — run 42629021700'deki 3 failure birebir yeniden üretildi.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

use WC_Install;

trait WooInstallState {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::restore_woo_install_state();
	}

	/**
	 * Silinen WC terimlerini yeniden kurar (var olanı atlar), rol nesnesini DB'den tazeler.
	 * Terim kimlikleri değiştiği için WC'nin kimlik önbelleği tests/bootstrap.php'deki işaretle kapalıdır.
	 */
	private static function restore_woo_install_state(): void {
		WC_Install::create_terms();
		wp_roles()->for_site();
	}
}
