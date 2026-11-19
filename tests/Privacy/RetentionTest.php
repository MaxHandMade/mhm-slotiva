<?php
/**
 * Saklama işi (spec Kişisel veri): yalnız süresi dolmuş satırlar anonimleşir; günlük iş bir kez kurulur.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Privacy;

use MHM\Slotiva\Privacy\Retention;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\PrivacyFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class RetentionTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use PrivacyFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		Rules::save( array( 'retentionMonths' => 24 ) );
	}

	public function tear_down(): void {
		$this->tear_down_checkout();
		parent::tear_down();
	}

	public function test_retention_anonymises_old_only(): void {
		// Donuk saat 2026-11-16; 24 ay eşiği = 2024-11-16. Eski: 2024-10-01; yeni: 2024-12-01 (23 ay önce).
		$old    = $this->make_bookings( 1, 'eski@example.test', '2024-10-01T07:00:00Z', 'Eski Kişi' );
		$recent = $this->make_bookings( 1, 'yeni@example.test', '2024-12-01T07:00:00Z', 'Yeni Kişi' );
		$future = $this->make_bookings( 1, 'gelecek@example.test', '2026-12-10T07:00:00Z', 'Gelecek Kişi' );

		$this->assertSame( 1, Retention::run() );

		$row = $this->raw_row( $old[0] );
		$this->assertSame( '', $row['customer_name'] );
		$this->assertSame( '', $row['customer_email'] );
		$this->assertSame( 'confirmed', $row['status'], 'satır ve durum kalır' );
		$this->assertSame( 'Yeni Kişi', $this->raw_row( $recent[0] )['customer_name'] );
		$this->assertSame( 'Gelecek Kişi', $this->raw_row( $future[0] )['customer_name'] );
		$this->assertStringNotContainsString( 'eski@example.test', $this->all_event_text() );
		$this->assertStringContainsString( 'yeni@example.test', $this->all_event_text() );

		$this->assertSame( 0, Retention::run(), 'ikinci koşu yapacak bir şey bulmaz' );
	}

	public function test_retention_uses_configured_months(): void {
		$recent = $this->make_bookings( 1, 'yeni@example.test', '2024-12-01T07:00:00Z', 'Yeni Kişi' );
		Rules::save( array( 'retentionMonths' => 12 ) );

		$this->assertSame( 1, Retention::run() );
		$this->assertSame( '', $this->raw_row( $recent[0] )['customer_name'] );
	}

	public function test_retention_clears_every_old_row_across_batches(): void {
		$ids = $this->make_bookings( 230, 'cok@example.test', '2024-01-01T07:00:00Z' );

		$this->assertSame( 230, Retention::run() );
		foreach ( $ids as $id ) {
			$this->assertSame( '', $this->raw_row( $id )['customer_email'] );
		}
	}

	/**
	 * Codex pre-ZIP: devam işi KOŞARKEN (RUNNING) kendi zincirini sürdürebilmeli; `as_has_scheduled_action` koşan işi de sayıp
	 * sonraki işi kurmuyordu ve saklama işi yarıda kalıyordu.
	 */
	public function test_continuation_job_schedules_the_next_one_while_it_is_running(): void {
		// Bir koşu en çok 20 tur x 100 = 2000 satır alır; 2001 eski satır sonraki bir koşu gerektirir.
		$ids = $this->make_bookings( 2001, 'cok@example.test', '2023-01-01T07:00:00Z' );

		$running = as_schedule_single_action( time() + 300, Retention::HOOK_MORE, array(), 'slotiva' );
		\ActionScheduler::store()->log_execution( $running ); // Devam işi şu an koşuyor (RUNNING).
		$this->assertSame( 2000, Retention::run() );

		$pending = as_get_scheduled_actions(
			array(
				'hook'   => Retention::HOOK_MORE,
				'group'  => 'slotiva',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( 1, $pending, 'koşan işin yanında yeni bir bekleyen devam işi kuruldu' );
		$this->assertNotContains( $running, array_map( 'intval', $pending ) );

		// Aynı anda ikinci bekleyen kurulmaz.
		$this->assertSame( 1, Retention::run() );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => Retention::HOOK_MORE,
					'group'  => 'slotiva',
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			)
		);
		$this->assertSame( '', $this->raw_row( $ids[2000] )['customer_email'] );
	}

	public function test_missing_retention_setting_falls_back_to_24_months(): void {
		delete_option( 'slotiva_rules' );
		$old    = $this->make_bookings( 1, 'eski@example.test', '2024-10-01T07:00:00Z', 'Eski Kişi' );
		$recent = $this->make_bookings( 1, 'yeni@example.test', '2024-12-01T07:00:00Z', 'Yeni Kişi' );

		$this->assertSame( 1, Retention::run() );
		$this->assertSame( '', $this->raw_row( $old[0] )['customer_name'] );
		$this->assertSame( 'Yeni Kişi', $this->raw_row( $recent[0] )['customer_name'] );
	}

	public function test_daily_job_scheduled_once(): void {
		delete_option( 'slotiva_retention_checked' );
		Retention::ensureRecurring();
		delete_option( 'slotiva_retention_checked' );
		Retention::ensureRecurring();

		$jobs = as_get_scheduled_actions(
			array(
				'hook'   => Retention::HOOK,
				'group'  => 'slotiva',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$this->assertCount( 1, $jobs );
		$this->assertTrue( has_action( Retention::HOOK ) );
	}
}
