<?php
/**
 * Yönetici: çakışma — elle yeni saat ya da iade.
 *
 * Bu şablon temada `yourtheme/woocommerce/emails/slotiva-admin-conflict.php` olarak ezilebilir.
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

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC kancası.
?>
<p><?php echo esc_html( \MHM\Slotiva\Notify\Email\AdminConflict::reasonText( (string) $reason ) . ' ' . __( 'Refund the order or set a new time manually.', 'mhm-slotiva' ) ); ?></p>
<?php
wc_get_template(
	'emails/slotiva-booking-details.php',
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
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC kancası.
