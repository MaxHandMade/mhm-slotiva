<?php
/**
 * Yönetici uyarıları: ödeme ayarı randevuyu kesinleştiremiyorsa (spec §6.4, §15.6, senaryo 17).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Lifecycle;

use MHM\Slotiva\Setup\SetupStatus;

defined( 'ABSPATH' ) || exit;

/**
 * (a) PayTR başarı durumu (`paytr_order_status`, varsayılan `wc-processing`) ödenmiş sayılmayan bir durumdaysa (ör.
 *     `wc-on-hold`), ödeme gelse de randevu `confirmed` olmaz.
 * (b) Ödeme sayfası blok değilse randevulu ürün satın alınamaz (klasik ödeme reddedilir, Task 8).
 */
final class GatewayNotice {

	/** PayTR iFrame ağ geçidi ayarı. */
	private const PAYTR_OPTION = 'woocommerce_paytr_payment_gateway_settings';

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Uyarıları yalnız mağaza yöneticisine gösterir.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce'in kaydettiği yetenek.
			return;
		}
		foreach ( self::messages() as $message ) {
			printf( '<div class="notice notice-warning"><p><strong>MHM Slotiva:</strong> %s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * Geçerli uyarılar.
	 *
	 * @return array<string,string> anahtar → ileti (paytr_status, classic_checkout).
	 */
	public static function messages(): array {
		$out   = array();
		$paytr = get_option( self::PAYTR_OPTION );
		if ( is_array( $paytr ) && 'yes' === ( $paytr['enabled'] ?? '' ) && isset( $paytr['paytr_order_status'] ) ) {
			$status = preg_replace( '/^wc-/', '', (string) $paytr['paytr_order_status'] );
			if ( ! in_array( $status, wc_get_is_paid_statuses(), true ) ) {
				$out['paytr_status'] = sprintf(
					/* translators: %s: order status name selected in the PayTR settings. */
					__( 'PayTR marks successful payments as "%s", which WooCommerce does not treat as paid. Bookings paid with PayTR are never confirmed and their time slots are released when the hold ends, although the customer has paid. Set the PayTR order status to Processing or Completed.', 'mhm-slotiva' ),
					wc_get_order_status_name( (string) $status )
				);
			}
		}
		if ( ! self::checkoutIsBlock() ) {
			$out['classic_checkout'] = __( 'Your checkout page does not use the Checkout block, so bookable services cannot be purchased. Switch the checkout page to the Checkout block.', 'mhm-slotiva' );
		}
		return $out;
	}

	/**
	 * Ödeme sayfası (ya da blok temanın ödeme şablonu) Checkout bloğunu içeriyor mu.
	 */
	private static function checkoutIsBlock(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- sözleşmeyle camelCase.
		return SetupStatus::checkoutUsesBlock();
	}
}
