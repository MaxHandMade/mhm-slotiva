<?php
/**
 * Bildirim e-postaları (spec §7, §15.3, §15.11; kare 4): onay + yönetici "yeni randevu" sipariş başına bir kez, toplu;
 * iptal/havale süresi dolumu → müşteri iptal; supersede ve kart tutma dolumu sessiz; çakışma → yönetici; saat değişimi →
 * müşteri (yeni `.ics`).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Notify;

use MHM\Slotiva\Lifecycle\PaymentListener;
use MHM\Slotiva\Notify\Emails;
use MHM\Slotiva\Reservation\EventLog;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\MailFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;
use MHM\Slotiva\Tests\Support\TestCardGateway;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.

class EmailsTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;
	use MailFixtures;

	private const ADMIN = 'admin@example.org';

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		$this->set_up_lifecycle();
		$this->set_up_mail();
		update_option( 'admin_email', self::ADMIN );
	}

	public function tear_down(): void {
		$this->tear_down_mail();
		$this->tear_down_lifecycle();
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * Tutulan satırı ödeme gibi onaylar.
	 *
	 * @param int $id Rezervasyon.
	 */
	private function confirm( int $id ): void {
		$this->assertTrue(
			ReservationRepository::setStatus(
				$id,
				'confirmed',
				'paid',
				array(
					'expect_status' => array( 'held' ),
					'reason'        => 'paid',
				)
			)
		);
	}

	/**
	 * Siparişsiz (yönetici elle) onaylı randevu.
	 *
	 * @param bool   $notify Müşteriye e-posta.
	 * @param string $slot   Dilim.
	 */
	private function manual( bool $notify, string $slot = '2026-11-17T07:00:00Z' ): int {
		return ReservationRepository::claim(
			array(
				'product_id'    => $this->bookable(),
				'product_name'  => 'Danışmanlık <b>özel</b>',
				'slots'         => array( new \DateTimeImmutable( $slot ) ),
				'duration'      => 30,
				'status'        => 'confirmed',
				'payment_state' => 'manual',
				'customer'      => array(
					'name'  => 'Ali <script>alert(1)</script>',
					'email' => 'ali@example.test',
					'phone' => '05550000000',
				),
				'location'      => 'Atölye & Moda',
				'notify'        => $notify,
			)
		)[0];
	}

	/**
	 * Satır içi stil sonrası HTML'deki bağlantı biçimi (`&` → `&amp;`).
	 *
	 * @param string $url Bağlantı.
	 */
	private static function html_url( string $url ): string {
		return str_replace( '&', '&amp;', $url );
	}

	public function test_email_classes_registered_with_overridable_templates(): void {
		$emails = WC()->mailer()->get_emails();
		foreach ( Emails::CLASSES as $key => $class ) {
			$this->assertArrayHasKey( $key, $emails );
			$this->assertInstanceOf( $class, $emails[ $key ] );
			$this->assertInstanceOf( \WC_Email::class, $emails[ $key ] );
			$this->assertSame( trailingslashit( SLOTIVA_DIR ) . 'templates/', $emails[ $key ]->template_base );
			$this->assertStringStartsWith( 'emails/slotiva-', (string) $emails[ $key ]->template_html );
			$this->assertStringStartsWith( 'emails/plain/slotiva-', (string) $emails[ $key ]->template_plain );
			$this->assertFileExists( $emails[ $key ]->template_base . $emails[ $key ]->template_html );
			$this->assertFileExists( $emails[ $key ]->template_base . $emails[ $key ]->template_plain );
		}
	}

	public function test_confirmed_sends_customer_and_admin_once(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID );
		$this->assertSame( array(), $this->sent, 'tutmada e-posta yok' );
		$this->confirm( $ids[0] );
		// İkinci tetik (yinelenen ödeme bildirimi / olay).
		do_action(
			'slotiva_reservation_status',
			$ids[0],
			'held',
			'confirmed',
			array(
				'notify'   => true,
				'reason'   => 'paid',
				'order_id' => $oid,
			)
		);
		PaymentListener::onPaid( $oid );

		$customer = $this->sent_of( 'slotiva_customer_confirmed' );
		$admin    = $this->sent_of( 'slotiva_admin_new' );
		$this->assertCount( 1, $customer, 'müşteri onayı bir kez' );
		$this->assertCount( 1, $admin, 'yönetici yeni randevu bir kez' );
		$this->assertSame( 'ayse@example.test', $customer[0]['to'], 'anlık kopya boşsa sipariş e-postası' );
		$this->assertSame( self::ADMIN, $admin[0]['to'] );
		$this->assertSame( array( $ids[0] ), array_map( 'intval', (array) $this->order( $oid )->get_meta( Emails::META_MAILED ) ) );

		// Kare 4 içeriği: tarih, saat aralığı, saat dilimi adı, yer, sipariş bağlantısı.
		$body = $customer[0]['body'];
		$this->assertStringContainsString( '10:00–10:30', $body );
		$this->assertStringContainsString( 'Europe/Istanbul', $body );
		$this->assertStringContainsString( 'GMT+3', $body );
		$this->assertStringContainsString( 'November 17, 2026', $body );
		$this->assertStringContainsString( self::html_url( $this->order( $oid )->get_checkout_order_received_url() ), $body );
		$this->assertStringContainsString( self::html_url( $this->order( $oid )->get_edit_order_url() ), $admin[0]['body'], 'yönetici: sipariş yönetimde aç' );

		$this->assertCount( 1, $customer[0]['ics'] );
		$ics = (string) reset( $customer[0]['ics'] );
		$this->assertStringContainsString( "METHOD:REQUEST\r\n", $ics );
		$this->assertStringContainsString( 'UID:' . $this->row( $ids[0] )->token . '@', $ics );
		$this->assertStringEndsWith( '.ics', (string) array_key_first( $customer[0]['ics'] ) );
		$this->assertCount( 1, $admin[0]['ics'], 'kare 4: yöneticiye de takvim dosyası' );

		$mock = tests_retrieve_phpmailer_instance();
		$this->assertNotFalse( $mock );
		$this->assertStringContainsString( 'text/calendar', (string) end( $mock->mock_sent )['body'], 'ek MIME türü' );
	}

	public function test_templates_escape_output(): void {
		$this->manual( true );
		$customer = $this->sent_of( 'slotiva_customer_confirmed' );
		$admin    = $this->sent_of( 'slotiva_admin_new' );
		$this->assertCount( 1, $customer );
		$this->assertStringNotContainsString( '<b>özel</b>', $customer[0]['body'] );
		$this->assertStringContainsString( '&lt;b&gt;özel&lt;/b&gt;', $customer[0]['body'] );
		$this->assertStringNotContainsString( '<script>', $admin[0]['body'] );
		$this->assertStringContainsString( 'Atölye &amp; Moda', $customer[0]['body'], 'kare 4: yer (anlık kopya)' );
	}

	public function test_plain_text_email(): void {
		WC()->mailer()->get_emails()['Slotiva_Customer_Confirmed']->email_type = 'plain';
		$this->manual( true );
		$customer = $this->sent_of( 'slotiva_customer_confirmed' );
		$this->assertCount( 1, $customer );
		$this->assertStringNotContainsString( '<table', $customer[0]['body'] );
		$this->assertStringContainsString( '10:00–10:30', $customer[0]['body'] );
		WC()->mailer()->get_emails()['Slotiva_Customer_Confirmed']->email_type = 'html';
	}

	public function test_cod_confirmed_sends_mails(): void {
		[ $oid, $ids ] = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_confirmed' ) );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_new' ) );
		$this->assertStringContainsString( 'UID:' . $this->row( $ids[0] )->token . '@', (string) reset( $this->sent_of( 'slotiva_customer_confirmed' )[0]['ics'] ) );
		$this->assertNotEmpty( $this->order( $oid )->get_meta( Emails::META_MAILED ) );
	}

	public function test_manual_create_mails_only_when_notify(): void {
		$silent = $this->manual( false );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_confirmed' ), 'notify=false → müşteriye yok' );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_new' ), 'yönetici yeni randevu her zaman' );
		$types = array_column( EventLog::for( $silent ), 'type' );
		$this->assertContains( 'mailed_confirmed', $types, 'bayrak olay tablosunda' );

		$this->sent = array();
		$this->manual( true, '2026-11-17T08:00:00Z' );
		$customer = $this->sent_of( 'slotiva_customer_confirmed' );
		$this->assertCount( 1, $customer );
		$this->assertSame( 'ali@example.test', $customer[0]['to'] );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_new' ) );

		// Aynı olay yeniden: bayrak tekrar göndermez.
		do_action(
			'slotiva_reservation_status',
			$silent,
			'',
			'confirmed',
			array(
				'notify'   => true,
				'reason'   => 'claim',
				'order_id' => null,
			)
		);
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_new' ) );
	}

	public function test_multi_slot_order_one_aggregate_mail(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ) );
		$this->confirm( $ids[0] );
		$this->assertSame( array(), $this->sent, 'bir dilim hâlâ tutuluyor: henüz e-posta yok' );
		$this->confirm( $ids[1] );

		$customer = $this->sent_of( 'slotiva_customer_confirmed' );
		$this->assertCount( 1, $customer, 'tek toplu müşteri e-postası' );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_new' ), 'tek toplu yönetici e-postası' );
		$this->assertStringContainsString( '10:00–10:30', $customer[0]['body'] );
		$this->assertStringContainsString( '10:30–11:00', $customer[0]['body'] );
		$this->assertCount( 2, $customer[0]['ics'], 'her rezervasyon ayrı .ics (§15.11)' );
		$uids = array();
		foreach ( $customer[0]['ics'] as $ics ) {
			preg_match( '/UID:([0-9a-f]{32})@/', $ics, $m );
			$uids[] = $m[1] ?? '';
		}
		sort( $uids );
		$want = array( $this->row( $ids[0] )->token, $this->row( $ids[1] )->token );
		sort( $want );
		$this->assertSame( $want, $uids );
		$mailed = array_map( 'intval', (array) $this->order( $oid )->get_meta( Emails::META_MAILED ) );
		sort( $mailed );
		$this->assertSame( $ids, $mailed );
	}

	public function test_paid_via_listener_sends_once_for_multi_slot(): void {
		[ $oid ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ) );
		$this->order( $oid )->update_status( 'processing' );
		$this->order( $oid )->payment_complete();
		PaymentListener::onPaid( $oid );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_confirmed' ) );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_new' ) );
	}

	public function test_order_cancel_sends_customer_cancelled_once_with_cancel_ics(): void {
		[ $oid, $ids ] = $this->booked_order( 'cod', 'confirmed', 'on_site', null, array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z' ) );
		$this->sent    = array();
		$this->order( $oid )->update_status( 'cancelled' );
		$cancelled = $this->sent_of( 'slotiva_customer_cancelled' );
		$this->assertCount( 1, $cancelled, 'sipariş iptali tek toplu e-posta' );
		$this->assertSame( 'ayse@example.test', $cancelled[0]['to'] );
		$this->assertCount( 2, $cancelled[0]['ics'] );
		foreach ( $cancelled[0]['ics'] as $ics ) {
			$this->assertStringContainsString( "METHOD:CANCEL\r\n", $ics );
			$this->assertStringContainsString( "SEQUENCE:1\r\n", $ics );
		}
		$this->assertSame( 'cancelled', $this->row( $ids[0] )->status );
		PaymentListener::onCancelled( $oid );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_cancelled' ), 'yinelenen iptal yeniden göndermez' );
	}

	public function test_bank_expired_sends_customer_cancelled(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'cancelled', $this->order( $oid )->get_status(), 'kurgu: havale siparişi iptal' );

		$cancelled = $this->sent_of( 'slotiva_customer_cancelled' );
		$this->assertCount( 1, $cancelled, 'bank_expired → müşteri iptal, sipariş iptali ikinci kez göndermez' );
		$this->assertSame( 'ayse@example.test', $cancelled[0]['to'] );
		$this->assertStringContainsString( "METHOD:CANCEL\r\n", (string) reset( $cancelled[0]['ics'] ) );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_confirmed' ) );
	}

	public function test_lazily_expired_bank_hold_sends_customer_cancelled(): void {
		[ $oid, $ids, $pid ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->rival( $pid ); // Tembel süre dolumu (iş koşmadı).
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_cancelled' ), 'havale son tarihi geçti: aynı iş olayı' );
	}

	public function test_card_hold_expired_sends_no_mail(): void {
		[ , $ids, $pid ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:46:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$this->rival( $pid, '2026-11-17T07:30:00Z' );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_cancelled' ) );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_confirmed' ) );

		// Kartın `on-hold` (PayTR yetkilendirme) tutması da sessiz düşer.
		[ $oid2, $ids2 ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-18T07:00:00Z' ) );
		$this->order( $oid2 )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:50:00Z' ) );
		$this->run_expiry( $this->row( $ids2[0] ) );
		$this->assertSame( 'expired', $this->row( $ids2[0] )->status );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_cancelled' ) );
	}

	public function test_cancel_then_reconfirm_ics_sequence_strictly_increases(): void {
		// I1: havale son tarihi → CANCEL (SEQUENCE n) → geç ödeme → reclaim → onay REQUEST aynı UID, SEQUENCE > n.
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$cancel = (string) reset( $this->sent_of( 'slotiva_customer_cancelled' )[0]['ics'] );
		$this->assertSame( 1, preg_match( '/\r\nSEQUENCE:(\d+)\r\n/', $cancel, $c ) );
		$this->assertSame( (int) $c[1], $this->row( $ids[0] )->sequence, 'iptal sürümü kalıcı' );

		$this->order( $oid )->update_status( 'processing' ); // Geç gelen havale elle onaylandı.
		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status, 'kurgu: saat boştu, yeniden alındı' );
		$confirmed = $this->sent_of( 'slotiva_customer_confirmed' );
		$this->assertCount( 1, $confirmed );
		$request = (string) reset( $confirmed[0]['ics'] );
		$this->assertStringContainsString( "METHOD:REQUEST\r\n", $request );
		$this->assertSame( 1, preg_match( '/\r\nSEQUENCE:(\d+)\r\n/', $request, $q ) );
		$this->assertGreaterThan( (int) $c[1], (int) $q[1], 'aynı UID için REQUEST, önceki CANCEL\'dan kesin büyük SEQUENCE' );
		$uid = 'UID:' . $this->row( $ids[0] )->token . '@';
		$this->assertStringContainsString( $uid, $cancel );
		$this->assertStringContainsString( $uid, $request );
	}

	public function test_second_cancel_after_reconfirm_gets_higher_sequence(): void {
		// Yeniden inceleme: CANCEL(n) → geç ödeme reclaim → REQUEST(>n) → yönetici iptali → ikinci CANCEL (> REQUEST).
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours' );
		$this->order( $oid )->update_status( 'on-hold' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->order( $oid )->update_status( 'processing' );
		$this->assertSame( 'confirmed', $this->row( $ids[0] )->status, 'kurgu: yeniden alındı' );
		$this->order( $oid )->update_status( 'cancelled' );
		$this->assertSame( 'cancelled', $this->row( $ids[0] )->status );

		$seq     = static function ( array $mail ): int {
			preg_match( '/\r\nSEQUENCE:(\d+)\r\n/', (string) reset( $mail['ics'] ), $m );
			return (int) ( $m[1] ?? -1 );
		};
		$cancels = $this->sent_of( 'slotiva_customer_cancelled' );
		$this->assertCount( 2, $cancels, 'her etkinlik döngüsü kendi CANCEL\'ını alır' );
		$request = $this->sent_of( 'slotiva_customer_confirmed' );
		$this->assertCount( 1, $request );
		$this->assertStringContainsString( "METHOD:CANCEL\r\n", (string) reset( $cancels[1]['ics'] ) );
		$this->assertGreaterThan( $seq( $cancels[0] ), $seq( $request[0] ) );
		$this->assertGreaterThan( $seq( $request[0] ), $seq( $cancels[1] ), 'ikinci CANCEL, REQUEST\'ten kesin büyük' );
		$this->assertSame( $seq( $cancels[1] ), $this->row( $ids[0] )->sequence, 'kalıcı' );

		// Aynı döngüde yinelenen olay: üçüncü CANCEL yok.
		do_action(
			'slotiva_reservation_status',
			$ids[0],
			'confirmed',
			'cancelled',
			array(
				'notify'   => true,
				'reason'   => 'order',
				'order_id' => $oid,
			)
		);
		PaymentListener::onCancelled( $oid );
		$this->assertCount( 2, $this->sent_of( 'slotiva_customer_cancelled' ) );
	}

	public function test_manual_without_notify_gets_no_customer_reminder_reschedule_or_cancel(): void {
		// I2: "e-posta gönderme" ile elle eklenen randevu: tercih alma olayında kalıcı; yalnız yönetici e-postaları gider.
		$id = $this->manual( false );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_new' ) );
		$this->assertSame( array(), $this->reminder_jobs( $id ), 'müşteri hatırlatması kurulmaz' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		\MHM\Slotiva\Notify\Reminders::run( $id, 0 ); // İş yine de koşsa.
		$this->assertTrue( ReservationRepository::reschedule( $id, new \DateTimeImmutable( '2026-11-17T08:30:00Z' ), true, true ) );
		$this->assertTrue( ReservationRepository::release( $id, 'cancelled' ) ); // Bağlam notify varsayılanı true.
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_reminder' ) );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_rescheduled' ) );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_cancelled' ) );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_confirmed' ) );
	}

	public function test_held_row_reschedule_sends_no_customer_mail(): void {
		// I3: onaylanmamış satıra REQUEST `.ics` gitmez (tutma sessizce dolarsa takvimde hayalet randevu kalırdı).
		[ , $ids ] = $this->booked_order( TestCardGateway::ID );
		$this->assertTrue( ReservationRepository::reschedule( $ids[0], new \DateTimeImmutable( '2026-11-17T08:00:00Z' ), true, true ) );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_rescheduled' ) );
		$this->assertSame( array(), $this->sent );
	}

	public function test_card_on_hold_lazy_expired_sends_no_cancel_mail(): void {
		// M6: kart `on-hold` (PayTR yetkilendirme) → `awaiting_transfer`; tembel dolum `lazy_expired`. Yalnız ödeme yöntemi
		// denetimi bunu havaleden ayırır.
		[ $oid, $ids, $pid ] = $this->booked_order( TestCardGateway::ID );
		$this->order( $oid )->update_status( 'on-hold' );
		$this->assertSame( 'awaiting_transfer', $this->row( $ids[0] )->paymentState, 'kurgu' );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T06:01:00Z' ) );
		$this->rival( $pid ); // Aynı dilim: tembel süre dolumu.
		$this->assertSame( 'expired', $this->row( $ids[0] )->status );
		$reasons = array_column( array_column( EventLog::for( $ids[0] ), 'data' ), 'reason' );
		$this->assertContains( 'lazy_expired', $reasons, 'kurgu: tembel yol' );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_cancelled' ) );
	}

	public function test_superseded_sends_no_mail(): void {
		$session   = md5( 'oturum-a' );
		[ , $a ]   = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z' ), $session );
		[ $b_oid ] = $this->booked_order( TestCardGateway::ID, 'held', 'pending', '+45 minutes', array( '2026-11-17T07:00:00Z' ), $session );
		$this->assertSame( 'superseded', $this->row( $a[0] )->status );
		ReservationRepository::replaceForOrder(
			$b_oid,
			array(
				'product_id' => $this->bookable(),
				'slots'      => array( new \DateTimeImmutable( '2026-11-17T08:00:00Z' ) ),
				'duration'   => 30,
				'status'     => 'held',
			)
		);
		$this->assertSame( array(), $this->sent, 'supersede ve yeniden gönderim sessiz' );
	}

	public function test_conflict_mail_to_admin(): void {
		[ $oid, $ids, $pid ] = $this->booked_order( TestCardGateway::ID );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:00:00Z' ) );
		$this->run_expiry( $this->row( $ids[0] ) );
		$this->rival( $pid );
		$this->sent = array();

		$this->order( $oid )->update_status( 'processing' );
		PaymentListener::onPaid( $oid ); // Yinelenen bildirim: yeni çakışma yok.

		$this->assertSame( 'conflict', $this->row( $ids[0] )->status );
		$conflict = $this->sent_of( 'slotiva_admin_conflict' );
		$this->assertCount( 1, $conflict );
		$this->assertSame( self::ADMIN, $conflict[0]['to'] );
		$this->assertStringContainsString( (string) $this->order( $oid )->get_order_number(), $conflict[0]['subject'] );
		$this->assertStringContainsString( '10:00–10:30', $conflict[0]['body'] );
		$this->assertStringContainsString( self::html_url( $this->order( $oid )->get_edit_order_url() ), $conflict[0]['body'] );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_confirmed' ), 'çakışmada müşteriye onay gitmez' );
	}

	public function test_rescheduled_mail_and_reminder_moved(): void {
		[ , $ids ] = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$this->assertSame( 0, $this->reminder_jobs( $ids[0] )[0]['args']['sequence'] );
		$this->sent = array();

		$this->assertTrue( ReservationRepository::reschedule( $ids[0], new \DateTimeImmutable( '2026-11-18T08:00:00Z' ), true, true ) );
		$mail = $this->sent_of( 'slotiva_customer_rescheduled' );
		$this->assertCount( 1, $mail );
		$this->assertSame( 'ayse@example.test', $mail[0]['to'] );
		$this->assertStringContainsString( '11:00–11:30', $mail[0]['body'] );
		$ics = (string) reset( $mail[0]['ics'] );
		$this->assertStringContainsString( "METHOD:REQUEST\r\n", $ics );
		$this->assertStringContainsString( "SEQUENCE:1\r\n", $ics );
		$this->assertStringContainsString( "DTSTART:20261118T080000Z\r\n", $ics );

		$jobs = $this->reminder_jobs( $ids[0] );
		$this->assertCount( 1, $jobs, 'eski hatırlatma kaldırıldı, yenisi kuruldu' );
		$this->assertSame( 1, $jobs[0]['args']['sequence'] );
		$this->assertSame( ( new \DateTimeImmutable( '2026-11-17T08:00:00Z' ) )->getTimestamp(), $jobs[0]['ts'] );

		// notify=false: e-posta yok, hatırlatma yine taşınır.
		$this->assertTrue( ReservationRepository::reschedule( $ids[0], new \DateTimeImmutable( '2026-11-18T08:30:00Z' ), false, false ) );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_rescheduled' ) );
		$this->assertSame( 2, $this->reminder_jobs( $ids[0] )[0]['args']['sequence'] );
	}

	/**
	 * (Final review F6 / T11 M5) Tam iade çok dilimli siparişte dilim başına değil, sipariş başına TEK iptal e-postası
	 * gönderir (her rezervasyon kendi CANCEL `.ics`'iyle); iade tek işlemde yazılır.
	 */
	public function test_full_refund_sends_one_cancel_mail_per_order(): void {
		[ $oid, $ids ] = $this->booked_order( TestCardGateway::ID, 'confirmed', 'paid', null, array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z', '2026-11-17T08:00:00Z' ) );
		$this->sent    = array();

		PaymentListener::onFullyRefunded( $oid );

		$cancelled = $this->sent_of( 'slotiva_customer_cancelled' );
		$this->assertCount( 1, $cancelled, 'tek toplu iptal e-postası' );
		$this->assertCount( 3, $cancelled[0]['ics'] );
		foreach ( $ids as $id ) {
			$this->assertSame( 'cancelled', $this->row( $id )->status );
			$this->assertSame( 'refunded', $this->row( $id )->paymentState );
		}
		PaymentListener::onFullyRefunded( $oid );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_cancelled' ), 'yinelenen iade yeniden göndermez' );
	}

	/**
	 * (Final review F14) Bildirimli iptalde de sequence iptal başına YALNIZ bir artar (iptal işleminde; e-posta ikinci
	 * kez artırmaz) ve CANCEL `.ics` o sürümü taşır.
	 */
	public function test_notified_cancel_bumps_sequence_once(): void {
		[ , $ids ]  = $this->booked_order( 'cod', 'confirmed', 'on_site', null );
		$this->sent = array();
		$before     = $this->row( $ids[0] )->sequence;
		$this->assertTrue( ReservationRepository::release( $ids[0], 'cancelled', array( 'reason' => 'admin' ) ) );
		$this->assertSame( $before + 1, $this->row( $ids[0] )->sequence );
		$ics = (string) reset( $this->sent_of( 'slotiva_customer_cancelled' )[0]['ics'] );
		$this->assertStringContainsString( "\r\nSEQUENCE:" . ( $before + 1 ) . "\r\n", $ics );
	}
}
