<?php
/**
 * Haftalık düzen testleri.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Schedule;

use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class WeeklyScheduleTest extends SlotivaTestCase {

	/**
	 * Aralık dizisi kurar.
	 *
	 * @param string $from Başlangıç.
	 * @param string $to   Bitiş.
	 * @return array{from:string,to:string}
	 */
	private function range( string $from, string $to ): array {
		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	public function test_weekly_rejects_overlap(): void {
		$res = WeeklySchedule::save(
			array(
				0 => array( $this->range( '10:00', '12:00' ), $this->range( '11:00', '13:00' ) ),
			)
		);
		$this->assertWPError( $res );
	}

	public function test_weekly_rejects_reversed_and_bad_format_and_bad_day(): void {
		$this->assertWPError( WeeklySchedule::save( array( 1 => array( $this->range( '12:00', '10:00' ) ) ) ) );
		$this->assertWPError( WeeklySchedule::save( array( 1 => array( $this->range( '9am', '10:00' ) ) ) ) );
		$this->assertWPError( WeeklySchedule::save( array( 7 => array( $this->range( '09:00', '10:00' ) ) ) ) );
		$this->assertWPError( WeeklySchedule::save( array( 1 => array( $this->range( '09:10', '10:00' ) ) ) ) );
	}

	public function test_weekly_roundtrip_sorted_and_all_days_present(): void {
		$this->assertTrue(
			WeeklySchedule::save(
				array(
					2 => array( $this->range( '14:00', '18:00' ), $this->range( '09:00', '12:00' ) ),
				)
			)
		);
		$s = WeeklySchedule::load();
		$this->assertSame( array( 0, 1, 2, 3, 4, 5, 6 ), array_keys( $s ) );
		$this->assertSame( '09:00', $s[2][0]['from'] );
		$this->assertSame( '14:00', $s[2][1]['from'] );
		$this->assertSame( array(), $s[0] );
	}

	public function test_saves_fire_schedule_changed(): void {
		$n = 0;
		add_action(
			'slotiva_schedule_changed',
			static function () use ( &$n ) {
				++$n;
			}
		);
		WeeklySchedule::save( array( 0 => array( $this->range( '09:00', '10:00' ) ) ) );
		$this->assertSame( 1, $n );
		WeeklySchedule::save( array( 0 => array( $this->range( '10:00', '09:00' ) ) ) );
		$this->assertSame( 1, $n );
	}
}
