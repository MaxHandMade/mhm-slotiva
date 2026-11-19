<?php
/**
 * Siparişin son alma kuşağı aynı WC oturumunun daha yeni bir siparişi tarafından `superseded` yapıldı (spec §15.3):
 * bu siparişe yeni tutma alınmaz. Hiçbir şey yazılmadı. Çağıran 409 `slotiva_superseded`; sipariş yine de ödenirse
 * PaymentListener onu `conflict` sayar (yöneticiye çakışma e-postası).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- alan ve parametre adları sözleşmeyle camelCase.

/**
 * Supersede edilmiş sipariş. `orderId` = reddedilen sipariş.
 */
final class OrderSupersededException extends \RuntimeException {

	/**
	 * Reddedilen sipariş.
	 *
	 * @var int
	 */
	public readonly int $orderId;

	/**
	 * Kurar.
	 *
	 * @param int $orderId Sipariş.
	 */
	public function __construct( int $orderId ) {
		parent::__construct( 'slotiva_superseded' );
		$this->orderId = $orderId;
	}
}
