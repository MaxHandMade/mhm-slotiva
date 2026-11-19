<?php
/**
 * Slotiva e-postalarının ortak randevu ayrıntıları (düz metin).
 *
 * Bu şablon temada `yourtheme/woocommerce/emails/plain/slotiva-booking-details.php` olarak ezilebilir.
 *
 * @package MHM\Slotiva
 *
 * @var list<array<string,mixed>> $bookings      Biçimlenmiş satırlar (Notify\Format::row).
 * @var bool                      $sent_to_admin Yöneticiye mi.
 * @var string                    $order_number  Sipariş numarası ('' = elle eklenen).
 * @var string                    $order_url     Sipariş bağlantısı ('' = yok).
 * @var bool                      $has_ics       Takvim dosyası ekli mi.
 */

defined( 'ABSPATH' ) || exit;

foreach ( $bookings as $slotiva_booking ) {
	echo esc_html( wp_strip_all_tags( $slotiva_booking['date'] . ' · ' . $slotiva_booking['time'] ) ) . "\n";
	echo esc_html( wp_strip_all_tags( __( 'Service', 'mhm-slotiva' ) . ': ' . $slotiva_booking['service'] ) ) . "\n";
	echo esc_html( wp_strip_all_tags( __( 'Time zone', 'mhm-slotiva' ) . ': ' . $slotiva_booking['timezone'] ) ) . "\n";
	if ( '' !== $slotiva_booking['location'] ) {
		echo esc_html( wp_strip_all_tags( __( 'Location', 'mhm-slotiva' ) . ': ' . $slotiva_booking['location'] ) ) . "\n";
	}
	if ( $sent_to_admin ) {
		echo esc_html( wp_strip_all_tags( __( 'Customer', 'mhm-slotiva' ) . ': ' . $slotiva_booking['customer_name'] ) ) . "\n";
		echo esc_html( wp_strip_all_tags( __( 'Email / Phone', 'mhm-slotiva' ) . ': ' . implode( ' · ', array_filter( array( $slotiva_booking['customer_email'], $slotiva_booking['customer_phone'] ) ) ) ) ) . "\n";
		echo esc_html( wp_strip_all_tags( __( 'Payment', 'mhm-slotiva' ) . ': ' . $slotiva_booking['payment'] ) ) . "\n";
	}
	echo "\n";
}

if ( '' !== $order_url ) {
	echo esc_html(
		$sent_to_admin
			/* translators: %s: order number. */
			? sprintf( __( 'Open order #%s in the dashboard', 'mhm-slotiva' ), $order_number )
			/* translators: %s: order number. */
			: sprintf( __( 'View order #%s', 'mhm-slotiva' ), $order_number )
	) . ': ' . esc_url_raw( $order_url ) . "\n\n";
}

if ( $has_ics ) {
	echo esc_html( 1 < count( $bookings ) ? __( 'The calendar files (.ics) are attached, one per booking.', 'mhm-slotiva' ) : __( 'The calendar file (.ics) is attached.', 'mhm-slotiva' ) ) . "\n\n";
}
