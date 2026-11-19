<?php
/**
 * Yönetici: yeni randevu (kare 4). (düz metin)
 *
 * Bu şablon temada `yourtheme/woocommerce/emails/plain/slotiva-admin-new.php` olarak ezilebilir.
 *
 * @package MHM\Slotiva
 *
 * @var string                    $email_heading      Başlık.
 * @var string                    $additional_content Ek içerik (ayarlardan).
 * @var list<array<string,mixed>> $bookings           Biçimlenmiş satırlar (Notify\Format::row).
 * @var \WC_Order|null            $order              Sipariş (elle eklenende null).
 * @var string                    $order_number       Sipariş numarası.
 * @var string                    $order_url          Sipariş bağlantısı.
 * @var string                    $reason             Bağlam (ör. bank_expired, late_paid_taken, superseded_paid).
 * @var bool                      $has_ics            Takvim dosyası ekli mi.
 * @var bool                      $sent_to_admin      Yöneticiye mi.
 * @var bool                      $plain_text         Düz metin mi.
 * @var \WC_Email                 $email              E-posta.
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
echo esc_html( wp_strip_all_tags( __( 'A new booking has been confirmed. This time slot is no longer offered to other customers.', 'mhm-slotiva' ) ) ) . "\n\n";

wc_get_template(
	'emails/plain/slotiva-booking-details.php',
	array(
		'bookings'      => $bookings,
		'sent_to_admin' => $sent_to_admin,
		'order_number'  => $order_number,
		'order_url'     => $order_url,
		'has_ics'       => $has_ics,
	),
	'',
	$email->template_base
);

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC süzgeci.
