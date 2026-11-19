<?php
/**
 * E-posta şablonlarına giden düz veri (kare 4): tarih, saat aralığı, saat dilimi adı, yer, müşteri, ödeme.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify;

use MHM\Slotiva\Reservation\Reservation;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reservation alanları sözleşmeyle camelCase.

/**
 * Şablon ezen temalar hesap yapmasın diye her satır önceden biçimlenmiş dizgilerle verilir. Biçimler sitenin tarih/saat
 * ayarlarından ve saat diliminden gelir.
 */
final class Format {

	/**
	 * Şablon satırı.
	 *
	 * @param Reservation    $r     Rezervasyon.
	 * @param \WC_Order|null $order Sipariş.
	 * @return array{id:int,service:string,date:string,time:string,timezone:string,location:string,customer_name:string,customer_email:string,customer_phone:string,payment:string}
	 */
	public static function row( Reservation $r, ?\WC_Order $order = null ): array {
		$start = $r->startsAt->getTimestamp();
		$end   = $r->endsAt->getTimestamp();
		return array(
			'id'             => $r->id,
			'service'        => Ics::summary( $r ),
			'date'           => self::date( $start ),
			'time'           => self::time( $start ) . '–' . self::time( $end ),
			'timezone'       => self::timezone( $r->startsAt ),
			'location'       => $r->locationText,
			'customer_name'  => '' !== $r->customerName ? $r->customerName : ( $order ? $order->get_formatted_billing_full_name() : '' ),
			'customer_email' => '' !== $r->customerEmail ? $r->customerEmail : ( $order ? (string) $order->get_billing_email() : '' ),
			'customer_phone' => '' !== $r->customerPhone ? $r->customerPhone : ( $order ? (string) $order->get_billing_phone() : '' ),
			'payment'        => self::payment( $r, $order ),
		);
	}

	/**
	 * Konu satırı için kısa an: "17 Kasım 2026 10:00".
	 *
	 * @param Reservation $r Rezervasyon.
	 */
	public static function when( Reservation $r ): string {
		$ts = $r->startsAt->getTimestamp();
		return self::date( $ts ) . ' ' . self::time( $ts );
	}

	/**
	 * Haftanın günüyle tarih (site biçimi).
	 *
	 * @param int $ts Zaman damgası.
	 */
	private static function date( int $ts ): string {
		return (string) wp_date( 'l', $ts ) . ', ' . (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $ts );
	}

	/**
	 * Saat (site biçimi).
	 *
	 * @param int $ts Zaman damgası.
	 */
	private static function time( int $ts ): string {
		return (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $ts );
	}

	/**
	 * Saat dilimi adı + o andaki fark: "Europe/Istanbul (GMT+3)".
	 *
	 * @param \DateTimeImmutable $at An (yaz saati farkı o güne göre).
	 */
	public static function timezone( \DateTimeImmutable $at ): string {
		$tz     = wp_timezone();
		$offset = $tz->getOffset( $at );
		$sign   = $offset < 0 ? '-' : '+';
		$abs    = abs( $offset );
		$gmt    = 'GMT' . $sign . intdiv( $abs, 3600 ) . ( $abs % 3600 ? sprintf( ':%02d', intdiv( $abs % 3600, 60 ) ) : '' );
		$name   = $tz->getName();
		return str_contains( $name, '/' ) ? str_replace( '_', ' ', $name ) . ' (' . $gmt . ')' : $gmt;
	}

	/**
	 * Ödeme durumunun okunur etiketi.
	 *
	 * @param string $state pending|paid|on_site|awaiting_transfer|refunded|manual.
	 */
	public static function paymentLabel( string $state ): string {
		$labels = array(
			'pending'           => __( 'Awaiting payment', 'mhm-slotiva' ),
			'paid'              => __( 'Paid', 'mhm-slotiva' ),
			'on_site'           => __( 'Pay on site', 'mhm-slotiva' ),
			'awaiting_transfer' => __( 'Awaiting bank transfer', 'mhm-slotiva' ),
			'refunded'          => __( 'Refunded', 'mhm-slotiva' ),
			'manual'            => __( 'Added manually', 'mhm-slotiva' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/**
	 * Ödeme durumu etiketi (+ siparişin ödeme yöntemi).
	 *
	 * @param Reservation    $r     Rezervasyon.
	 * @param \WC_Order|null $order Sipariş.
	 */
	private static function payment( Reservation $r, ?\WC_Order $order ): string {
		$label  = self::paymentLabel( $r->paymentState );
		$method = $order ? (string) $order->get_payment_method_title() : '';
		/* translators: 1: payment status, 2: payment method title. */
		return '' !== $method ? sprintf( __( '%1$s (%2$s)', 'mhm-slotiva' ), $label, $method ) : $label;
	}
}
