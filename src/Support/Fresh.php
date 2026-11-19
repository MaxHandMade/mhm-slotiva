<?php
/**
 * Taze okuma: kilit/işlem duyarlı kararlarda WC siparişi önbellekten değil veritabanından okunur.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tek yardımcı (Rentiva dersi, 2026-11-10): eski depolamada yazı + meta önbelleği, HPOS'ta sipariş nesnesi önbelleği
 * temizlenir ve sipariş yeniden yüklenir. Kalıcı nesne önbelleği olan sitede başka bir istek siparişi değiştirmiş
 * olabilir; bu yardımcı o değişikliği görür.
 */
final class Fresh {

	/**
	 * Siparişi önbelleksiz yükler. İade nesnesi ya da yok → null.
	 *
	 * @param int $id Sipariş kimliği.
	 */
	public static function order( int $id ): ?\WC_Order {
		if ( $id <= 0 ) {
			return null;
		}
		clean_post_cache( $id );
		wp_cache_delete( $id, 'post_meta' );
		wp_cache_delete( $id, 'posts' );
		if ( class_exists( \Automattic\WooCommerce\Caches\OrderCache::class ) && function_exists( 'wc_get_container' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- önbellek denetleyicisi yoksa temizlenecek bir şey yoktur.
				// Önbellek denetleyicisi kapalı/yok: yükleme zaten veritabanından.
			}
		}
		// WC_Data meta önbelleği ('orders' grubu, nesne meta anahtarı) her iki depolamada da ayrıca tutulur.
		wp_cache_delete( \WC_Order::generate_meta_cache_key( $id, 'orders' ), 'orders' );
		wp_cache_delete( 'order-items-' . $id, 'orders' );
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		$order->read_meta_data( true );
		return $order;
	}
}
