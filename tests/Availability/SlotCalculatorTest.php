<?php
/**
 * Birimler ve müsaitlik hesabı testleri.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Availability;

use MHM\Slotiva\Availability\SlotCalculator;
use MHM\Slotiva\Availability\Units;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Schedule\ExceptionRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class SlotCalculatorTest extends SlotivaTestCase {

	/**
	 * Test öncesi saat dilimi seçenekleri.
	 *
	 * @var array<string,mixed>
	 */
	private array $tz_backup = array();

	public function set_up(): void {
		parent::set_up();
		$this->tz_backup = array(
			'timezone_string' => get_option( 'timezone_string' ),
			'gmt_offset'      => get_option( 'gmt_offset' ),
		);
		update_option( 'timezone_string', 'Europe/Istanbul' );
		update_option( 'gmt_offset', 0 );
		// Pzt 2026-11-16 09:00 İstanbul.
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:00:00Z' ) );
		$day = array(
			array(
				'from' => '10:00',
				'to'   => '12:00',
			),
			array(
				'from' => '13:00',
				'to'   => '17:00',
			),
		);
		WeeklySchedule::save(
			array(
				0 => $day,
				1 => $day,
				2 => $day,
				3 => $day,
				4 => $day,
			)
		);
		Rules::save(
			array(
				'leadHours'   => 24,
				'horizonDays' => 14,
				'bufferMin'   => 0,
			)
		);
	}

	public function tear_down(): void {
		update_option( 'timezone_string', $this->tz_backup['timezone_string'] );
		update_option( 'gmt_offset', $this->tz_backup['gmt_offset'] );
		parent::tear_down();
	}

	private function product( int $duration ): BookableProduct {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Danışmanlık' );
		$p->set_regular_price( '100' );
		$p->update_meta_data( '_slotiva_enabled', 'yes' );
		$p->update_meta_data( '_slotiva_duration', (string) $duration );
		$p->save();
		$bookable = BookableProduct::from( $p );
		$this->assertNotNull( $bookable );
		return $bookable;
	}
	/**
	 * Etiket listesi.
	 *
	/**
	 *
	 * @param BookableProduct $p    Ürün.
	 * @param string          $date Yerel gün.
	 * @return list<string>
	 */
	private function labels( BookableProduct $p, string $date ): array {
		return array_column( SlotCalculator::slots( $p, $date ), 'label' );
	}

	/**
	 * Verilen başlangıç için süre+ara birimlerini dolu yazar.
	 *
	 * @param string $start_utc   UTC başlangıç.
	 * @param int    $duration    Süre (dk).
	 * @param int    $buffer      Ara (dk).
	 * @param int    $reservation Rezervasyon kimliği.
	 */
	private function occupy( string $start_utc, int $duration, int $buffer, int $reservation = 1 ): void {
		global $wpdb;
		foreach ( Units::span( new \DateTimeImmutable( $start_utc ), $duration, $buffer ) as $unit ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test verisi.
				Schema::table( 'occupancy' ),
				array(
					'resource_id'    => 1,
					'unit_start'     => $unit->format( Units::FORMAT ),
					'seat'           => 1,
					'reservation_id' => $reservation,
				)
			);
		}
	}

	public function test_units_30min_is_two(): void {
		$t = new \DateTimeImmutable( '2026-11-17T07:00:00Z' );
		$this->assertCount( 2, Units::span( $t, 30, 0 ) );
	}

	public function test_units_include_buffer(): void {
		$t     = new \DateTimeImmutable( '2026-11-17T07:00:00Z' );
		$units = Units::span( $t, 30, 15 );
		$this->assertCount( 3, $units );
		$this->assertSame( '2026-11-17 07:30:00', $units[2]->format( Units::FORMAT ) );
	}

	public function test_slots_30min_grid(): void {
		$labels = $this->labels( $this->product( 30 ), '2026-11-17' );
		$this->assertSame(
			array( '10:00', '10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30' ),
			$labels
		);
		$slots = SlotCalculator::slots( $this->product( 30 ), '2026-11-17' );
		$this->assertSame( '2026-11-17T07:00:00Z', $slots[0]['start'] );
	}

	public function test_slots_60min_grid(): void {
		$this->assertSame(
			array( '10:00', '11:00', '13:00', '14:00', '15:00', '16:00' ),
			$this->labels( $this->product( 60 ), '2026-11-17' )
		);
	}

	public function test_lead_time_hides_tomorrow_morning(): void {
		$this->assertSame( array(), $this->labels( $this->product( 30 ), '2026-11-16' ) );
	}

	public function test_horizon_excludes_day_15(): void {
		// days() ufku aşan günü hiç döndürmez; slots() de boştur.
		$days = SlotCalculator::days( $this->product( 30 ), '2026-11-30', '2026-12-01' );
		$this->assertCount( 1, $days );
		$this->assertSame( '2026-11-30', $days[0]['date'] );
		$this->assertSame( array(), $this->labels( $this->product( 30 ), '2026-12-01' ) );
	}

	public function test_horizon_inclusive_calendar_days(): void {
		$p    = $this->product( 30 );
		$days = SlotCalculator::days( $p, '2026-11-30', '2026-11-30' );
		$this->assertTrue( $days[0]['open'] );
		$this->assertSame( 12, $days[0]['free'] );
		$this->assertTrue( SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-11-30T07:00:00Z' ) ), 'son ufuk günü' );
		$this->assertFalse( SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-12-01T07:00:00Z' ) ), 'ertesi gün' );
	}

	public function test_days_range_is_clamped_to_today_and_horizon(): void {
		$days = SlotCalculator::days( $this->product( 30 ), '2026-11-01', '2027-03-01' );
		$this->assertCount( 15, $days );
		$this->assertSame( '2026-11-16', $days[0]['date'] );
		$this->assertSame( '2026-11-30', $days[14]['date'] );
		$this->assertSame( array(), SlotCalculator::days( $this->product( 30 ), '2026-10-01', '2026-11-15' ) );
	}

	public function test_days_never_returns_more_than_62_days(): void {
		Rules::save( array( 'horizonDays' => 62 ) );
		$days = SlotCalculator::days( $this->product( 30 ), '2026-01-01', '2028-01-01' );
		$this->assertCount( 62, $days );
		$this->assertSame( '2026-11-16', $days[0]['date'] );
		$this->assertSame( '2027-01-16', $days[61]['date'] );
	}

	public function test_fully_booked_day_is_open_with_zero_free(): void {
		$p = $this->product( 30 );
		foreach ( SlotCalculator::slots( $p, '2026-11-17' ) as $slot ) {
			$this->occupy( $slot['start'], 30, 0 );
		}
		$days = SlotCalculator::days( $p, '2026-11-17', '2026-11-17' );
		$this->assertTrue( $days[0]['open'] );
		$this->assertSame( 0, $days[0]['free'] );
		$this->assertSame( array(), $this->labels( $p, '2026-11-17' ) );
	}

	public function test_whole_day_closed_exception_removes_the_day(): void {
		ExceptionRepository::add(
			array(
				'date' => '2026-11-18',
				'type' => 'closed',
			)
		);
		$days = SlotCalculator::days( $this->product( 30 ), '2026-11-18', '2026-11-18' );
		$this->assertFalse( $days[0]['open'] );
		$this->assertSame( 0, $days[0]['free'] );
	}

	public function test_closed_wins_over_overlapping_open(): void {
		ExceptionRepository::add(
			array(
				'date'      => '2026-11-21',
				'type'      => 'open',
				'from_time' => '10:00',
				'to_time'   => '12:00',
			)
		);
		ExceptionRepository::add(
			array(
				'date'      => '2026-11-21',
				'type'      => 'closed',
				'from_time' => '10:30',
				'to_time'   => '11:30',
			)
		);
		$this->assertSame( array( '10:00', '11:30' ), $this->labels( $this->product( 30 ), '2026-11-21' ) );
	}

	public function test_unaligned_range_grid_starts_at_range_start(): void {
		WeeklySchedule::save(
			array(
				1 => array(
					array(
						'from' => '10:15',
						'to'   => '12:00',
					),
				),
			)
		);
		$this->assertSame( array( '10:15' ), $this->labels( $this->product( 60 ), '2026-11-17' ) );
	}

	public function test_slot_ending_after_range_end_is_not_offered(): void {
		WeeklySchedule::save(
			array(
				1 => array(
					array(
						'from' => '10:00',
						'to'   => '11:30',
					),
				),
			)
		);
		$this->assertSame( array( '10:00' ), $this->labels( $this->product( 60 ), '2026-11-17' ) );
	}

	public function test_buffer_blocks_next_slot_start_in_slots(): void {
		Rules::save( array( 'bufferMin' => 15 ) );
		$p = $this->product( 30 );
		// 10:00 yerel = 07:00Z; 30 + 15 tampon = 10:00, 10:15, 10:30 dolu.
		$this->occupy( '2026-11-17T07:00:00Z', 30, 15 );
		$labels = $this->labels( $p, '2026-11-17' );
		$this->assertNotContains( '10:00', $labels );
		$this->assertNotContains( '10:30', $labels );
		$this->assertContains( '11:00', $labels );
	}

	public function test_lead_boundary_exact_is_offered_one_step_earlier_is_not(): void {
		// Şimdi Pzt 10:00 yerel: en erken Sal 10:00 yerel (07:00Z).
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$p = $this->product( 30 );
		$this->assertSame( '10:00', $this->labels( $p, '2026-11-17' )[0] );
		$this->assertTrue( SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ) );

		// Şimdi 15 dk ileri: 10:00 artık erken kalır.
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:15:00Z' ) );
		$this->assertSame( '10:30', $this->labels( $p, '2026-11-17' )[0] );
		$this->assertFalse( SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ) );
	}

	public function test_closed_exception_removes_range(): void {
		ExceptionRepository::add(
			array(
				'date'      => '2026-11-18',
				'type'      => 'closed',
				'from_time' => '14:00',
				'to_time'   => '16:00',
			)
		);
		$labels = $this->labels( $this->product( 30 ), '2026-11-18' );
		foreach ( array( '14:00', '14:30', '15:00', '15:30' ) as $gone ) {
			$this->assertNotContains( $gone, $labels );
		}
		$this->assertContains( '13:30', $labels );
		$this->assertContains( '16:00', $labels );
	}

	public function test_open_exception_adds_saturday(): void {
		ExceptionRepository::add(
			array(
				'date'      => '2026-11-21',
				'type'      => 'open',
				'from_time' => '10:00',
				'to_time'   => '12:00',
			)
		);
		$this->assertSame( array( '10:00', '10:30', '11:00', '11:30' ), $this->labels( $this->product( 30 ), '2026-11-21' ) );
	}

	public function test_busy_unit_hides_overlapping_mixed_duration(): void {
		// 60 dk randevu 10:00 yerel = 07:00Z.
		$this->occupy( '2026-11-17T07:00:00Z', 60, 0 );
		$labels = $this->labels( $this->product( 30 ), '2026-11-17' );
		$this->assertNotContains( '10:00', $labels );
		$this->assertNotContains( '10:30', $labels );
		$this->assertContains( '11:00', $labels );
	}

	public function test_busy_units_reduce_days_free_count(): void {
		$p = $this->product( 30 );
		$this->occupy( '2026-11-17T07:00:00Z', 60, 0 );
		$days = SlotCalculator::days( $p, '2026-11-17', '2026-11-17' );
		$this->assertSame( 10, $days[0]['free'] );
	}

	public function test_is_bookable_ignores_occupancy(): void {
		$p     = $this->product( 30 );
		$start = new \DateTimeImmutable( '2026-11-17T07:00:00Z' );
		$this->occupy( '2026-11-17T07:00:00Z', 30, 0 );
		$this->assertTrue( SlotCalculator::isBookable( $p, $start ) );
		$this->assertFalse( SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-11-17T07:15:00Z' ) ), 'ızgara dışı' );
		$this->assertFalse( SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-11-17T09:00:00Z' ) ), '12:00 öğle arası' );
		$this->assertFalse( SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) ), 'lead' );
		$this->assertFalse( SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-12-01T07:00:00Z' ) ), 'horizon' );
	}

	public function test_offset_timezone_equals_named(): void {
		ExceptionRepository::add(
			array(
				'date'      => '2026-11-18',
				'type'      => 'closed',
				'from_time' => '14:00',
				'to_time'   => '16:00',
			)
		);
		$p     = $this->product( 30 );
		$named = array(
			SlotCalculator::slots( $p, '2026-11-18' ),
			SlotCalculator::days( $p, '2026-11-16', '2026-12-02' ),
			SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ),
		);

		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 3 );
		$offset = array(
			SlotCalculator::slots( $p, '2026-11-18' ),
			SlotCalculator::days( $p, '2026-11-16', '2026-12-02' ),
			SlotCalculator::isBookable( $p, new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ),
		);
		$this->assertSame( $named, $offset );
		$this->assertNotEmpty( $offset[0] );
	}

	public function test_label_day_boundary(): void {
		ExceptionRepository::add(
			array(
				'date'      => '2026-11-18',
				'type'      => 'open',
				'from_time' => '00:00',
				'to_time'   => '03:00',
			)
		);
		$p     = $this->product( 30 );
		$slots = SlotCalculator::slots( $p, '2026-11-18' );
		$found = array_values( array_filter( $slots, static fn( array $s ): bool => '01:30' === $s['label'] ) );
		$this->assertCount( 1, $found );
		$this->assertSame( '2026-11-17T22:30:00Z', $found[0]['start'] );

		$days = SlotCalculator::days( $p, '2026-11-17', '2026-11-18' );
		$this->assertSame( 12, $days[0]['free'], '17 Kasım yalnız kendi yerel dilimlerini sayar' );
		$this->assertSame( count( $slots ), $days[1]['free'] );
		$this->assertSame( 18, $days[1]['free'] );
	}

	public function test_span_crossing_local_midnight(): void {
		Rules::save( array( 'bufferMin' => 15 ) );
		foreach ( array(
			array( '2026-11-18', '22:00', '24:00' ),
			array( '2026-11-19', '00:00', '02:00' ),
		) as $e ) {
			ExceptionRepository::add(
				array(
					'date'      => $e[0],
					'type'      => 'open',
					'from_time' => $e[1],
					'to_time'   => $e[2],
				)
			);
		}
		$p = $this->product( 60 );

		// 23:30 yerel = 20:30Z; 60+15 = 5 birim (23:30–00:45).
		$start = new \DateTimeImmutable( '2026-11-18T20:30:00Z' );
		$units = Units::span( $start, 60, 15 );
		$this->assertCount( 5, $units );
		$tz    = wp_timezone();
		$dates = array_values(
			array_unique(
				array_map( static fn( \DateTimeImmutable $u ): string => $u->setTimezone( $tz )->format( 'Y-m-d' ), $units )
			)
		);
		$this->assertSame( array( '2026-11-18', '2026-11-19' ), $dates );

		$this->occupy( '2026-11-18T20:30:00Z', 60, 15 );
		$half = $this->product( 30 );
		$next = $this->labels( $half, '2026-11-19' );
		$this->assertNotContains( '00:00', $next );
		$this->assertNotContains( '00:30', $next );
		$this->assertContains( '01:00', $next );
		$this->assertNotNull( $p );
	}
}
