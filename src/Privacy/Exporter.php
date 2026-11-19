<?php
/**
 * WP "Kişisel veriyi dışa aktar" kaydı: e-postaya göre rezervasyonlar + olay geçmişi (spec §Kişisel veri).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Privacy;

use MHM\Slotiva\Db\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- yöntem adları sözleşmeyle camelCase; eklentinin kendi rezervasyon/olay tabloları (WP kişisel veri dışa aktarıcı), sayfalı tek seferlik okuma, önbellek anlamsız.

/**
 * Sayfa boyutu 100 rezervasyon; `done` = sayfa dolu değil. Dışa aktarma satırları DEĞİŞTİRMEZ, bu yüzden OFFSET sayfalaması
 * kararlıdır (id sırası).
 */
final class Exporter {

	public const PAGE = 100;

	/**
	 * Kanca.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add' ) );
	}

	/**
	 * Dışa aktarıcıyı kaydeder.
	 *
	 * @param array<string,mixed>|mixed $exporters Kayıtlar.
	 * @return array<string,mixed>
	 */
	public static function add( $exporters ): array {
		$exporters                = (array) $exporters;
		$exporters['mhm-slotiva'] = array(
			'exporter_friendly_name' => __( 'MHM Slotiva appointments', 'mhm-slotiva' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * WP sözleşmesi: `array{data: list<array>, done: bool}`.
	 *
	 * @param string $email E-posta.
	 * @param int    $page  Sayfa (1'den).
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$res    = Schema::table( 'reservations' );
		$evt    = Schema::table( 'events' );
		$offset = ( max( 1, $page ) - 1 ) * self::PAGE;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d', $res, $email, self::PAGE, $offset ), ARRAY_A );

		$items = array();
		$ids   = array();
		foreach ( $rows as $row ) {
			$ids[]   = (int) $row['id'];
			$items[] = array(
				'group_id'    => 'mhm-slotiva-reservations',
				'group_label' => __( 'Appointments', 'mhm-slotiva' ),
				'item_id'     => 'slotiva-reservation-' . (int) $row['id'],
				'data'        => self::reservationFields( $row ),
			);
		}

		if ( $ids ) {
			$events = $wpdb->get_results( $wpdb->prepare( 'SELECT id, reservation_id, type, actor, data, created_at FROM %i WHERE reservation_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC', array_merge( array( $evt ), $ids ) ), ARRAY_A );
			foreach ( (array) $events as $e ) {
				$items[] = array(
					'group_id'    => 'mhm-slotiva-events',
					'group_label' => __( 'Appointment history', 'mhm-slotiva' ),
					'item_id'     => 'slotiva-event-' . (int) $e['id'],
					'data'        => array(
						self::field( __( 'Appointment', 'mhm-slotiva' ), '#' . (int) $e['reservation_id'] ),
						self::field( __( 'Event', 'mhm-slotiva' ), (string) $e['type'] ),
						self::field( __( 'Recorded by', 'mhm-slotiva' ), (string) $e['actor'] ),
						self::field( __( 'Recorded at (UTC)', 'mhm-slotiva' ), (string) $e['created_at'] ),
						self::field( __( 'Details', 'mhm-slotiva' ), self::details( (string) $e['data'] ) ),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < self::PAGE,
		);
	}

	/**
	 * Bir rezervasyonun dışa aktarım alanları.
	 *
	 * @param array<string,mixed> $row Satır.
	 * @return list<array{name:string,value:string}>
	 */
	private static function reservationFields( array $row ): array {
		$local = ( new \DateTimeImmutable( (string) $row['starts_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() );
		return array(
			self::field( __( 'Date', 'mhm-slotiva' ), $local->format( 'Y-m-d' ) ),
			self::field( __( 'Time', 'mhm-slotiva' ), $local->format( 'H:i' ) ),
			self::field( __( 'Service', 'mhm-slotiva' ), (string) $row['product_name'] ),
			self::field( __( 'Name', 'mhm-slotiva' ), (string) $row['customer_name'] ),
			self::field( __( 'Phone', 'mhm-slotiva' ), (string) $row['customer_phone'] ),
			self::field( __( 'Email', 'mhm-slotiva' ), (string) $row['customer_email'] ),
			self::field( __( 'Appointment status', 'mhm-slotiva' ), (string) $row['status'] ),
			self::field( __( 'Order no', 'mhm-slotiva' ), null === $row['order_id'] ? '' : (string) $row['order_id'] ),
			self::field( __( 'Created (UTC)', 'mhm-slotiva' ), (string) $row['created_at'] ),
		);
	}

	/**
	 * Olay verisi okunur JSON olarak (Unicode kaçışsız).
	 *
	 * @param string $raw Ham JSON.
	 */
	private static function details( string $raw ): string {
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? (string) wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ) : $raw;
	}

	/**
	 * Ad/değer çifti.
	 *
	 * @param string $name  Ad.
	 * @param string $value Değer.
	 * @return array{name:string,value:string}
	 */
	private static function field( string $name, string $value ): array {
		return array(
			'name'  => $name,
			'value' => $value,
		);
	}
}
