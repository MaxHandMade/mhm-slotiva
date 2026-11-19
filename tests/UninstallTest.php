<?php
/**
 * Kaldırma (uninstall.php) testleri: veri yalnız `deleteDataOnUninstall === true` iken silinir.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class UninstallTest extends SlotivaTestCase {

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mhm-slotiva/mhm-slotiva.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- çekirdeğin kaldırma sabiti; uninstall.php bunu bekler.
		}
		include dirname( __DIR__ ) . '/uninstall.php';
	}

	private function table_exists( string $short ): bool {
		global $wpdb;
		$table = Schema::table( $short );
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- şema denetimi.
	}

	public function test_uninstall_keeps_data_by_default(): void {
		update_option( 'slotiva_schedule', array( 'mon' => array() ) );
		$this->run_uninstall();
		foreach ( array( 'reservations', 'occupancy', 'exceptions', 'events' ) as $short ) {
			$this->assertTrue( $this->table_exists( $short ), "{$short} silindi" );
		}
		$this->assertNotFalse( get_option( 'slotiva_schedule' ) );
	}

	public function test_uninstall_keeps_data_when_rule_false(): void {
		update_option( 'slotiva_rules', array( 'deleteDataOnUninstall' => false ) );
		$this->run_uninstall();
		$this->assertTrue( $this->table_exists( 'reservations' ) );
		$this->assertNotFalse( get_option( 'slotiva_rules' ) );
	}

	public function test_uninstall_drops_when_rule_true(): void {
		update_option( 'slotiva_rules', array( 'deleteDataOnUninstall' => true ) );
		update_option( 'slotiva_db_version', '1' );
		// WP test kütüphanesi DROP TABLE'ı DROP TEMPORARY TABLE'a çevirir; gerçek tablo için süzgeçleri kaldır.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$this->run_uninstall();
			foreach ( array( 'reservations', 'occupancy', 'exceptions', 'events' ) as $short ) {
				$this->assertFalse( $this->table_exists( $short ), "{$short} duruyor" );
			}
			$this->assertFalse( get_option( 'slotiva_rules' ) );
			$this->assertFalse( get_option( 'slotiva_db_version' ) );
		} finally {
			// Sonraki testler için şemayı geri kur.
			Schema::install();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Lite eski lisans seçeneklerini adıyla okumaz/silmez: veri silme KAPALIYKEN kalır; AÇIKKEN genel `slotiva_%`
	 * süpürmesiyle (ad aranmadan) gider.
	 */
	public function test_legacy_license_options_are_left_untouched(): void {
		update_option( 'slotiva_license', array( 'k' => 'x' ) );
		$this->run_uninstall();
		$this->assertSame( array( 'k' => 'x' ), get_option( 'slotiva_license' ), 'bayrak kapalı: dokunulmaz' );

		update_option( 'slotiva_rules', array( 'deleteDataOnUninstall' => true ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$this->run_uninstall();
			$this->assertFalse( get_option( 'slotiva_license' ), 'bayrak açık: önek süpürmesiyle gider' );
		} finally {
			Schema::install();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * `slotivapro_*` seçenek, `_slotivapro_*` meta ve `slotivapro` AS grubu Lite kaldırmasında kalır (`\_` kaçışı).
	 */
	public function test_pro_prefixed_data_survives(): void {
		update_option( 'slotiva_rules', array( 'deleteDataOnUninstall' => true ) );
		$pid = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_option( 'slotivapro_x', 1 );
		update_post_meta( $pid, '_slotivapro_capacity', 3 );
		as_schedule_single_action( time() + 600, 'slotivapro_test_job', array(), 'slotivapro' );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$this->run_uninstall();
			$this->assertSame( 1, (int) get_option( 'slotivapro_x' ) );
			$this->assertSame( '3', get_post_meta( $pid, '_slotivapro_capacity', true ) );
			$this->assertSame( 1, self::pending_in( 'slotivapro' ) );
		} finally {
			Schema::install();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			as_unschedule_all_actions( '', array(), 'slotivapro' );
		}
	}

	/**
	 * Gruptaki bekleyen işler.
	 *
	 * @param string $group AS grubu.
	 */
	private static function pending_in( string $group ): int {
		return count(
			as_get_scheduled_actions(
				array(
					'group'    => $group,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			)
		);
	}

	/**
	 * (Final review F7 / m-3, T10 M4) Kaldırma, veri silme bayrağından bağımsız olarak `slotiva` grubundaki TÜM işleri
	 * kaldırır (süpürücü, süre dolumu, hatırlatma, sipariş eşitleme, saklama); başka grubun işine dokunmaz.
	 */
	public function test_uninstall_unschedules_every_slotiva_job(): void {
		as_schedule_recurring_action( time() + 300, 300, 'slotiva_sweep', array(), 'slotiva' );
		as_schedule_single_action(
			time() + 600,
			'slotiva_hold_expire',
			array(
				'reservation_id'  => 1,
				'sequence'        => 0,
				'hold_expires_at' => '2026-11-17 07:00:00',
			),
			'slotiva'
		);
		as_schedule_single_action(
			time() + 600,
			'slotiva_reminder',
			array(
				'reservation_id' => 1,
				'sequence'       => 0,
			),
			'slotiva'
		);
		as_schedule_single_action(
			time() + 60,
			'slotiva_order_sync',
			array(
				'order_id' => 5,
				'attempt'  => 1,
			),
			'slotiva'
		);
		as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'slotiva_retention', array(), 'slotiva' );
		as_unschedule_all_actions( '', array(), 'slotiva-test-other' );
		as_schedule_single_action( time() + 600, 'slotiva_test_other_plugin', array(), 'slotiva-test-other' );
		$this->assertSame( 5, self::pending_in( 'slotiva' ) );

		try {
			$this->run_uninstall();

			$this->assertSame( 0, self::pending_in( 'slotiva' ) );
			$this->assertSame( 1, self::pending_in( 'slotiva-test-other' ), 'başka grubun işi kalır' );
			$this->assertTrue( $this->table_exists( 'reservations' ), 'veri yine kalır (bayrak kapalı)' );
		} finally {
			as_unschedule_all_actions( '', array(), 'slotiva-test-other' );
		}
	}

	/**
	 * (Final review F7) Veri silme bayrağı açıkken ürün metası `_slotiva_*`, WC e-posta ayarları `woocommerce_slotiva_*`
	 * ve Slotiva geçici kayıtları da silinir; başka meta/seçenek kalır.
	 */
	public function test_uninstall_with_flag_removes_product_meta_email_settings_and_transients(): void {
		update_option( 'slotiva_rules', array( 'deleteDataOnUninstall' => true ) );
		$pid = self::factory()->post->create( array( 'post_type' => 'product' ) );
		update_post_meta( $pid, '_slotiva_enabled', 'yes' );
		update_post_meta( $pid, '_slotiva_duration', '30' );
		update_post_meta( $pid, '_price', '100' );
		update_option( 'woocommerce_slotiva_customer_confirmed_settings', array( 'enabled' => 'no' ) );
		update_option( 'woocommerce_new_order_settings', array( 'enabled' => 'yes' ) );
		set_transient( 'slotiva_avail_test', array( 1 ), 600 );
		set_transient( 'other_plugin_cache', 1, 600 );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$this->run_uninstall();
			$this->assertSame( '', get_post_meta( $pid, '_slotiva_enabled', true ) );
			$this->assertSame( '', get_post_meta( $pid, '_slotiva_duration', true ) );
			$this->assertSame( '100', get_post_meta( $pid, '_price', true ), 'başka meta kalır' );
			$this->assertFalse( get_option( 'woocommerce_slotiva_customer_confirmed_settings' ) );
			$this->assertNotFalse( get_option( 'woocommerce_new_order_settings' ), 'WC kendi ayarı kalır' );
			$this->assertFalse( get_transient( 'slotiva_avail_test' ) );
			$this->assertSame( 1, get_transient( 'other_plugin_cache' ) );
		} finally {
			Schema::install();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
			delete_option( 'woocommerce_new_order_settings' );
			delete_transient( 'other_plugin_cache' );
		}
	}
}
