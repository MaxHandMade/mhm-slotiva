<?php
/**
 * Müşteri: hatırlatma (`starts_at − reminderHours`).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Şablon: `templates/emails/slotiva-customer-reminder.php` (+ `plain/`); temada `woocommerce/emails/slotiva-customer-reminder.php` ile ezilir.
 */
final class CustomerReminder extends BookingEmail {

	/**
	 * Kimlik, başlık ve şablonlar.
	 */
	public function __construct() {
		$this->title       = __( 'Booking reminder', 'mhm-slotiva' );
		$this->description = __( 'Sent to the customer before a confirmed booking (the number of hours is set in the Slotiva rules).', 'mhm-slotiva' );
		$this->ics_method  = '';
		$this->setup( 'slotiva_customer_reminder', 'slotiva-customer-reminder', true );
	}

	/**
	 * Varsayılan konu.
	 */
	public function get_default_subject() {
		return __( 'Reminder: your booking on {booking_date}', 'mhm-slotiva' );
	}

	/**
	 * Varsayılan başlık.
	 */
	public function get_default_heading() {
		return __( 'See you soon', 'mhm-slotiva' );
	}
}
