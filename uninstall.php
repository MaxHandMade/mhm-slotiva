<?php
/**
 * Eklenti silinirken çalışır. Zamanlanmış işler her zaman silinir. Veri YALNIZ `slotiva_rules.deleteDataOnUninstall === true` ise silinir (varsayılan: kalır).
 *
 * Eklenti bu noktada yüklenmemiştir (autoload yok): ham seçenek dizisi okunur, tablo adları burada türetilir.
 *
 * @package MHM\Slotiva
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

( static function () {
	global $wpdb;

	// Slotiva'nın TÜM zamanlanmış işleri (`slotiva` grubu: süpürücü, süre dolumu, hatırlatma, sipariş eşitleme, saklama)
	// her kaldırmada silinir: geri çağrısı kalmayan iş "no callbacks are registered" ile başarısız olur, yinelenenler
	// beş başarısızlıktan sonra durup Araçlar → Zamanlanmış eylemler'de iz bırakırdı (final review F7). Başka grup kalır.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'slotiva' );
	}
	delete_option( 'slotiva_sweep_checked' );
	delete_option( 'slotiva_retention_checked' );

	$rules = get_option( 'slotiva_rules' );
	if ( ! is_array( $rules ) || true !== ( $rules['deleteDataOnUninstall'] ?? false ) ) {
		return;
	}

	// Ürün metası `_slotiva_*` (yalnız ürün ve varyasyonlarda; siparişlerdeki okunur kopya sipariş geçmişidir, kalır).
	$product_meta = $wpdb->get_results( "SELECT pm.post_id, pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE '\\_slotiva\\_%' AND p.post_type IN ('product', 'product_variation')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- kaldırma (yalnız "verileri sil" seçiliyse): değişkensiz sabit LIKE sorgusu, tek seferlik; önbellek anlamsız.
	foreach ( (array) $product_meta as $row ) {
		delete_post_meta( (int) $row->post_id, (string) $row->meta_key );
	}

	// WC e-posta ayarları (`woocommerce_slotiva_*_settings`) ve Slotiva geçici kayıtları.
	$wc_names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce\\_slotiva\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- kaldırma (yalnız "verileri sil" seçiliyse): değişkensiz sabit LIKE sorgusu, tek seferlik; önbellek anlamsız.
	foreach ( (array) $wc_names as $name ) {
		delete_option( $name );
	}
	$transients = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_slotiva\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- kaldırma (yalnız "verileri sil" seçiliyse): değişkensiz sabit LIKE sorgusu, tek seferlik; önbellek anlamsız.
	foreach ( (array) $transients as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}

	foreach ( array( 'reservations', 'occupancy', 'exceptions', 'events' ) as $short ) {
		$table = $wpdb->prefix . 'slotiva_' . $short;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- kaldırma (yalnız kullanıcı "verileri sil" seçtiyse): kendi tablolarımızı siler; WordPress'te DDL API'si yok.
	}

	$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'slotiva\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- kaldırma (yalnız "verileri sil" seçiliyse): değişkensiz sabit LIKE sorgusu, tek seferlik; önbellek anlamsız.
	foreach ( (array) $names as $name ) {
		delete_option( $name );
	}
} )();
