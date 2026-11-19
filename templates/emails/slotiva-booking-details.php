<?php
/**
 * Slotiva e-postalarının ortak randevu ayrıntıları (kare 4: tarih, saat aralığı, saat dilimi, yer, sipariş bağlantısı).
 *
 * Bu şablon temada `yourtheme/woocommerce/emails/slotiva-booking-details.php` olarak ezilebilir.
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

$slotiva_label = 'color:#5c554b;text-align:left;vertical-align:top;padding:4px 12px 4px 0;width:35%;font-weight:normal;';
$slotiva_value = 'text-align:left;vertical-align:top;padding:4px 0;';
?>
<?php foreach ( $bookings as $slotiva_booking ) : ?>
<div style="margin:0 0 24px;">
	<p style="margin:0 0 10px;font-size:20px;font-weight:700;line-height:1.3;"><?php echo esc_html( $slotiva_booking['date'] . ' · ' . $slotiva_booking['time'] ); ?></p>
	<table cellspacing="0" cellpadding="0" border="0" width="100%" role="presentation" style="border-collapse:collapse;">
		<tr>
			<th scope="row" style="<?php echo esc_attr( $slotiva_label ); ?>"><?php esc_html_e( 'Service', 'mhm-slotiva' ); ?></th>
			<td style="<?php echo esc_attr( $slotiva_value ); ?>"><?php echo esc_html( $slotiva_booking['service'] ); ?></td>
		</tr>
		<tr>
			<th scope="row" style="<?php echo esc_attr( $slotiva_label ); ?>"><?php esc_html_e( 'Time zone', 'mhm-slotiva' ); ?></th>
			<td style="<?php echo esc_attr( $slotiva_value ); ?>"><?php echo esc_html( $slotiva_booking['timezone'] ); ?></td>
		</tr>
		<?php if ( '' !== $slotiva_booking['location'] ) : ?>
		<tr>
			<th scope="row" style="<?php echo esc_attr( $slotiva_label ); ?>"><?php esc_html_e( 'Location', 'mhm-slotiva' ); ?></th>
			<td style="<?php echo esc_attr( $slotiva_value ); ?>"><?php echo esc_html( $slotiva_booking['location'] ); ?></td>
		</tr>
		<?php endif; ?>
		<?php if ( $sent_to_admin ) : ?>
		<tr>
			<th scope="row" style="<?php echo esc_attr( $slotiva_label ); ?>"><?php esc_html_e( 'Customer', 'mhm-slotiva' ); ?></th>
			<td style="<?php echo esc_attr( $slotiva_value ); ?>"><?php echo esc_html( $slotiva_booking['customer_name'] ); ?></td>
		</tr>
		<tr>
			<th scope="row" style="<?php echo esc_attr( $slotiva_label ); ?>"><?php esc_html_e( 'Email / Phone', 'mhm-slotiva' ); ?></th>
			<td style="<?php echo esc_attr( $slotiva_value ); ?>"><?php echo esc_html( implode( ' · ', array_filter( array( $slotiva_booking['customer_email'], $slotiva_booking['customer_phone'] ) ) ) ); ?></td>
		</tr>
		<tr>
			<th scope="row" style="<?php echo esc_attr( $slotiva_label ); ?>"><?php esc_html_e( 'Payment', 'mhm-slotiva' ); ?></th>
			<td style="<?php echo esc_attr( $slotiva_value ); ?>"><?php echo esc_html( $slotiva_booking['payment'] ); ?></td>
		</tr>
		<?php endif; ?>
	</table>
</div>
<?php endforeach; ?>
<?php if ( '' !== $order_url ) : ?>
<p style="margin:0 0 16px;">
	<a href="<?php echo esc_url( $order_url ); ?>">
	<?php
	echo esc_html(
		$sent_to_admin
			/* translators: %s: order number. */
			? sprintf( __( 'Open order #%s in the dashboard', 'mhm-slotiva' ), $order_number )
			/* translators: %s: order number. */
			: sprintf( __( 'View order #%s', 'mhm-slotiva' ), $order_number )
	);
	?>
	</a>
</p>
<?php endif; ?>
<?php if ( $has_ics ) : ?>
<p style="margin:0 0 16px;padding:12px 14px;border-radius:6px;background:#ebe1cd;color:#12100d;">
	<?php
	echo esc_html(
		1 < count( $bookings )
			? __( 'The calendar files (.ics) are attached, one per booking.', 'mhm-slotiva' )
			: __( 'The calendar file (.ics) is attached.', 'mhm-slotiva' )
	);
	?>
</p>
<?php endif; ?>
