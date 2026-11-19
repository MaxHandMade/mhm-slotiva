<?php
/**
 * Müşteri: randevu iptal edildi (iptal ya da havale süresi doldu; METHOD:CANCEL `.ics`).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Şablon: `templates/emails/slotiva-customer-cancelled.php` (+ `plain/`); temada `woocommerce/emails/slotiva-customer-cancelled.php` ile ezilir.
 */
final class CustomerCancelled extends BookingEmail {

	/**
	 * Kimlik, başlık ve şablonlar.
	 */
	public function __construct() {
		$this->title       = __( 'Booking cancelled', 'mhm-slotiva' );
		$this->description = __( 'Sent to the customer when a booking is cancelled or a bank transfer deadline passes. A calendar cancellation (.ics) is attached.', 'mhm-slotiva' );
		$this->ics_method  = 'CANCEL';
		$this->setup( 'slotiva_customer_cancelled', 'slotiva-customer-cancelled', true );
	}

	/**
	 * Varsayılan konu.
	 */
	public function get_default_subject() {
		return __( 'Your booking has been cancelled', 'mhm-slotiva' );
	}

	/**
	 * Varsayılan başlık.
	 */
	public function get_default_heading() {
		return __( 'Booking cancelled', 'mhm-slotiva' );
	}
}
