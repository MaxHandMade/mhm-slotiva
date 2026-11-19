<?php
/**
 * Randevu kartı (kare 4): teşekkür sayfası ve Hesabım → sipariş ayrıntısı.
 *
 * Bu şablon temada `yourtheme/woocommerce/front/appointment-card.php` olarak ezilebilir. Stil şablonda DEĞİL:
 * `slotiva-appointment` tutamacına `wp_add_inline_style` ile eklenir (Front\OrderDisplay::CARD_CSS). Renkler `--slotiva-*`
 * özel özelliklerinden okunur (tema `:root`'tan verir); bileşen bunları kendisi TANIMLAMAZ:
 * --slotiva-accent, --slotiva-on-accent, --slotiva-surface, --slotiva-border, --slotiva-muted, --slotiva-radius, --slotiva-gap
 *
 * @package MHM\Slotiva
 *
 * @var list<array<string,mixed>> $bookings Önceden biçimlenmiş satırlar (Front\OrderDisplay::cardData).
 * @var list<string>              $policies Ürün politika metinleri (yinelenmeyen).
 * @var WC_Order                  $order    Sipariş.
 * @var bool                      $first    Bu istekteki ilk kart mı (eski tema kopyalarıyla uyumluluk için; stil artık burada basılmaz).
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="slotiva-appointment" aria-labelledby="slotiva-appointment-title-<?php echo esc_attr( (string) $order->get_id() ); ?>">
	<h2 class="slotiva-appointment__title" id="slotiva-appointment-title-<?php echo esc_attr( (string) $order->get_id() ); ?>">
		<?php echo esc_html( _n( 'Your appointment', 'Your appointments', count( $bookings ), 'mhm-slotiva' ) ); ?>
	</h2>
	<?php foreach ( $bookings as $slotiva_b ) : ?>
		<?php $slotiva_muted = in_array( $slotiva_b['status'], array( 'cancelled', 'expired', 'conflict' ), true ); ?>
		<div class="slotiva-appointment__card<?php echo $slotiva_muted ? ' slotiva-appointment__card--muted' : ''; ?>">
			<div class="slotiva-appointment__date" aria-hidden="true">
				<span class="slotiva-appointment__date-month"><?php echo esc_html( $slotiva_b['month'] ); ?></span>
				<span class="slotiva-appointment__date-day"><?php echo esc_html( $slotiva_b['day'] ); ?></span>
				<span class="slotiva-appointment__date-weekday"><?php echo esc_html( $slotiva_b['weekday'] ); ?></span>
			</div>
			<div class="slotiva-appointment__body">
				<p class="slotiva-appointment__when"><?php echo esc_html( $slotiva_b['date'] . ', ' . $slotiva_b['time'] ); ?></p>
				<p class="slotiva-appointment__meta"><?php echo esc_html( $slotiva_b['timezone'] . ' · ' . $slotiva_b['service'] ); ?></p>
				<?php if ( '' !== $slotiva_b['location'] ) : ?>
					<p class="slotiva-appointment__meta">
						<span class="screen-reader-text"><?php esc_html_e( 'Location', 'mhm-slotiva' ); ?>:</span>
						<?php echo esc_html( $slotiva_b['location'] ); ?>
					</p>
				<?php endif; ?>
				<?php if ( '' !== $slotiva_b['note'] ) : ?>
					<p class="<?php echo $slotiva_muted ? 'slotiva-appointment__status' : 'slotiva-appointment__note'; ?>"><?php echo esc_html( $slotiva_b['note'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $slotiva_b['held_until'] ) : ?>
					<p class="slotiva-appointment__meta">
						<?php
						/* translators: %s: date and time until which the slot is held. */
						echo esc_html( sprintf( __( 'Held until %s', 'mhm-slotiva' ), $slotiva_b['held_until'] ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( '' !== $slotiva_b['ics_url'] ) : ?>
					<p class="slotiva-appointment__note"><?php esc_html_e( 'Add it to your calendar:', 'mhm-slotiva' ); ?></p>
					<div class="slotiva-appointment__actions">
						<a class="slotiva-appointment__btn" href="<?php echo esc_url( $slotiva_b['ics_url'] ); ?>"><?php esc_html_e( 'Calendar file (.ics)', 'mhm-slotiva' ); ?></a>
						<a class="slotiva-appointment__btn" href="<?php echo esc_url( $slotiva_b['gcal_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'mhm-slotiva' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'mhm-slotiva' ); ?></span></a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
	<?php foreach ( $policies as $slotiva_policy ) : ?>
		<p class="slotiva-appointment__policy"><?php echo wp_kses( nl2br( esc_html( $slotiva_policy ) ), array( 'br' => array() ) ); ?></p>
	<?php endforeach; ?>
</section>
