<?php
/**
 * Zaman kaynağı: üretimde gerçek UTC saati, testte dondurulabilir saat.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tüm "şimdi" okumaları buradan geçer; böylece süre dolumu, en erken/en geç kuralı ve hatırlatma testlenebilir.
 */
final class Clock {

	/**
	 * Dondurulmuş an (yalnız test); null = gerçek saat.
	 *
	 * @var \DateTimeImmutable|null
	 */
	private static ?\DateTimeImmutable $frozen = null;

	/**
	 * Şimdiki an, UTC.
	 */
	public static function now(): \DateTimeImmutable {
		return self::$frozen ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Saati dondurur ya da (null) serbest bırakır. Yalnız testler çağırır.
	 *
	 * @param \DateTimeImmutable|null $at Dondurulacak an; verilen an UTC'ye çevrilir.
	 */
	public static function freeze( ?\DateTimeImmutable $at ): void {
		self::$frozen = $at ? $at->setTimezone( new \DateTimeZone( 'UTC' ) ) : null;
	}
}
