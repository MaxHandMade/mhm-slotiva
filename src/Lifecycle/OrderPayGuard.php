<?php
/**
 * Klasik "siparişi öde" formu (spec §6.4, §15.4).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Lifecycle;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- imzalar sözleşmeyle camelCase.

/**
 * `woocommerce_before_pay_action` (WC 11.2.1 `class-wc-form-handler.php:567`, ödeme yöntemi işlenmeden önce): randevu
 * siparişin kalemine ve kayıtlı talebine (`_slotiva_requested_slots`) göre yeniden alınır (Task 8 `CheckoutFlow::claim`,
 * idempotent: canlı tutma aynen kullanılır, süresi dolmuş ise yeniden alınır). Alınamazsa `wc_add_notice(…,'error')`:
 * WC hata bildirimi varken `process_payment`'ı çağırmaz (`:605`); yönlendirme yapılmaz.
 *
 * PayTR makbuz sayfası yeniden yüklenince iframe'i bu formdan geçmeden üretir; o yoldan gelen ödeme geç ödeme
 * satırına düşer (yeniden al ya da `conflict`), çift rezervasyon oluşmaz (spec §6.4, bilerek kabul).
 */
final class OrderPayGuard {

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_pay_action', array( self::class, 'beforePay' ) );
	}

	/**
	 * Ödeme formu gönderildi.
	 *
	 * @param \WC_Order|mixed $order Sipariş.
	 */
	public static function beforePay( $order ): void {
		if ( ! $order instanceof \WC_Order || ! PaymentListener::isSlotivaOrder( $order ) ) {
			return;
		}
		try {
			self::expirePastHolds( $order->get_id() );
			self::usePostedMethod( $order );
			CheckoutFlow::claim( $order ); // Varsayılan order-pay: supersede edilmiş sipariş yeniden alınmaz (§15.3).
		} catch ( RouteException $e ) {
			if ( 'slotiva_slot_taken' === $e->getErrorCode() ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: checkout page URL. */
						__( 'The time you picked is no longer available. Please <a href="%s">return to the checkout page</a> and choose another time.', 'mhm-slotiva' ),
						esc_url( wc_get_checkout_url() )
					),
					'error'
				);
				return;
			}
			if ( 'slotiva_superseded' === $e->getErrorCode() ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: checkout page URL. */
						__( 'This order has been replaced by a newer order and can no longer be paid. Please <a href="%s">go to the checkout page</a> to place a new order.', 'mhm-slotiva' ),
						esc_url( wc_get_checkout_url() )
					),
					'error'
				);
				return;
			}
			wc_add_notice( $e->getMessage(), 'error' ); // İleti CheckoutFlow'da esc_html__ ile üretildi.
		} catch ( \Throwable $e ) {
			// Final review F4: beklenmeyen hata `woocommerce_before_pay_action` içinde yakalanmazsa müşteri WP kritik hata
			// sayfasını görürdü. Hata bildirimi ödemeyi durdurur (`class-wc-form-handler.php:605`); ayrıntı yalnız günlükte.
			CheckoutFlow::logError( $order->get_id(), $e );
			wc_add_notice( esc_html( CheckoutFlow::unavailableMessage() ), 'error' );
		}
	}

	/**
	 * Süresi geçmiş ama henüz düşmemiş tutma "boş" sayılır: önce düşürülür ki talep yeniden doğrulanıp alınsın (canlı
	 * tutma gibi yenilenmesin). Satır kilidi altında beklenen sequence/bitişle (HoldScheduler::expire).
	 *
	 * @param int $orderId Sipariş.
	 */
	private static function expirePastHolds( int $orderId ): void {
		foreach ( ReservationRepository::forOrder( $orderId, true ) as $r ) {
			if ( 'held' === $r->status && $r->holdExpiresAt && $r->holdExpiresAt <= Clock::now() ) {
				HoldScheduler::expire( $r->id, $r->sequence, $r->holdExpiresAt->format( 'Y-m-d H:i:s' ) );
			}
		}
	}

	/**
	 * Formda seçilen yöntem (WC bunu birkaç satır sonra zaten yazar) hedef durumu belirler: kart → `held`, kapıda →
	 * `confirmed/on_site`. Yalnız kullanılabilir bir ağ geçidi kabul edilir. Nonce, fail-closed: WC `pay_action` bu kancadan
	 * önce `woocommerce-pay` nonce'unu doğrular; aynı nonce (WC ile aynı alanlardan) burada da doğrulanır, geçersizse
	 * gönderilen yöntem yok sayılır ve siparişin kayıtlı yöntemi kalır.
	 *
	 * @param \WC_Order $order Sipariş.
	 */
	private static function usePostedMethod( \WC_Order $order ): void {
		$nonce = '';
		if ( isset( $_REQUEST['woocommerce-pay-nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['woocommerce-pay-nonce'] ) );
		} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		}
		if ( ! wp_verify_nonce( $nonce, 'woocommerce-pay' ) ) {
			return;
		}
		$posted = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( '' === $posted || $posted === $order->get_payment_method() || ! WC()->payment_gateways() ) {
			return;
		}
		$available = WC()->payment_gateways()->get_available_payment_gateways();
		if ( isset( $available[ $posted ] ) ) {
			$order->set_payment_method( $posted );
		}
	}
}
