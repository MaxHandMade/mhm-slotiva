<?php
/**
 * Tarih istisnaları (tatil/kapalı gün ya da ek açık saat) — `slotiva_exceptions` tablosu.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Schedule;

use MHM\Slotiva\Db\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- between() imzası sözleşmeyle $fromDate/$toDate.

/**
 * İstisna satırlarını okur/ekler/siler. Tarihler yerel; `from_time`/`to_time` NULL = tüm gün.
 */
final class ExceptionRepository {

	/**
	 * Uçlar dahil aralıktaki istisnalar (tarih, başlangıç, kimlik sırasıyla).
	 *
	 * @param string $fromDate Y-m-d.
	 * @param string $toDate   Y-m-d.
	 * @return list<array{id:int,date:string,from_time:?string,to_time:?string,type:'open'|'closed',note:string}>
	 */
	public static function between( string $fromDate, string $toDate ): array {
		global $wpdb;
		$table = Schema::table( 'exceptions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi tarih istisnaları tablosu; WordPress API'si yok; müsaitlik yanıtı kendi sürümlü önbelleğinde (Availability/RestController).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, date, from_time, to_time, type, note FROM %i WHERE date >= %s AND date <= %s ORDER BY date ASC, from_time ASC, id ASC',
				$table,
				$fromDate,
				$toDate
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'        => (int) $r['id'],
				'date'      => (string) $r['date'],
				'from_time' => null === $r['from_time'] ? null : substr( (string) $r['from_time'], 0, 5 ),
				'to_time'   => null === $r['to_time'] ? null : substr( (string) $r['to_time'], 0, 5 ),
				'type'      => 'open' === $r['type'] ? 'open' : 'closed',
				'note'      => (string) $r['note'],
			);
		}
		return $out;
	}

	/**
	 * İstisna ekler.
	 *
	 * @param array<string,mixed> $in date (Y-m-d), type (open|closed), from_time/to_time (ikisi birden ya da hiçbiri), note.
	 * @return int|\WP_Error Yeni satırın kimliği.
	 */
	public static function add( array $in ) {
		global $wpdb;
		$date = (string) ( $in['date'] ?? '' );
		$d    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) {
			return new \WP_Error( 'slotiva_invalid_date', __( 'The date must be a valid date (YYYY-MM-DD).', 'mhm-slotiva' ) );
		}
		$type = (string) ( $in['type'] ?? '' );
		if ( ! in_array( $type, array( 'open', 'closed' ), true ) ) {
			return new \WP_Error( 'slotiva_invalid_type', __( 'The type must be open or closed.', 'mhm-slotiva' ) );
		}

		$from_raw = isset( $in['from_time'] ) ? (string) $in['from_time'] : '';
		$to_raw   = isset( $in['to_time'] ) ? (string) $in['to_time'] : '';
		$from     = null;
		$to       = null;
		if ( '' !== $from_raw || '' !== $to_raw ) {
			$from = WeeklySchedule::normalize_time( $from_raw );
			$to   = WeeklySchedule::normalize_time( $to_raw );
			if ( null === $from || null === $to ) {
				return new \WP_Error( 'slotiva_invalid_time', __( 'Both times are required, as HH:MM on a 15-minute step.', 'mhm-slotiva' ) );
			}
			if ( $from >= $to ) {
				return new \WP_Error( 'slotiva_invalid_range', __( 'The start must be before the end.', 'mhm-slotiva' ) );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- eklentinin kendi tablosu (tarih istisnaları) için $wpdb->insert; WordPress API'si yok.
		$ok = $wpdb->insert(
			Schema::table( 'exceptions' ),
			array(
				'date'      => $date,
				'from_time' => null === $from ? null : $from . ':00',
				'to_time'   => null === $to ? null : $to . ':00',
				'type'      => $type,
				'note'      => mb_substr( sanitize_text_field( (string) ( $in['note'] ?? '' ) ), 0, 255 ),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'slotiva_db_error', __( 'Could not save the exception.', 'mhm-slotiva' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		do_action( 'slotiva_schedule_changed' );
		return $id;
	}

	/**
	 * Siler; satır yoksa false (olay tetiklenmez), veritabanı hatasında 500 durumlu WP_Error ($wpdb->delete hatada false,
	 * etkilenen satır yoksa 0 döndürür).
	 *
	 * @param int $id Satır kimliği.
	 * @return bool|\WP_Error
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$n = $wpdb->delete( Schema::table( 'exceptions' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi tablosu (tarih istisnaları) için $wpdb->delete; müsaitlik önbelleği slotiva_schedule_changed ile geçersiz kılınır.
		if ( false === $n ) {
			return new \WP_Error( 'slotiva_db_error', __( 'Could not delete the exception.', 'mhm-slotiva' ), array( 'status' => 500 ) );
		}
		if ( 0 === $n ) {
			return false;
		}
		do_action( 'slotiva_schedule_changed' );
		return true;
	}
}
