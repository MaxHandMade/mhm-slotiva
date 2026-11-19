<?php
/**
 * Kişisel veri testleri için ortak kurgu: kişisel alanlı onaylı randevular + kişisel veri taşıyan olaylar.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Reservation\EventLog;
use MHM\Slotiva\Reservation\ReservationRepository;

trait PrivacyFixtures {

	/**
	 * Her biri ayrı dilimde `$count` onaylı, elle randevu; her birine kişisel veri taşıyan bir olay eklenir.
	 *
	 * @param int    $count Adet.
	 * @param string $email E-posta.
	 * @param string $start İlk dilim (UTC, ISO).
	 * @param string $name  Ad.
	 * @return list<int> Kimlikler.
	 */
	private function make_bookings( int $count, string $email, string $start = '2026-12-01T07:00:00Z', string $name = 'Ayşe Yılmaz' ): array {
		$pid = $this->bookable();
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$slot  = ( new \DateTimeImmutable( $start ) )->modify( '+' . ( 30 * $i ) . ' minutes' );
			$id    = ReservationRepository::claim(
				array(
					'product_id'    => $pid,
					'product_name'  => 'Danışmanlık',
					'slots'         => array( $slot ),
					'duration'      => 30,
					'status'        => 'confirmed',
					'payment_state' => 'manual',
					'customer'      => array(
						'name'  => $name,
						'email' => $email,
						'phone' => '+905551112233',
					),
					'notify'        => false,
				)
			)[0];
			$ids[] = $id;
			EventLog::add(
				$id,
				'note_added',
				array(
					'admin_name'     => 'Yönetici Kişi',
					'customer_email' => $email,
					'text_copy'      => 'Aranacak: ' . $name,
					'reason'         => 'admin',
				)
			);
		}
		return $ids;
	}

	/**
	 * Rezervasyon satırı (ham).
	 *
	 * @param int $id Kimlik.
	 * @return array<string,mixed>
	 */
	private function raw_row( int $id ): array {
		global $wpdb;
		$table = Schema::table( 'reservations' );
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test kurgusu.
	}

	/**
	 * Tüm olay satırlarının birleşik metni (kişisel veri sızıntısı araması için).
	 */
	private function all_event_text(): string {
		global $wpdb;
		$table = Schema::table( 'events' );
		return implode( "\n", (array) $wpdb->get_col( "SELECT CONCAT(actor, data) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test kurgusu.
	}
}
