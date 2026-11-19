<?php
/**
 * Randevulu ürün: ürün metasından okunan değişmez görünüm.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Product;

use MHM\Slotiva\Schedule\Rules;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- alan adları sözleşmeyle camelCase (spec §5).

/**
 * Bookable yerel tarihler: `bugün_yerel … bugün_yerel + horizonDays` (takvim günü, uçlar dahil); `leadHours` saat cinsinden anlıktır.
 */
final class BookableProduct {

	/**
	 * Yalnız `from()` kurar.
	 *
	 * @param int    $productId   Meta sahibi ürün (varyasyonda ebeveyn).
	 * @param int    $variationId Varyasyon kimliği; yoksa 0.
	 * @param int    $durationMin Dilim süresi (dk).
	 * @param int    $minSlots    En az dilim.
	 * @param int    $maxSlots    En çok dilim (`fixed` ise 1).
	 * @param string $pricing     per_slot|fixed.
	 * @param string $location    Yer metni.
	 * @param int    $leadHours   Ön süre (saat).
	 * @param int    $horizonDays Ufuk (gün).
	 * @param string $policyText  Ürün politikası; boşsa genel kural.
	 */
	private function __construct(
		public readonly int $productId,
		public readonly int $variationId,
		public readonly int $durationMin,
		public readonly int $minSlots,
		public readonly int $maxSlots,
		public readonly string $pricing,
		public readonly string $location,
		public readonly int $leadHours,
		public readonly int $horizonDays,
		public readonly string $policyText
	) {}

	/**
	 * Ürün randevulu değilse null. Varyasyon ebeveynin metasını kullanır.
	 *
	 * @param \WC_Product $p Ürün veya varyasyon.
	 */
	public static function from( \WC_Product $p ): ?self {
		$variation_id = 0;
		$owner        = $p;
		if ( $p->is_type( 'variation' ) && $p->get_parent_id() ) {
			$parent = wc_get_product( $p->get_parent_id() );
			if ( ! $parent ) {
				return null;
			}
			$variation_id = $p->get_id();
			$owner        = $parent;
		}
		if ( 'yes' !== $owner->get_meta( '_slotiva_enabled' ) ) {
			return null;
		}

		$rules   = Rules::load();
		$pricing = 'fixed' === $owner->get_meta( '_slotiva_pricing' ) ? 'fixed' : 'per_slot';
		$min     = min( 10, max( 1, self::int_meta( $owner, '_slotiva_min_slots', 1 ) ) );
		$max     = min( 10, max( $min, self::int_meta( $owner, '_slotiva_max_slots', 1 ) ) );
		if ( 'fixed' === $pricing ) {
			$min = 1;
			$max = 1;
		}
		$policy = (string) $owner->get_meta( '_slotiva_policy' );
		$policy = '' !== trim( $policy ) ? $policy : $rules->policyText;

		// Meta sekme dışından da yazılabilir (içe aktarma, REST, doğrudan DB): dilim mantığına geçmeden normalize edilir.
		$duration = self::int_meta( $owner, '_slotiva_duration', $rules->durationDefault );
		if ( $duration < Rules::STEP || 0 !== $duration % Rules::STEP ) {
			$duration = $rules->durationDefault;
		}
		$lead    = max( 0, self::int_meta( $owner, '_slotiva_lead_hours', $rules->leadHours ) );
		$horizon = min( 62, max( 1, self::int_meta( $owner, '_slotiva_horizon_days', $rules->horizonDays ) ) );

		return new self(
			$owner->get_id(),
			$variation_id,
			$duration,
			$min,
			$max,
			$pricing,
			(string) $owner->get_meta( '_slotiva_location' ),
			$lead,
			$horizon,
			$policy
		);
	}

	/**
	 * Boş/sayısal olmayan meta varsayılana düşer.
	 *
	 * @param \WC_Product $p       Meta sahibi.
	 * @param string      $key     Meta anahtarı.
	 * @param int         $fallback Varsayılan.
	 */
	private static function int_meta( \WC_Product $p, string $key, int $fallback ): int {
		$v = $p->get_meta( $key );
		return is_numeric( $v ) ? (int) $v : $fallback;
	}
}
