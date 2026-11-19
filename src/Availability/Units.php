<?php
/**
 * 15 dakikalık birimler: doluluk tablosunun ve dilimlerin ortak zaman ızgarası.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Availability;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Schedule\Rules;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- imzalar sözleşmeyle $startUtc/$fromUtc/$toUtc.

/**
 * Birim üretimi (saf) ve dolu birimleri okuma (tek veritabanı okuması).
 */
final class Units {

	public const FORMAT = 'Y-m-d H:i:s';

	/** Tek kaynak (takvim): bugün mağaza genelinde bir kaynak var; birim yazan ve okuyan her yol bunu kullanır (R14). */
	public const RESOURCE_ID = 1;

	/**
	 * Başlangıçtan (süre + ara) kadar süreyi 15 dk'lık, artan birimlere böler.
	 *
	 * @param \DateTimeImmutable $startUtc    İlk birimin başlangıcı.
	 * @param int                $durationMin Süre (dk, 15'in katı).
	 * @param int                $bufferMin   Ara (dk, 15'in katı veya 0).
	 * @return list<\DateTimeImmutable> UTC birim başlangıçları.
	 */
	public static function span( \DateTimeImmutable $startUtc, int $durationMin, int $bufferMin ): array {
		$count = (int) ceil( max( 0, $durationMin + $bufferMin ) / Rules::STEP );
		$start = $startUtc->setTimezone( new \DateTimeZone( 'UTC' ) );
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $start->modify( '+' . ( $i * Rules::STEP ) . ' minutes' );
		}
		return $out;
	}

	/**
	 * [from, to) aralığındaki dolu birimler.
	 *
	 * @param \DateTimeImmutable $fromUtc    Aralık başı (dahil).
	 * @param \DateTimeImmutable $toUtc      Aralık sonu (hariç).
	 * @param int                $resourceId Kaynak.
	 * @param int                $excludeReservation Bu rezervasyonun birimleri dolu sayılmaz (0 = yok; yönetici saat değişimi, §15.10).
	 * @return array<string,true> 'Y-m-d H:i:s' UTC anahtarlı küme.
	 */
	public static function busy( \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, int $resourceId = self::RESOURCE_ID, int $excludeReservation = 0 ): array {
		global $wpdb;
		$utc   = new \DateTimeZone( 'UTC' );
		$table = Schema::table( 'occupancy' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi doluluk tablosu; müsaitlik okuması taze olmalı (önbellek yanlış dolu/boş gösterir); yanıt düzeyindeki önbellek Availability/RestController'da sürümlüdür.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT unit_start FROM %i WHERE resource_id = %d AND unit_start >= %s AND unit_start < %s AND reservation_id <> %d',
				$table,
				$resourceId,
				$fromUtc->setTimezone( $utc )->format( self::FORMAT ),
				$toUtc->setTimezone( $utc )->format( self::FORMAT ),
				max( 0, $excludeReservation )
			)
		);

		$out = array();
		foreach ( (array) $rows as $unit ) {
			$out[ (string) $unit ] = true;
		}
		return $out;
	}

	/**
	 * [from, to) aralığındaki doluluk, (birim, ebeveyn ürün, başlangıç) üzerinden gruplanmış: TEK sorgu (occupancy ⋈
	 * reservations, K9; N+1 yok). Müsaitlik bunu `SeatPolicy::seatsLeft`'e verir. Rezervasyonu olmayan (sahipsiz) satırlar
	 * ürün/başlangıç null grubunda toplanır (yabancı sayılır). `busy()` gibi her satır sayılır (süresi geçmiş tutma dahil;
	 * gösterim kabulden fazla yer vaat etmez).
	 *
	 * @param \DateTimeImmutable $fromUtc            Aralık başı (dahil).
	 * @param \DateTimeImmutable $toUtc              Aralık sonu (hariç).
	 * @param int                $resourceId         Kaynak.
	 * @param int                $excludeReservation Bu rezervasyonun satırları sayılmaz (0 = yok; yönetici saat değişimi).
	 * @return array<string,list<array{product_id:?int,starts_at:?string,capacity:?int,seats:int}>> 'Y-m-d H:i:s' UTC birim → gruplar.
	 */
	public static function occupancy( \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, int $resourceId = self::RESOURCE_ID, int $excludeReservation = 0 ): array {
		global $wpdb;
		$utc = new \DateTimeZone( 'UTC' );
		$occ = Schema::table( 'occupancy' );
		$res = Schema::table( 'reservations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi doluluk tablosu; müsaitlik okuması taze olmalı (önbellek yanlış dolu/boş gösterir); yanıt düzeyindeki önbellek Availability/RestController'da sürümlüdür.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT o.unit_start, r.product_id, r.starts_at, MIN(r.capacity) AS capacity, COUNT(*) AS seats FROM %i o LEFT JOIN %i r ON r.id = o.reservation_id WHERE o.resource_id = %d AND o.unit_start >= %s AND o.unit_start < %s AND o.reservation_id <> %d GROUP BY o.unit_start, r.product_id, r.starts_at',
				$occ,
				$res,
				$resourceId,
				$fromUtc->setTimezone( $utc )->format( self::FORMAT ),
				$toUtc->setTimezone( $utc )->format( self::FORMAT ),
				max( 0, $excludeReservation )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$known                              = null !== $r['product_id'];
			$out[ (string) $r['unit_start'] ][] = array(
				'product_id' => $known ? (int) $r['product_id'] : null,
				'starts_at'  => $known ? (string) $r['starts_at'] : null,
				'capacity'   => $known && null !== $r['capacity'] ? (int) $r['capacity'] : null,
				'seats'      => (int) $r['seats'],
			);
		}
		return $out;
	}

	/**
	 * Verilen birimlerdeki doluluk satırları ve sahip rezervasyonun kabul için gereken alanları (tek sorgu; DÜZ okuma —
	 * yazma yolunda kaynak kilidi altında çağrılır). Rezervasyonu olmayan (sahipsiz) satırda rezervasyon alanları null.
	 *
	 * @param list<string> $unitKeys   'Y-m-d H:i:s' UTC birimler.
	 * @param int          $resourceId Kaynak.
	 * @return list<array{unit_start:string,seat:int,reservation_id:int,product_id:?int,starts_at:?string,capacity:?int,status:?string,hold_expires_at:?int,order_id:?int}>
	 * @throws \RuntimeException Veritabanı hatası.
	 */
	public static function occupants( array $unitKeys, int $resourceId = self::RESOURCE_ID ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		global $wpdb;
		if ( ! $unitKeys ) {
			return array();
		}
		$occ  = Schema::table( 'occupancy' );
		$res  = Schema::table( 'reservations' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT o.unit_start, o.seat, o.reservation_id, r.product_id, r.starts_at, r.capacity, r.status, r.hold_expires_at, r.order_id FROM %i o LEFT JOIN %i r ON r.id = o.reservation_id WHERE o.resource_id = %d AND o.unit_start IN (' . implode( ',', array_fill( 0, count( $unitKeys ), '%s' ) ) . ') ORDER BY o.unit_start ASC, o.seat ASC', array_merge( array( $occ, $res, $resourceId ), array_values( $unitKeys ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi doluluk tablosu; kilit altında taze okuma gerekir, önbellek doluluğu bozar.
		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( 'slotiva_db_error: ' . esc_html( $wpdb->last_error ) );
		}
		$utc = new \DateTimeZone( 'UTC' );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$known = null !== $r['product_id'];
			$hold  = $known && null !== $r['hold_expires_at'] ? new \DateTimeImmutable( (string) $r['hold_expires_at'], $utc ) : null;
			$out[] = array(
				'unit_start'      => (string) $r['unit_start'],
				'seat'            => (int) $r['seat'],
				'reservation_id'  => (int) $r['reservation_id'],
				'product_id'      => $known ? (int) $r['product_id'] : null,
				'starts_at'       => $known ? (string) $r['starts_at'] : null,
				'capacity'        => $known ? (int) $r['capacity'] : null,
				'status'          => $known ? (string) $r['status'] : null,
				'hold_expires_at' => $hold ? $hold->getTimestamp() : null,
				'order_id'        => $known && null !== $r['order_id'] ? (int) $r['order_id'] : null,
			);
		}
		return $out;
	}
}
