<?php
/**
 * Müşteri: randevu onaylandı (sipariş başına bir kez, toplu).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Şablon: `templates/emails/slotiva-customer-confirmed.php` (+ `plain/`); temada `woocommerce/emails/slotiva-customer-confirmed.php` ile ezilir.
 */
final class CustomerConfirmed extends BookingEmail {

	/**
	 * Kimlik, başlık ve şablonlar.
	 */
	public function __construct() {
		$this->title       = __( 'Booking confirmed', 'mhm-slotiva' );
		$this->description = __( 'Sent to the customer once, when every booking of the order is confirmed. The calendar file (.ics) is attached.', 'mhm-slotiva' );
		$this->ics_method  = 'REQUEST';
		$this->setup( 'slotiva_customer_confirmed', 'slotiva-customer-confirmed', true );
	}

	/**
	 * Varsayılan konu.
	 */
	public function get_default_subject() {
		return __( 'Your booking is confirmed: {booking_date}', 'mhm-slotiva' );
	}

	/**
	 * Varsayılan başlık.
	 */
	public function get_default_heading() {
		return __( 'Your booking is confirmed', 'mhm-slotiva' );
	}
}
