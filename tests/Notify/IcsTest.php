<?php
/**
 * Takvim dosyası (spec §7, §15.11): UTC `Z` zamanlar, UID = token@host, SEQUENCE saat değişiminde ve iptalde artar,
 * ORGANIZER/DTSTAMP zorunlu, CRLF satır sonları.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Notify;

use MHM\Slotiva\Notify\Ics;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.

class IcsTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
	}

	public function tear_down(): void {
		$this->tear_down_checkout();
		parent::tear_down();
	}

	private function manual_row(): int {
		return ReservationRepository::claim(
			array(
				'product_id'    => $this->bookable(),
				'product_name'  => 'Danışmanlık; 30 dk, ön görüşme',
				'slots'         => array( new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ),
				'duration'      => 30,
				'status'        => 'confirmed',
				'payment_state' => 'manual',
				'customer'      => array(
					'name'  => 'Ali Veli',
					'email' => 'ali@example.test',
					'phone' => '05550000000',
				),
				'location'      => 'Atölye, Kadıköy',
				'notify'        => false,
			)
		)[0];
	}

	public function test_ics_utc_and_sequence(): void {
		$id  = $this->manual_row();
		$r   = $this->row( $id );
		$ics = Ics::build( $r, 'REQUEST' );

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$this->assertStringContainsString( "BEGIN:VCALENDAR\r\n", $ics, 'CRLF satır sonu' );
		$this->assertStringContainsString( "\r\nMETHOD:REQUEST\r\n", $ics );
		$this->assertStringContainsString( "\r\nUID:{$r->token}@{$host}\r\n", $ics );
		$this->assertStringContainsString( "\r\nDTSTART:20261117T070000Z\r\n", $ics );
		$this->assertStringContainsString( "\r\nDTEND:20261117T073000Z\r\n", $ics );
		$this->assertStringContainsString( "\r\nSEQUENCE:0\r\n", $ics );
		$this->assertStringContainsString( "\r\nDTSTAMP:20261116T060000Z\r\n", $ics, 'DTSTAMP = şimdi (UTC)' );
		$this->assertMatchesRegularExpression( '/\r\nORGANIZER;CN=[^:]+:mailto:[^\r]+\r\n/', $ics );
		$this->assertStringContainsString( "\r\nSUMMARY:Danışmanlık\\; 30 dk\\, ön görüşme\r\n", $ics, 'metin kaçışı (RFC 5545 §3.3.11)' );
		$this->assertStringContainsString( "\r\nLOCATION:Atölye\\, Kadıköy\r\n", $ics );
		$this->assertStringContainsString( "\r\nSTATUS:CONFIRMED\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		$this->assertDoesNotMatchRegularExpression( '/(?<!\r)\n/', $ics, 'çıplak LF yok' );

		$this->assertTrue( ReservationRepository::reschedule( $id, new \DateTimeImmutable( '2026-11-17T07:30:00Z' ), true, false ) );
		$ics2 = Ics::build( $this->row( $id ), 'REQUEST' );
		$this->assertStringContainsString( "\r\nSEQUENCE:1\r\n", $ics2, 'saat değişince SEQUENCE artar' );
		$this->assertStringContainsString( "\r\nDTSTART:20261117T073000Z\r\n", $ics2 );
		$this->assertStringContainsString( "\r\nUID:{$r->token}@{$host}\r\n", $ics2, 'UID korunur' );
	}

	public function test_cancel_ics_method_cancel(): void {
		$id     = $this->manual_row();
		$before = $this->row( $id )->sequence;
		$this->assertTrue( ReservationRepository::release( $id, 'cancelled', array( 'notify' => false ) ) );
		$r = $this->row( $id );
		// §15.11 (Codex final 4): sessiz iptal de sürümü artırır — artış iptal işleminde, e-postaya bağlı değil.
		$this->assertSame( $before + 1, $r->sequence, 'iptal sequence = önceki + 1 (notify=false)' );
		$ics = Ics::build( $r, 'CANCEL' );
		$this->assertStringContainsString( "\r\nMETHOD:CANCEL\r\n", $ics );
		$this->assertStringContainsString( "\r\nSTATUS:CANCELLED\r\n", $ics );
		$this->assertStringContainsString( "\r\nSEQUENCE:" . ( $before + 1 ) . "\r\n", $ics );
		$this->assertStringContainsString( "\r\nUID:{$r->token}@", $ics, 'UID korunur' );
		$this->assertStringContainsString( "\r\nDTSTAMP:", $ics );
		$this->assertStringContainsString( "\r\nORGANIZER;", $ics );
	}

	public function test_long_lines_are_folded_at_75_octets(): void {
		$id  = ReservationRepository::claim(
			array(
				'product_id'    => $this->bookable(),
				'product_name'  => str_repeat( 'Çok uzun hizmet adı ', 10 ),
				'slots'         => array( new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ),
				'duration'      => 30,
				'status'        => 'confirmed',
				'payment_state' => 'manual',
				'notify'        => false,
			)
		)[0];
		$ics = Ics::build( $this->row( $id ), 'REQUEST' );
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), $line );
			$this->assertTrue( mb_check_encoding( $line, 'UTF-8' ), 'katlama çok baytlı karakteri bölmez' );
		}
		$unfolded = str_replace( "\r\n ", '', $ics );
		$this->assertStringContainsString( 'SUMMARY:' . rtrim( str_repeat( 'Çok uzun hizmet adı ', 10 ) ), $unfolded );
	}

	public function test_invalid_method_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		Ics::build( $this->row( $this->manual_row() ), 'PUBLISH' );
	}
}
