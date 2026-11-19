<?php
/**
 * Haftalık çalışma düzeni (`slotiva_schedule` seçeneği). Gün 0 = Pazartesi … 6 = Pazar; saatler yerel "HH:MM".
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Haftalık aralıkları okur/doğrular/kaydeder.
 */
final class WeeklySchedule {

	public const OPTION = 'slotiva_schedule';

	/**
	 * Tüm günleri (0–6) içeren düzen; çalışılmayan gün boş listedir.
	 *
	 * @return array<int,list<array{from:string,to:string}>>
	 */
	public static function load(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		for ( $day = 0; $day <= 6; $day++ ) {
			$ranges = isset( $stored[ $day ] ) && is_array( $stored[ $day ] ) ? $stored[ $day ] : array();
			$list   = array();
			foreach ( $ranges as $r ) {
				if ( isset( $r['from'], $r['to'] ) ) {
					$list[] = array(
						'from' => (string) $r['from'],
						'to'   => (string) $r['to'],
					);
				}
			}
			$out[ $day ] = $list;
		}
		return $out;
	}

	/**
	 * Doğrular (biçim, 15 dk adımı, ters aralık, çakışma) ve TÜM düzeni değiştirir.
	 *
	 * @param array<int|string,mixed> $in Gün => aralık listesi; verilmeyen günler boşalır.
	 * @return true|\WP_Error
	 */
	public static function save( array $in ) {
		$clean = array();
		for ( $day = 0; $day <= 6; $day++ ) {
			$clean[ $day ] = array();
		}
		foreach ( $in as $day => $ranges ) {
			if ( ! is_numeric( $day ) || (int) $day < 0 || (int) $day > 6 || (string) (int) $day !== (string) $day ) {
				return new \WP_Error( 'slotiva_invalid_day', __( 'The day must be 0 (Monday) to 6 (Sunday).', 'mhm-slotiva' ) );
			}
			if ( ! is_array( $ranges ) ) {
				return new \WP_Error( 'slotiva_invalid_range', __( 'Ranges must be a list.', 'mhm-slotiva' ) );
			}
			$list = array();
			foreach ( $ranges as $r ) {
				$from = self::normalize_time( is_array( $r ) ? (string) ( $r['from'] ?? '' ) : '' );
				$to   = self::normalize_time( is_array( $r ) ? (string) ( $r['to'] ?? '' ) : '' );
				if ( null === $from || null === $to ) {
					return new \WP_Error( 'slotiva_invalid_time', __( 'Times must be HH:MM on a 15-minute step.', 'mhm-slotiva' ) );
				}
				if ( $from >= $to ) {
					return new \WP_Error( 'slotiva_invalid_range', __( 'A range must start before it ends.', 'mhm-slotiva' ) );
				}
				$list[] = array(
					'from' => $from,
					'to'   => $to,
				);
			}
			usort(
				$list,
				static fn( array $a, array $b ): int => strcmp( $a['from'], $b['from'] )
			);
			$count = count( $list );
			for ( $i = 1; $i < $count; $i++ ) {
				if ( $list[ $i ]['from'] < $list[ $i - 1 ]['to'] ) {
					return new \WP_Error( 'slotiva_overlap', __( 'Ranges on the same day must not overlap.', 'mhm-slotiva' ) );
				}
			}
			$clean[ (int) $day ] = $list;
		}

		update_option( self::OPTION, $clean, false );
		do_action( 'slotiva_schedule_changed' );
		return true;
	}

	/**
	 * "H:MM", "HH:MM" veya "HH:MM:SS" → "HH:MM"; geçersiz veya 15 dk adımına uymayan → null. "24:00" kabul edilir (gün sonu).
	 *
	 * @param string $t Ham saat.
	 */
	public static function normalize_time( string $t ): ?string {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::00)?$/', $t, $m ) ) {
			return null;
		}
		$h   = (int) $m[1];
		$min = (int) $m[2];
		if ( 0 !== $min % Rules::STEP || $min > 59 || $h > 24 || ( 24 === $h && 0 !== $min ) ) {
			return null;
		}
		return sprintf( '%02d:%02d', $h, $min );
	}
}
