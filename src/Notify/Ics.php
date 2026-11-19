<?php
/**
 * Takvim dosyası (`.ics`, RFC 5545 / iTIP RFC 5546) — spec §7, §15.11.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify;

use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reservation alanları ve yöntemler sözleşmeyle camelCase.

/**
 * Her rezervasyon ayrı bir VEVENT/dosya. Zamanlar UTC (`Z`), VTIMEZONE gerekmez. UID = `{token}@{host}` (değişmez);
 * SEQUENCE = satırın KALICI `sequence`'i. Bir UID için giden her `.ics` öncekinden kesin büyük SEQUENCE taşır: saat
 * değişimi, iptal e-postası (`Emails`, `recordOnce(..., bumpSequence)`) ve yeniden etkinleşme (`reclaim`) sayacı artırır.
 */
final class Ics {

	public const METHODS = array( 'REQUEST', 'CANCEL' );

	/** RFC 5545 §3.1: satır en çok 75 sekizli (CRLF hariç). */
	private const FOLD = 75;

	/**
	 * Takvim dosyasının metni.
	 *
	 * @param Reservation $r      Rezervasyon.
	 * @param string      $method REQUEST | CANCEL.
	 * @throws \InvalidArgumentException Bilinmeyen yöntem.
	 */
	public static function build( Reservation $r, string $method ): string {
		if ( ! in_array( $method, self::METHODS, true ) ) {
			throw new \InvalidArgumentException( 'slotiva_invalid_ics_method' );
		}
		$cancel   = 'CANCEL' === $method;
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host     = '' !== $host ? $host : 'localhost';
		$org_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$org_mail = self::organizerEmail();

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//MHM//Slotiva//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:' . $method,
			'BEGIN:VEVENT',
			'UID:' . $r->token . '@' . $host,
			'DTSTAMP:' . self::utc( Clock::now() ),
			'DTSTART:' . self::utc( $r->startsAt ),
			'DTEND:' . self::utc( $r->endsAt ),
			'SEQUENCE:' . $r->sequence,
			'SUMMARY:' . self::text( self::summary( $r ) ),
		);
		if ( '' !== $r->locationText ) {
			$lines[] = 'LOCATION:' . self::text( $r->locationText );
		}
		$lines[] = 'ORGANIZER;CN=' . self::param( '' !== $org_name ? $org_name : $host ) . ':mailto:' . $org_mail;
		if ( '' !== $r->customerEmail && is_email( $r->customerEmail ) ) {
			$attendee = 'ATTENDEE;ROLE=REQ-PARTICIPANT;RSVP=FALSE';
			if ( '' !== $r->customerName ) {
				$attendee .= ';CN=' . self::param( $r->customerName );
			}
			$lines[] = $attendee . ':mailto:' . $r->customerEmail;
		}
		$lines[] = 'STATUS:' . ( $cancel ? 'CANCELLED' : 'CONFIRMED' );
		$lines[] = 'TRANSP:OPAQUE';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		$out = '';
		foreach ( $lines as $line ) {
			$out .= self::fold( $line ) . "\r\n";
		}
		return $out;
	}

	/**
	 * Dosya adı.
	 *
	 * @param Reservation $r Rezervasyon.
	 */
	public static function filename( Reservation $r ): string {
		return 'booking-' . $r->id . '.ics';
	}

	/**
	 * Hizmet adı: anlık kopya, yoksa ürünün güncel adı.
	 *
	 * @param Reservation $r Rezervasyon.
	 */
	public static function summary( Reservation $r ): string {
		if ( '' !== $r->productName ) {
			return $r->productName;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $r->productId ) : null;
		return $product instanceof \WC_Product ? $product->get_name() : __( 'Booking', 'mhm-slotiva' );
	}

	/**
	 * Düzenleyici adresi: WC "gönderen" adresi, yoksa site yöneticisi.
	 */
	private static function organizerEmail(): string {
		$from = (string) get_option( 'woocommerce_email_from_address', '' );
		return is_email( $from ) ? $from : (string) get_option( 'admin_email' );
	}

	/**
	 * UTC biçimi `YYYYMMDDTHHMMSSZ`.
	 *
	 * @param \DateTimeImmutable $at An.
	 */
	private static function utc( \DateTimeImmutable $at ): string {
		return $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	/**
	 * TEXT değer kaçışı (RFC 5545 §3.3.11).
	 *
	 * @param string $s Metin.
	 */
	private static function text( string $s ): string {
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), $s );
	}

	/**
	 * Parametre değeri (CN): DQUOTE ve denetim karakterleri atılır, tırnak içine alınır.
	 *
	 * @param string $s Değer.
	 */
	private static function param( string $s ): string {
		return '"' . (string) preg_replace( '/[\x00-\x1F\x7F"]/', '', $s ) . '"';
	}

	/**
	 * 75 sekizlide katlar; çok baytlı UTF-8 karakteri bölmez.
	 *
	 * @param string $line Satır.
	 */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= self::FOLD ) {
			return $line;
		}
		$out   = '';
		$cur   = '';
		$limit = self::FOLD;
		foreach ( (array) preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			$ch = (string) $ch;
			if ( strlen( $cur ) + strlen( $ch ) > $limit ) {
				$out  .= $cur . "\r\n ";
				$cur   = '';
				$limit = self::FOLD - 1; // Devam satırı baştaki boşlukla başlar.
			}
			$cur .= $ch;
		}
		return $out . $cur;
	}
}
