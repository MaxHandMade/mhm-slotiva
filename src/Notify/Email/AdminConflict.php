<?php
/**
 * Yönetici: çakışma (ödenmiş sipariş saatini alamadı; `slotiva_conflict`).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Şablon: `templates/emails/slotiva-admin-conflict.php` (+ `plain/`); temada `woocommerce/emails/slotiva-admin-conflict.php` ile ezilir.
 */
final class AdminConflict extends BookingEmail {

	/**
	 * Kimlik, başlık ve şablonlar.
	 */
	public function __construct() {
		$this->title       = __( 'Booking conflict', 'mhm-slotiva' );
		$this->description = __( 'Sent to the shop when a paid order could not keep its time slot. Refund or set a new time manually.', 'mhm-slotiva' );
		$this->ics_method  = '';
		$this->setup( 'slotiva_admin_conflict', 'slotiva-admin-conflict', false );
	}

	/**
	 * Çakışma nedeninin yöneticiye açıklaması (şablonlar kullanır).
	 *
	 * @param string $reason late_paid_taken | superseded_paid | admin_cancelled_paid | superseded_admin_cancelled_paid.
	 */
	public static function reasonText( string $reason ): string {
		if ( 'superseded_paid' === $reason ) {
			return __( 'Payment arrived for a booking that had been replaced by a newer order, so the time slot was not booked again.', 'mhm-slotiva' );
		}
		if ( 'superseded_admin_cancelled_paid' === $reason ) {
			return __( 'Payment arrived for bookings that had been replaced by a newer order or cancelled by an administrator, so the time slots were not booked again.', 'mhm-slotiva' );
		}
		if ( 'admin_cancelled_paid' === $reason ) {
			return __( 'Payment arrived for a booking that was cancelled by an administrator, so the time slot was not booked again.', 'mhm-slotiva' );
		}
		return __( 'Payment arrived after the booking hold expired and the time slot is now taken by another booking.', 'mhm-slotiva' );
	}

	/**
	 * Varsayılan konu.
	 */
	public function get_default_subject() {
		return __( '[{site_title}] Booking conflict: set a new time manually (order #{order_number})', 'mhm-slotiva' );
	}

	/**
	 * Varsayılan başlık.
	 */
	public function get_default_heading() {
		return __( 'Booking conflict', 'mhm-slotiva' );
	}
}
