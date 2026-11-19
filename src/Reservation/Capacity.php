<?php
/**
 * Kapasite kaynağı: ürün başına koltuk sayısının tek okuma noktası.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar ve yöntem adları sözleşmeyle camelCase.

/**
 * Kapasite yalnız nötr `slotiva_product_capacity` süzgecinden gelir; 1..MAX aralığına sıkıştırılır.
 */
final class Capacity {

	/**
	 * Üst sınır.
	 */
	public const MAX = 50;

	/**
	 * Ürünün güncel kapasitesi (süzgeç varsayılanı 1).
	 *
	 * @param int $productId Ürün kimliği.
	 */
	public static function forProduct( int $productId ): int {
		$value = (int) apply_filters( 'slotiva_product_capacity', 1, $productId );
		return max( 1, min( self::MAX, $value ) );
	}

	/**
	 * Geçerli kapasite: anlık görüntü yoksa güncel değer, varsa ikisinin küçüğü (silinmiş ürün dahil).
	 *
	 * @param int      $productId Ürün kimliği.
	 * @param int|null $snapshot  Rezervasyondaki kapasite anlık görüntüsü.
	 */
	public static function effective( int $productId, ?int $snapshot ): int {
		$current = self::forProduct( $productId );
		if ( null === $snapshot ) {
			return $current;
		}
		return min( max( 1, min( self::MAX, $snapshot ) ), $current );
	}
}
