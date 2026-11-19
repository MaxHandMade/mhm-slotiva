<?php
/**
 * Randevu CSV dışa aktarımı (spec §8): Türkçe Excel'de çift tıkla açılır.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Admin;

use MHM\Slotiva\Front\OrderDisplay;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reservation alanları ve yöntem adları sözleşmeyle camelCase.

/**
 * UTF-8 BOM + `;` ayraç + CRLF satır sonu; sabit sütunlar. Formül enjeksiyonu: `= + - @`, sekme ya da CR ile başlayan hücrenin
 * başına `'` eklenir. Ayraç, tırnak ya da satır sonu içeren hücre RFC 4180 gibi tırnaklanır.
 */
final class Csv {

	public const BOM = "\xEF\xBB\xBF";

	private const SEPARATOR = ';';

	/** Hücre başında her zaman önek gerektiren denetim karakterleri (spec: sekme, CR; ayrıca LF). */
	private const CONTROL_LEADS = array( "\t", "\r", "\n" );

	/** Baştaki boşluk/denetim karakterleri atlandıktan sonra formül başlatan karakterler (tam genişlik biçimleri dahil). */
	private const FORMULA_LEADS = array( '=', '+', '-', '@', "\u{FF1D}", "\u{FF0B}", "\u{FF0D}", "\u{FF20}" );

	/** `row()` içinde telefon sütununun sırası. */
	private const PHONE_COL = 4;

	/** Excel'in sayı sanıp bozacağı (baştaki 0 düşer, 15 haneden sonrası sıfırlanır) yalnız rakamlı telefon. */
	private const DIGITS_ONLY = '/^[0-9]+$/';

	/**
	 * CSV metni (test edilebilir; uç bunu basar).
	 *
	 * @param array<string,mixed> $filters from, to (Y-m-d yerel, uçlar dahil; boş = sınırsız).
	 */
	public static function build( array $filters ): string {
		$from = isset( $filters['from'] ) && '' !== (string) $filters['from'] ? (string) $filters['from'] : null;
		$to   = isset( $filters['to'] ) && '' !== (string) $filters['to'] ? (string) $filters['to'] : null;

		$lines = array( self::line( self::headers() ) );
		foreach ( ReservationRepository::exportRows( $from, $to ) as $r ) {
			$cells                    = array_map( array( self::class, 'cell' ), self::row( $r ) );
			$cells[ self::PHONE_COL ] = self::phoneCell( $r->customerPhone ) ?? $cells[ self::PHONE_COL ];
			$lines[]                  = implode( self::SEPARATOR, $cells );
		}
		return self::BOM . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Sütun başlıkları (İngilizce kaynak, çeviri .po ile).
	 *
	 * @return list<string>
	 */
	public static function headers(): array {
		return array(
			__( 'Date', 'mhm-slotiva' ),
			__( 'Time', 'mhm-slotiva' ),
			__( 'Service', 'mhm-slotiva' ),
			__( 'Name', 'mhm-slotiva' ),
			__( 'Phone', 'mhm-slotiva' ),
			__( 'Email', 'mhm-slotiva' ),
			__( 'Appointment status', 'mhm-slotiva' ),
			__( 'Payment status', 'mhm-slotiva' ),
			__( 'Order no', 'mhm-slotiva' ),
		);
	}

	/**
	 * Ödeme durumu etiketi.
	 *
	 * @param string $state Ödeme durumu.
	 */
	public static function paymentLabel( string $state ): string {
		$labels = array(
			'pending'           => __( 'Pending', 'mhm-slotiva' ),
			'paid'              => __( 'Paid', 'mhm-slotiva' ),
			'on_site'           => __( 'Pay on site', 'mhm-slotiva' ),
			'awaiting_transfer' => __( 'Awaiting transfer', 'mhm-slotiva' ),
			'refunded'          => __( 'Refunded', 'mhm-slotiva' ),
			'manual'            => __( 'Manual', 'mhm-slotiva' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/**
	 * Tek hücreyi güvenli hâle getirir: geçersiz UTF-8 ayıklanır, formül öneki, gerekirse tırnak. Önek: hücre sekme/CR/LF
	 * ile başlıyorsa ya da baştaki boşluk ve denetim karakterleri (NBSP dahil) atlandığında ilk karakter `= + - @` veya
	 * tam genişlik `＝ ＋ － ＠` ise. Güvence yukarı akıştaki temizliğe dayanmaz.
	 *
	 * @param string $value Ham değer.
	 */
	public static function cell( string $value ): string {
		$value = wp_check_invalid_utf8( $value, true );
		if ( '' !== $value ) {
			$probe = (string) preg_replace( '/^[\s\x{00A0}\x{0000}-\x{001F}\x{007F}]+/u', '', $value );
			if ( in_array( $value[0], self::CONTROL_LEADS, true ) || ( '' !== $probe && in_array( mb_substr( $probe, 0, 1 ), self::FORMULA_LEADS, true ) ) ) {
				$value = "'" . $value;
			}
		}
		if ( strpbrk( $value, self::SEPARATOR . "\"\r\n" ) !== false ) {
			$value = '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	/**
	 * Telefon hücresi (final review F5, §11.16 Türkçe Excel): yalnız rakamdan oluşan ve 0 ile başlayan ya da 15 haneyi
	 * aşan değer `="05551234567"` metin formülü olarak (CSV tırnaklı) yazılır: Excel baştaki 0'ı korur, kesme işareti
	 * görünmez. Değer yalnız rakam olduğundan enjeksiyon taşımaz. Diğer değerler için null (olağan `cell()` yolu).
	 *
	 * @param string $phone Ham telefon.
	 */
	public static function phoneCell( string $phone ): ?string {
		if ( 1 !== preg_match( self::DIGITS_ONLY, $phone ) || ( '0' !== $phone[0] && strlen( $phone ) <= 15 ) ) {
			return null;
		}
		return '"=""' . $phone . '"""';
	}

	/**
	 * Bir satırın hücreleri.
	 *
	 * @param Reservation $r Rezervasyon.
	 * @return list<string>
	 */
	private static function row( Reservation $r ): array {
		$local = $r->startsAt->setTimezone( wp_timezone() );
		return array(
			$local->format( 'Y-m-d' ),
			$local->format( 'H:i' ),
			RestController::productName( $r ),
			$r->customerName,
			$r->customerPhone,
			$r->customerEmail,
			OrderDisplay::statusLabel( $r->status ),
			self::paymentLabel( $r->paymentState ),
			self::orderNumber( $r ),
		);
	}

	/**
	 * Sipariş numarası (WC numara süzgeçleri dahil); sipariş silinmişse kimlik, siparişsizse boş.
	 *
	 * @param Reservation $r Rezervasyon.
	 */
	private static function orderNumber( Reservation $r ): string {
		if ( null === $r->orderId ) {
			return '';
		}
		$order = wc_get_order( $r->orderId );
		return $order instanceof \WC_Order ? (string) $order->get_order_number() : (string) $r->orderId;
	}

	/**
	 * Hücreleri satıra çevirir.
	 *
	 * @param list<string> $cells Hücreler.
	 */
	private static function line( array $cells ): string { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		return implode( self::SEPARATOR, array_map( array( self::class, 'cell' ), $cells ) );
	}
}
