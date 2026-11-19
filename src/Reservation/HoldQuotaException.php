<?php
/**
 * Alma kotası aşıldı (spec §15.8): aynı WC oturumu ya da aynı IP özeti için etkin `held` en fazla 2 sipariş.
 * İşlem tamamen geri alındı. Çağıran 429 `slotiva_hold_quota`.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

defined( 'ABSPATH' ) || exit;

/**
 * Kota aşımı. `scope` = session|ip, `count` = sayılan diğer etkin tutma siparişleri.
 */
final class HoldQuotaException extends \RuntimeException {

	/**
	 * Aşılan kapsam: session|ip.
	 *
	 * @var string
	 */
	public readonly string $scope;

	/**
	 * Sayılan diğer etkin tutma siparişleri.
	 *
	 * @var int
	 */
	public readonly int $count;

	/**
	 * Kurar.
	 *
	 * @param string $scope session|ip.
	 * @param int    $count Sayı.
	 */
	public function __construct( string $scope, int $count ) {
		parent::__construct( 'slotiva_hold_quota' );
		$this->scope = $scope;
		$this->count = $count;
	}
}
