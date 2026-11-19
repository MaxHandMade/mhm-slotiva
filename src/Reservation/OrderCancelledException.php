<?php
/**
 * Order-pay: siparişin son alma kuşağında iptal edilmiş bir satır var (yönetici iptali, sipariş iptali, iade, sipariş
 * silindi): iptal edilen saat order-pay ile geri alınmaz. Hiçbir şey yazılmadı. Çağıran 409 `slotiva_cancelled`; sipariş
 * yine de başka yoldan ödenirse PaymentListener onu yönetici çakışması sayar.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- alan ve parametre adları sözleşmeyle camelCase.

/**
 * İptal edilmiş kuşak. `orderId` = reddedilen sipariş.
 */
final class OrderCancelledException extends \RuntimeException {

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
		parent::__construct( 'slotiva_cancelled' );
		$this->orderId = $orderId;
	}
}
