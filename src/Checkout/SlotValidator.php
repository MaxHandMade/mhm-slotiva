<?php
/**
 * Ödemede gelen dilim seçiminin sunucu doğrulaması (spec §6.3).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use MHM\Slotiva\Availability\SlotCalculator;
use MHM\Slotiva\Availability\Units;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Schedule\Rules;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar sözleşmeyle camelCase.

/**
 * DOLULUĞA BAKMAZ: doluluğu yalnız `claim()` söyler (409). Aksi hâlde açık kalmış eski sayfa 409 yerine 400 alır ve aynı
 * siparişin yeniden gönderimi kendi tutmasını dolu görür. Süre her zaman ürün metasından gelir; istemcinin gönderdiği
 * başka alanlar (ör. `duration`) okunmaz.
 */
final class SlotValidator {

	/** Şemadaki üst sınırla aynı. */
	public const MAX_SLOTS = 10;

	/**
	 * Dilimleri doğrular ve artan, UTC `DateTimeImmutable` listesi döner.
	 *
	 * Sıra: boş → biçim → tekrar → sayı aralığı → adet → örtüşme → takvim (ızgara/düzen/istisna/ön süre/ufuk).
	 *
	 * @param BookableProduct $p        Sepetteki/siparişteki randevulu satırın ürünü.
	 * @param array<mixed>    $isoSlots İstemciden gelen ISO-8601 dizgiler.
	 * @param int             $lineQty  Satır adedi.
	 * @return list<\DateTimeImmutable>
	 * @throws RouteException 400: slotiva_no_slots | slotiva_bad_format | slotiva_duplicate | slotiva_count_range |
	 *                        slotiva_qty_mismatch | slotiva_overlap | slotiva_not_bookable.
	 */
	public static function validate( BookableProduct $p, array $isoSlots, int $lineQty ): array {
		$isoSlots = array_values( $isoSlots );
		if ( ! $isoSlots ) {
			throw self::fail( 'slotiva_no_slots', esc_html__( 'Please choose a time for your appointment.', 'mhm-slotiva' ) );
		}
		if ( count( $isoSlots ) > self::MAX_SLOTS ) {
			throw self::count_range( (int) $p->minSlots, (int) $p->maxSlots );
		}

		$slots = array();
		$seen  = array();
		foreach ( $isoSlots as $iso ) {
			$dt = self::parse( $iso );
			if ( ! $dt ) {
				throw self::fail( 'slotiva_bad_format', esc_html__( 'The chosen time could not be read. Please choose the time again.', 'mhm-slotiva' ) );
			}
			$ts = $dt->getTimestamp();
			if ( isset( $seen[ $ts ] ) ) {
				throw self::fail( 'slotiva_duplicate', esc_html__( 'The same time was chosen more than once.', 'mhm-slotiva' ) );
			}
			$seen[ $ts ] = true;
			$slots[]     = $dt;
		}
		usort( $slots, static fn( \DateTimeImmutable $a, \DateTimeImmutable $b ): int => $a <=> $b );

		$count = count( $slots );
		if ( $count < $p->minSlots || $count > $p->maxSlots ) {
			throw self::count_range( (int) $p->minSlots, (int) $p->maxSlots );
		}
		self::assertQuantity( $p, $count, $lineQty );

		$buffer = Rules::load()->bufferMin;
		$units  = array();
		foreach ( $slots as $slot ) {
			foreach ( Units::span( $slot, $p->durationMin, $buffer ) as $unit ) {
				$key = $unit->format( Units::FORMAT );
				if ( isset( $units[ $key ] ) ) {
					throw self::fail( 'slotiva_overlap', esc_html__( 'The chosen times overlap each other.', 'mhm-slotiva' ) );
				}
				$units[ $key ] = true;
			}
		}

		foreach ( $slots as $slot ) {
			if ( ! SlotCalculator::isBookable( $p, $slot ) ) {
				throw self::fail(
					'slotiva_not_bookable',
					esc_html__( 'The chosen time is no longer offered. Please choose another time.', 'mhm-slotiva' ),
					array( 'slot' => esc_html( self::iso( $slot ) ) )
				);
			}
		}
		return $slots;
	}

	/**
	 * Satır adedi dilim sayısıyla uyuşuyor mu: `per_slot` → adet = dilim sayısı, `fixed` → adet = 1. Yeni almada ve
	 * mevcut tutmanın HER yeniden kullanımında (order-pay dahil: kalem adedi sonradan değişmiş olabilir) denetlenir.
	 *
	 * @param BookableProduct $p       Ürün.
	 * @param int             $count   Dilim sayısı (istek ya da kayıtlı talep).
	 * @param int             $lineQty  Satır (sepet ya da sipariş kalemi) adedi.
	 * @param bool            $orderPay Order-pay yolu (sepet yok): iletide sipariş anılır.
	 * @throws RouteException 400 slotiva_qty_mismatch.
	 */
	public static function assertQuantity( BookableProduct $p, int $count, int $lineQty, bool $orderPay = false ): void {
		$expected = 'fixed' === $p->pricing ? 1 : $count;
		if ( $lineQty !== $expected ) {
			throw self::fail(
				'slotiva_qty_mismatch',
				$orderPay
					? esc_html__( 'The quantity in this order does not match the number of booked times, so the order cannot be paid as it is. Please place a new order on the checkout page.', 'mhm-slotiva' )
					: esc_html__( 'The quantity in your cart does not match the number of chosen times. Please reload the page and try again.', 'mhm-slotiva' ),
				array(
					'expected' => (int) $expected,
					'quantity' => (int) $lineQty,
				)
			);
		}
	}

	/**
	 * Yalnız biçim: ISO-8601 (saat dilimi zorunlu) → UTC. Geçersiz takvim tarihi (2026-02-30) reddedilir.
	 *
	 * @param mixed $iso Ham değer.
	 */
	public static function parse( $iso ): ?\DateTimeImmutable {
		if ( ! is_string( $iso ) || ! preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::\d{2})?(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $iso, $m ) ) {
			return null;
		}
		try {
			$dt = new \DateTimeImmutable( $iso );
		} catch ( \Exception $e ) {
			return null;
		}
		if ( $dt->format( 'Y-m-d\TH:i' ) !== $m[1] . 'T' . $m[2] ) {
			return null;
		}
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Saklama/yanıt biçimi: `2026-11-17T07:00:00Z`.
	 *
	 * @param \DateTimeImmutable $dt An.
	 */
	public static function iso( \DateTimeImmutable $dt ): string {
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Sayı aralığı hatası.
	 *
	 * @param int $min Ürünün en az dilim sayısı.
	 * @param int $max Ürünün en çok dilim sayısı.
	 */
	private static function count_range( int $min, int $max ): RouteException {
		return self::fail(
			'slotiva_count_range',
			$min === $max
				/* translators: %d: number of time slots. */
				? esc_html( sprintf( _n( 'Choose %d time for this service.', 'Choose %d times for this service.', $max, 'mhm-slotiva' ), $max ) )
				/* translators: 1: minimum number of slots, 2: maximum number of slots. */
				: esc_html( sprintf( __( 'Choose between %1$d and %2$d times for this service.', 'mhm-slotiva' ), $min, $max ) ),
			array(
				'min' => $min,
				'max' => $max,
			)
		);
	}

	/**
	 * 400 RouteException.
	 *
	 * @param string              $code    Hata kodu.
	 * @param string              $message Müşteri iletisi (esc_html'li: OrderPayGuard bunu wc_add_notice ile basar).
	 * @param array<string,mixed> $data    Ek veri.
	 */
	private static function fail( string $code, string $message, array $data = array() ): RouteException {
		return new RouteException( $code, $message, 400, $data );
	}
}
