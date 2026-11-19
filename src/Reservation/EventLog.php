<?php
/**
 * Rezervasyon geçmişi (`slotiva_events`): ayrıntı ekranındaki "Geçmiş".
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- imzalar sözleşmeyle $reservationId.

/**
 * Olay satırı ekler/okur. `actor` = oturumdaki kullanıcı kimliği, yoksa `system`.
 */
final class EventLog {

	/**
	 * Olay ekler. Açık bir işlemin içinde çağrılırsa o işlemle birlikte yazılır/geri alınır.
	 *
	 * @param int                 $reservationId Rezervasyon.
	 * @param string              $type          Olay türü (en çok 40 karakter).
	 * @param array<string,mixed> $data          JSON'a çevrilecek ek veri.
	 */
	public static function add( int $reservationId, string $type, array $data = array() ): void {
		self::insert( $reservationId, $type, $data );
	}

	/**
	 * `add()` ile aynı; sonucu döndürür (depo işlem içinde başarısızlığı denetler).
	 *
	 * @param int                 $reservationId Rezervasyon.
	 * @param string              $type          Olay türü (en çok 40 karakter).
	 * @param array<string,mixed> $data          JSON'a çevrilecek ek veri.
	 * @return bool Yazıldı mı.
	 */
	public static function insert( int $reservationId, string $type, array $data = array() ): bool {
		global $wpdb;
		$user = get_current_user_id();
		return false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- eklentinin kendi tablosu (olaylar) için $wpdb->insert; WordPress API'si yok.
			Schema::table( 'events' ),
			array(
				'reservation_id' => $reservationId,
				'type'           => substr( $type, 0, 40 ),
				'actor'          => $user > 0 ? (string) $user : 'system',
				'data'           => (string) wp_json_encode( $data ),
				'created_at'     => Clock::now()->format( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Bir rezervasyonun olayları, eskiden yeniye.
	 *
	 * @param int $reservationId Rezervasyon.
	 * @return list<array{id:int,reservation_id:int,type:string,actor:string,data:array<string,mixed>,created_at:string}>
	 */
	public static function for( int $reservationId ): array {
		global $wpdb;
		$table = Schema::table( 'events' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi olay tablosu (yalnız ekleme); WordPress API'si yok, yönetici ayrıntısı tek seferlik okur.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, reservation_id, type, actor, data, created_at FROM %i WHERE reservation_id = %d ORDER BY id ASC',
				$table,
				$reservationId
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$data  = json_decode( (string) $r['data'], true );
			$out[] = array(
				'id'             => (int) $r['id'],
				'reservation_id' => (int) $r['reservation_id'],
				'type'           => (string) $r['type'],
				'actor'          => (string) $r['actor'],
				'data'           => is_array( $data ) ? $data : array(),
				'created_at'     => (string) $r['created_at'],
			);
		}
		return $out;
	}
}
