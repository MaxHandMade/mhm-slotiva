<?php
/**
 * WP dışa aktarıcı (spec senaryo 21): alanlar, olaylar, WP zarfı, çok sayfa.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Privacy;

use MHM\Slotiva\Privacy\Exporter;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\PrivacyFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.

class ExporterTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use PrivacyFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
	}

	public function tear_down(): void {
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * Öğe verisini ad => değer eşlemesine çevirir.
	 *
	 * @param array<string,mixed> $item Öğe.
	 * @return array<string,string>
	 */
	private static function fields( array $item ): array {
		$out = array();
		foreach ( $item['data'] as $f ) {
			$out[ $f['name'] ] = $f['value'];
		}
		return $out;
	}

	public function test_registered_with_wp(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$this->assertArrayHasKey( 'mhm-slotiva', $exporters );
		$this->assertIsCallable( $exporters['mhm-slotiva']['callback'] );
		$this->assertNotSame( '', $exporters['mhm-slotiva']['exporter_friendly_name'] );
	}

	public function test_export_contains_reservation_fields(): void {
		$ids = $this->make_bookings( 1, 'ayse@example.test' );
		$this->make_bookings( 1, 'baska@example.test', '2026-12-05T07:00:00Z', 'Başka Kişi' );

		$out = Exporter::export( 'ayse@example.test' );

		$this->assertTrue( $out['done'] );
		$reservation = array_values( array_filter( $out['data'], static fn( $i ) => 'mhm-slotiva-reservations' === $i['group_id'] ) );
		$this->assertCount( 1, $reservation, 'yalnız bu e-postanın randevusu' );
		$this->assertSame( 'slotiva-reservation-' . $ids[0], $reservation[0]['item_id'] );
		$f = self::fields( $reservation[0] );
		$this->assertSame( '2026-12-01', $f['Date'] );
		$this->assertSame( '10:00', $f['Time'], 'yerel saat (İstanbul)' );
		$this->assertSame( 'Danışmanlık', $f['Service'] );
		$this->assertSame( 'Ayşe Yılmaz', $f['Name'] );
		$this->assertSame( '+905551112233', $f['Phone'] );
		$this->assertSame( 'ayse@example.test', $f['Email'] );
	}

	public function test_export_includes_event_pii(): void {
		$this->make_bookings( 1, 'ayse@example.test' );
		$out    = Exporter::export( 'ayse@example.test' );
		$events = array_values( array_filter( $out['data'], static fn( $i ) => 'mhm-slotiva-events' === $i['group_id'] ) );
		$this->assertGreaterThanOrEqual( 2, count( $events ), 'status + note_added' );
		$details = array();
		foreach ( $events as $e ) {
			$details[] = self::fields( $e )['Details'];
		}
		$this->assertStringContainsString( 'Yönetici Kişi', implode( ' ', $details ), 'olay verisindeki kişisel alanlar dışa aktarılır' );
	}

	public function test_export_paginates_over_150_records_without_gaps(): void {
		$ids = $this->make_bookings( 150, 'cok@example.test' );

		$p1 = Exporter::export( 'cok@example.test', 1 );
		$p2 = Exporter::export( 'cok@example.test', 2 );
		$p3 = Exporter::export( 'cok@example.test', 3 );

		$this->assertFalse( $p1['done'] );
		$this->assertTrue( $p2['done'] );
		$this->assertSame( array(), $p3['data'] );
		$this->assertTrue( $p3['done'] );

		$seen = array();
		foreach ( array_merge( $p1['data'], $p2['data'] ) as $item ) {
			if ( 'mhm-slotiva-reservations' === $item['group_id'] ) {
				$seen[] = $item['item_id'];
			}
		}
		$this->assertCount( 100, array_filter( $p1['data'], static fn( $i ) => 'mhm-slotiva-reservations' === $i['group_id'] ) );
		$this->assertCount( 150, array_unique( $seen ) );
		$this->assertSame( array_map( static fn( $id ) => 'slotiva-reservation-' . $id, $ids ), $seen, 'id sırası, tekrar ve atlama yok' );
	}

	public function test_unknown_email_exports_nothing(): void {
		$out = Exporter::export( 'yok@example.test' );
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$out
		);
	}
}
