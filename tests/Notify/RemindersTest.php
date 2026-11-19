<?php
/**
 * Hatırlatma (spec §7, §15.7): onayda `starts_at − reminderHours` işi (grup `slotiva`, argüman `reservation_id` + beklenen
 * `sequence`); iptal/süre dolumunda kaldırılır; saat değişince yeniden kurulur; işleyici satır kilidi altında yeniden doğrular,
 * "gönderildi" işareti olay tablosunda; randevuya N saatten az kala onaylanırsa kurulmaz.
 *
 * İşleyici doğrudan çağrılır: `Clock::freeze` Action Scheduler'ın saatini değiştirmez.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Notify;

use MHM\Slotiva\Notify\Reminders;
use MHM\Slotiva\Reservation\EventLog;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\MailFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;
use MHM\Slotiva\Tests\Support\TestCardGateway;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.

class RemindersTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;
	use MailFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		$this->set_up_lifecycle();
		$this->set_up_mail();
	}

	public function tear_down(): void {
		$this->tear_down_mail();
		$this->tear_down_lifecycle();
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * Kart siparişi onaylı satır.
	 *
	 * @param string $slot Dilim.
	 */
	private function confirmed_row( string $slot = '2026-11-17T07:00:00Z' ): int {
		[ , $ids ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( $slot ) );
		$this->assertTrue(
			ReservationRepository::setStatus(
				$ids[0],
				'confirmed',
				'paid',
				array(
					'expect_status' => array( 'held' ),
					'reason'        => 'paid',
				)
			)
		);
		return $ids[0];
	}

	/**
	 * İşleyiciyi bekleyen işin argümanlarıyla çağırır.
	 *
	 * @param array<string,mixed> $args Argümanlar.
	 */
	private function run_reminder( array $args ): void {
		Reminders::run( $args['reservation_id'], $args['sequence'] );
	}

	public function test_reminder_scheduled_and_unscheduled(): void {
		[ , $held ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-18T07:00:00Z' ) );
		$this->assertSame( array(), $this->reminder_jobs( $held[0] ), 'tutulan satıra hatırlatma kurulmaz' );

		$id   = $this->confirmed_row();
		$jobs = $this->reminder_jobs( $id );
		$this->assertCount( 1, $jobs );
		$this->assertSame(
			array(
				'reservation_id' => $id,
				'sequence'       => 0,
			),
			$jobs[0]['args']
		);
		$this->assertSame( ( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) )->getTimestamp(), $jobs[0]['ts'], 'starts_at − 24 sa' );

		// Yinelenen onay olayı ikinci iş kurmaz.
		do_action(
			'slotiva_reservation_status',
			$id,
			'held',
			'confirmed',
			array(
				'notify'   => true,
				'reason'   => 'paid',
				'order_id' => null,
			)
		);
		$this->assertCount( 1, $this->reminder_jobs( $id ) );

		$this->assertTrue( ReservationRepository::release( $id, 'cancelled' ) );
		$this->assertSame( array(), $this->reminder_jobs( $id ), 'iptalde kaldırıldı' );
	}

	public function test_reminder_unscheduled_on_expired_and_completed(): void {
		$a = $this->confirmed_row();
		$this->assertTrue( ReservationRepository::release( $a, 'expired' ) );
		$this->assertSame( array(), $this->reminder_jobs( $a ) );

		$b = $this->confirmed_row( '2026-11-18T07:00:00Z' );
		$this->assertTrue( ReservationRepository::setStatus( $b, 'completed' ) );
		$this->assertSame( array(), $this->reminder_jobs( $b ), 'confirmed dışı her durumda kaldırılır' );
	}

	public function test_reminder_not_scheduled_when_confirmed_too_close(): void {
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:30:00Z' ) ); // Randevuya 23,5 sa.
		$id = $this->confirmed_row();
		$this->assertSame( array(), $this->reminder_jobs( $id ), 'N saatten az kala onay → hatırlatma yok (§15.7)' );
	}

	public function test_reminder_not_scheduled_when_disabled(): void {
		Rules::save( array( 'reminderOn' => false ) );
		$id = $this->confirmed_row();
		$this->assertSame( array(), $this->reminder_jobs( $id ) );
	}

	public function test_reminder_sends_once(): void {
		$id   = $this->confirmed_row();
		$args = $this->reminder_jobs( $id )[0]['args'];
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->sent = array();
		$this->run_reminder( $args );
		$this->run_reminder( $args ); // Yinelenen iş (AS yeniden deneme).

		$mail = $this->sent_of( 'slotiva_customer_reminder' );
		$this->assertCount( 1, $mail, 'bir kez' );
		$this->assertSame( 'ayse@example.test', $mail[0]['to'] );
		$this->assertStringContainsString( '10:00–10:30', $mail[0]['body'] );
		$sent = array_values( array_filter( EventLog::for( $id ), static fn( array $e ): bool => 'reminder_sent' === $e['type'] ) );
		$this->assertCount( 1, $sent, 'gönderildi işareti olay tablosunda' );
		$this->assertSame( 0, $sent[0]['data']['sequence'] );
	}

	public function test_reminder_skips_cancelled(): void {
		$id   = $this->confirmed_row();
		$args = $this->reminder_jobs( $id )[0]['args'];
		$this->assertTrue( ReservationRepository::release( $id, 'cancelled', array( 'notify' => false ) ) );
		$this->sent = array();
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_reminder( $args ); // İş kaldırılmış olsa da koşmuş olabilir (yarış): işleyici yeniden doğrular.
		$this->assertSame( array(), $this->sent_of( 'slotiva_customer_reminder' ) );
		$this->assertNotContains( 'reminder_sent', array_column( EventLog::for( $id ), 'type' ) );
	}

	public function test_stale_reminder_job_noop_after_reschedule(): void {
		$id    = $this->confirmed_row();
		$stale = $this->reminder_jobs( $id )[0]['args'];
		$this->assertTrue( ReservationRepository::reschedule( $id, new \DateTimeImmutable( '2026-11-17T08:00:00Z' ), true, false ) );
		$fresh = $this->reminder_jobs( $id );
		$this->assertCount( 1, $fresh, 'bayat iş kaldırıldı' );
		$this->assertSame( 1, $fresh[0]['args']['sequence'] );
		$this->assertSame( ( new \DateTimeImmutable( '2026-11-16T08:00:00Z' ) )->getTimestamp(), $fresh[0]['ts'] );

		Clock::freeze( new \DateTimeImmutable( '2026-11-16T08:00:00Z' ) );
		$this->sent = array();
		$this->run_reminder( $stale ); // Kaldırılmadan önce koşmaya başlamış bayat iş.
		$this->assertSame( array(), $this->sent_of( 'slotiva_customer_reminder' ), 'beklenen sequence tutmuyor → no-op' );
		$this->run_reminder( $fresh[0]['args'] );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_reminder' ) );
		$this->assertStringContainsString( '11:00–11:30', $this->sent_of( 'slotiva_customer_reminder' )[0]['body'] );
	}

	public function test_reminder_after_start_is_noop(): void {
		$id   = $this->confirmed_row();
		$args = $this->reminder_jobs( $id )[0]['args'];
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T07:05:00Z' ) ); // İş çok geç koştu.
		$this->sent = array();
		$this->run_reminder( $args );
		$this->assertSame( array(), $this->sent_of( 'slotiva_customer_reminder' ) );
	}
}
