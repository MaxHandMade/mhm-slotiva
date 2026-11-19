<?php
/**
 * Dilim dolu: alma ya da yeniden zamanlama çakıştı, işlem tamamen geri alındı.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

defined( 'ABSPATH' ) || exit;

/**
 * `taken` = istenen dilimlerden dolu olanların BAŞLANGIÇLARI (birim değil, dilim; UTC).
 */
final class SlotTakenException extends \RuntimeException {

	/**
	 * Dolu dilim başlangıçları.
	 *
	 * @var list<\DateTimeImmutable>
	 */
	public readonly array $taken;

	/**
	 * Kurar.
	 *
	 * @param list<\DateTimeImmutable> $taken Dolu dilim başlangıçları (UTC).
	 */
	public function __construct( array $taken ) { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		parent::__construct( 'slotiva_slot_taken' );
		$this->taken = $taken;
	}
}
