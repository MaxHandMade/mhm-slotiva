<?php
/**
 * İstisna deposu testleri.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Schedule;

use MHM\Slotiva\Schedule\ExceptionRepository;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class ExceptionRepositoryTest extends SlotivaTestCase {

	public function test_exception_open_and_closed_roundtrip(): void {
		$closed = ExceptionRepository::add(
			array(
				'date' => '2026-12-24',
				'type' => 'closed',
				'note' => 'Tatil',
			)
		);
		$open   = ExceptionRepository::add(
			array(
				'date'      => '2026-12-26',
				'from_time' => '10:00',
				'to_time'   => '12:30',
				'type'      => 'open',
			)
		);
		$this->assertIsInt( $closed );
		$this->assertIsInt( $open );

		$rows = ExceptionRepository::between( '2026-12-01', '2026-12-31' );
		$this->assertCount( 2, $rows );
		$this->assertSame( $closed, $rows[0]['id'] );
		$this->assertSame( '2026-12-24', $rows[0]['date'] );
		$this->assertNull( $rows[0]['from_time'] );
		$this->assertNull( $rows[0]['to_time'] );
		$this->assertSame( 'closed', $rows[0]['type'] );
		$this->assertSame( 'Tatil', $rows[0]['note'] );
		$this->assertSame( '10:00', $rows[1]['from_time'] );
		$this->assertSame( '12:30', $rows[1]['to_time'] );
		$this->assertSame( 'open', $rows[1]['type'] );

		// Uçlar dahil, dışı hariç.
		$this->assertCount( 1, ExceptionRepository::between( '2026-12-24', '2026-12-24' ) );
		$this->assertCount( 0, ExceptionRepository::between( '2026-12-27', '2026-12-31' ) );

		$this->assertTrue( ExceptionRepository::delete( $closed ) );
		$this->assertFalse( ExceptionRepository::delete( $closed ) );
		$this->assertCount( 1, ExceptionRepository::between( '2026-12-01', '2026-12-31' ) );
	}

	public function test_add_rejects_invalid_input(): void {
		$bad = array(
			array(
				'date' => 'yarin',
				'type' => 'closed',
			),
			array(
				'date' => '2026-12-24',
				'type' => 'maybe',
			),
			array(
				'date'      => '2026-12-24',
				'type'      => 'open',
				'from_time' => '12:00',
				'to_time'   => '10:00',
			),
			array(
				'date'      => '2026-12-24',
				'type'      => 'open',
				'from_time' => '10:00',
			),
			array(
				'date'      => '2026-12-24',
				'type'      => 'open',
				'from_time' => '10:10',
				'to_time'   => '11:00',
			),
			array(
				'date' => '2026-02-31',
				'type' => 'closed',
			),
		);
		foreach ( $bad as $i => $in ) {
			$this->assertWPError( ExceptionRepository::add( $in ), 'vaka ' . $i );
		}
		$this->assertCount( 0, ExceptionRepository::between( '2026-01-01', '2026-12-31' ) );
	}

	public function test_saves_fire_schedule_changed(): void {
		$n = 0;
		add_action(
			'slotiva_schedule_changed',
			static function () use ( &$n ) {
				++$n;
			}
		);
		$id = ExceptionRepository::add(
			array(
				'date' => '2026-12-24',
				'type' => 'closed',
			)
		);
		$this->assertSame( 1, $n );
		ExceptionRepository::delete( (int) $id );
		$this->assertSame( 2, $n );
		ExceptionRepository::delete( 99999 );
		$this->assertSame( 2, $n );
	}
}
