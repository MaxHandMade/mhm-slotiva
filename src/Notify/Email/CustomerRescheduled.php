<?php
/**
 * Müşteri: randevu saati değişti (yeni SEQUENCE'li `.ics`).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Şablon: `templates/emails/slotiva-customer-rescheduled.php` (+ `plain/`); temada `woocommerce/emails/slotiva-customer-rescheduled.php` ile ezilir.
 */
final class CustomerRescheduled extends BookingEmail {

	/**
	 * Kimlik, başlık ve şablonlar.
	 */
	public function __construct() {
		$this->title       = __( 'Booking time changed', 'mhm-slotiva' );
		$this->description = __( 'Sent to the customer when the time of a booking is changed with notification. The updated calendar file (.ics) is attached.', 'mhm-slotiva' );
		$this->ics_method  = 'REQUEST';
		$this->setup( 'slotiva_customer_rescheduled', 'slotiva-customer-rescheduled', true );
	}

	/**
	 * Varsayılan konu.
	 */
	public function get_default_subject() {
		return __( 'Your booking time has changed: {booking_date}', 'mhm-slotiva' );
	}

	/**
	 * Varsayılan başlık.
	 */
	public function get_default_heading() {
		return __( 'New time for your booking', 'mhm-slotiva' );
	}
}
