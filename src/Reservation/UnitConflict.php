<?php
/**
 * İç sinyal: işlem içinde birim eklemesi çakıştı (1062) ya da kilitlenme (1213). Depo dışına çıkmaz;
 * `ReservationRepository::transact()` bunu ROLLBACK + yeniden deneme / SlotTakenException'a çevirir.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

defined( 'ABSPATH' ) || exit;

/**
 * Birim çakışması / kilitlenme sinyali.
 *
 * @internal
 */
final class UnitConflict extends \RuntimeException {

	/**
	 * MySQL hata kodu.
	 *
	 * @var int
	 */
	public readonly int $errno;

	/**
	 * Çakışan birim ('Y-m-d H:i:s' UTC; '' = bilinmiyor).
	 *
	 * @var string
	 */
	public readonly string $unit;

	/**
	 * Kurar.
	 *
	 * @param int    $errno MySQL hata kodu.
	 * @param string $unit  Çakışan birim.
	 */
	public function __construct( int $errno, string $unit ) {
		parent::__construct( 'slotiva_unit_conflict' );
		$this->errno = $errno;
		$this->unit  = $unit;
	}
}
