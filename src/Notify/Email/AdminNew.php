<?php
/**
 * Yönetici: yeni randevu (sipariş başına bir kez, toplu; kare 4).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Şablon: `templates/emails/slotiva-admin-new.php` (+ `plain/`); temada `woocommerce/emails/slotiva-admin-new.php` ile ezilir.
 */
final class AdminNew extends BookingEmail {

	/**
	 * Kimlik, başlık ve şablonlar.
	 */
	public function __construct() {
		$this->title       = __( 'New booking', 'mhm-slotiva' );
		$this->description = __( 'Sent to the shop when a booking is confirmed (once per order). The calendar file (.ics) is attached.', 'mhm-slotiva' );
		$this->ics_method  = 'REQUEST';
		$this->setup( 'slotiva_admin_new', 'slotiva-admin-new', false );
	}

	/**
	 * Varsayılan konu.
	 */
	public function get_default_subject() {
		return __( '[{site_title}] New booking: {booking_date}', 'mhm-slotiva' );
	}

	/**
	 * Varsayılan başlık.
	 */
	public function get_default_heading() {
		return __( 'New booking', 'mhm-slotiva' );
	}
}
