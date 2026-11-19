<?php
/**
 * Silinmiş kişiye e-posta gitmez: hatırlatma kaldırılır, müşteri e-postası fatura adresine düşmez.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Privacy;

use MHM\Slotiva\Notify\Emails;
use MHM\Slotiva\Notify\Reminders;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\MailFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;
use MHM\Slotiva\Tests\Support\TestCardGateway;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce kancası.

class AnonymisedMailTest extends SlotivaTestCase {

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

	public function test_erased_future_booking_gets_no_reminder_or_customer_mail(): void {
		[ $order_id, $ids ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-18T07:00:00Z' ) );
		$ctx                = array(
			'expect_status' => array( 'held' ),
			'reason'        => 'paid',
		);
		$this->assertTrue( ReservationRepository::setStatus( $ids[0], 'confirmed', 'paid', $ctx ) );
		$this->assertCount( 1, $this->reminder_jobs( $ids[0] ), 'kurgu: hatırlatma kurulu' );

		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'ayse@example.test', $order->get_billing_email(), 'kurgu: fatura e-postası dolu (WC silmedi)' );
		do_action( 'woocommerce_privacy_remove_order_personal_data', $order );

		$this->assertTrue( ReservationRepository::isAnonymised( $ids[0] ) );
		$this->assertFalse( ReservationRepository::customerNotify( $ids[0] ) );
		$this->assertSame( array(), $this->reminder_jobs( $ids[0] ), 'hatırlatma işi kaldırıldı' );

		Reminders::run( $ids[0], 0 );
		$this->assertSame( array(), $this->sent_of( 'slotiva_customer_reminder' ) );

		$r = ReservationRepository::find( $ids[0] );
		$this->assertNotNull( $r );
		Emails::send( 'Slotiva_Customer_Cancelled', array( $r ), $order );
		$this->assertSame( array(), $this->sent_of( 'slotiva_customer_cancelled' ), 'fatura e-postasına düşmez' );
	}
}
