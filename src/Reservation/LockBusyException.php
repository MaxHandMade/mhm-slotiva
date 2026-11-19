<?php
/**
 * Adlı MySQL kilidi (GET_LOCK) zamanında alınamadı: karar VERİLEMEDİ (asla "boş" sayılmaz). Çağıran 409 `slotiva_busy`.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

defined( 'ABSPATH' ) || exit;

/**
 * Kilit meşgul. `lock` = alınamayan kilit adı (ör. `slotiva_order_42`).
 */
final class LockBusyException extends \RuntimeException {

	/**
	 * Alınamayan kilit adı.
	 *
	 * @var string
	 */
	public readonly string $lock;

	/**
	 * Kurar.
	 *
	 * @param string $lock Kilit adı.
	 */
	public function __construct( string $lock ) {
		parent::__construct( 'slotiva_busy' );
		$this->lock = $lock;
	}
}
