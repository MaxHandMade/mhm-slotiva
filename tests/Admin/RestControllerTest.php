<?php
/**
 * Yönetim REST API testleri (`slotiva/v1/admin`, spec §8, §15.10). Mantık `rest_do_request` ile; çerez/nonce davranışı
 * gerçek HTTP ile ayrıca (`tests/e2e/admin-rest-auth.sh`).
 *
 * Takvim (CheckoutFixtures): donuk saat Pzt 2026-11-16 09:00 İstanbul (06:00Z); hafta içi 10:00–12:00; ön süre 24 sa.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Admin;

use MHM\Slotiva\Admin\RestController;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Lifecycle\PaymentListener;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\MailFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.DB.RestrictedFunctions -- bağımsız ikinci bağlantı (mysqli) yalnız kilit testi için.

class RestControllerTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;
	use MailFixtures;

	private const NS = '/slotiva/v1/admin';

	/** Bugün 10:00 yerel. */
	private const TODAY_10 = '2026-11-16T07:00:00Z';

	/** Yarın 10:00 yerel. */
	private const TOMORROW_10 = '2026-11-17T07:00:00Z';

	private int $admin = 0;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		$this->set_up_lifecycle();
		$this->set_up_mail();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->tear_down_mail();
		$this->tear_down_lifecycle();
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * Yönetim isteği.
	 *
	 * @param string              $method Yöntem.
	 * @param string              $path   NS sonrası yol.
	 * @param array<string,mixed> $params Sorgu (GET/DELETE) ya da JSON gövde.
	 */
	private function call( string $method, string $path, array $params = array() ): \WP_REST_Response {
		$r = new \WP_REST_Request( $method, self::NS . $path );
		if ( in_array( $method, array( 'GET', 'DELETE' ), true ) ) {
			$r->set_query_params( $params );
		} elseif ( $params ) {
			$r->set_header( 'Content-Type', 'application/json' );
			$r->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_do_request( $r );
	}

	/**
	 * Yanıt verisi dizi olarak.
	 *
	 * @param \WP_REST_Response $res Yanıt.
	 * @return array<string,mixed>
	 */
	private static function data( \WP_REST_Response $res ): array {
		return (array) json_decode( (string) wp_json_encode( $res->get_data() ), true );
	}

	/**
	 * Saat değiştirme gövdesi.
	 *
	 * @param string $start  Yeni başlangıç (UTC ISO).
	 * @param int    $expect Beklenen sequence.
	 * @return array<string,mixed>
	 */
	private static function move( string $start, int $expect ): array {
		return array(
			'start'           => $start,
			'expect_sequence' => $expect,
		);
	}

	/**
	 * Elle randevu isteği.
	 *
	 * @param int    $pid    Ürün.
	 * @param string $slot   Dilim (UTC ISO).
	 * @param bool   $notify Bildirim.
	 */
	private function manual( int $pid, string $slot, bool $notify = true ): \WP_REST_Response {
		return $this->call(
			'POST',
			'/appointments',
			array(
				'product_id' => $pid,
				'slot'       => $slot,
				'customer'   => array(
					'name'  => 'Çağrı Şahin',
					'phone' => '05551112233',
					'email' => 'cagri@example.test',
				),
				'notify'     => $notify,
			)
		);
	}

	/**
	 * Elle randevu oluşturur, kimliğini döndürür.
	 *
	 * @param int    $pid  Ürün.
	 * @param string $slot Dilim.
	 */
	private function manual_id( int $pid, string $slot ): int {
		$res = $this->manual( $pid, $slot );
		$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		return (int) self::data( $res )['id'];
	}

	/**
	 * Rezervasyonun birim sayısı.
	 *
	 * @param int $id Rezervasyon.
	 */
	private static function units_of( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'occupancy' ) . ' WHERE reservation_id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test; tablo adı beyaz listeden.
	}

	/**
	 * Her uç (yöntem, yol, parametre).
	 *
	 * @param int $pid Ürün.
	 * @param int $id  Rezervasyon.
	 * @return list<array{0:string,1:string,2:array<string,mixed>}>
	 */
	private function all_routes( int $pid, int $id ): array {
		return array(
			array( 'GET', '/appointments', array() ),
			array( 'GET', '/appointments/' . $id, array() ),
			array(
				'POST',
				'/appointments',
				array(
					'product_id' => $pid,
					'slot'       => self::TOMORROW_10,
					'customer'   => array( 'name' => 'X' ),
				),
			),
			array(
				'POST',
				'/appointments/' . $id . '/reschedule',
				array(
					'start'           => self::TOMORROW_10,
					'expect_sequence' => 0,
				),
			),
			array( 'POST', '/appointments/' . $id . '/status', array( 'status' => 'cancelled' ) ),
			array( 'POST', '/appointments/' . $id . '/extend-hold', array( 'hours' => 24 ) ),
			array(
				'GET',
				'/availability',
				array(
					'product' => $pid,
					'day'     => '2026-11-17',
				),
			),
			array( 'GET', '/schedule', array() ),
			array( 'PUT', '/schedule', array( 'days' => array_fill( 0, 7, array() ) ) ),
			array( 'GET', '/rules', array() ),
			array( 'PUT', '/rules', array( 'leadHours' => 2 ) ),
			array( 'GET', '/exceptions', array() ),
			array(
				'POST',
				'/exceptions',
				array(
					'date' => '2026-11-20',
					'type' => 'closed',
				),
			),
			array( 'DELETE', '/exceptions/1', array() ),
			array( 'GET', '/export.csv', array( '_wpnonce' => wp_create_nonce( 'wp_rest' ) ) ),
		);
	}

	public function test_requires_manage_woocommerce(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TODAY_10 );
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$before = (string) wp_json_encode( ReservationRepository::find( $id ) );
		foreach ( $this->all_routes( $pid, $id ) as [ $method, $path, $params ] ) {
			$res = $this->call( $method, $path, $params );
			$this->assertSame( 403, $res->get_status(), "$method $path" );
		}
		wp_set_current_user( 0 );
		foreach ( $this->all_routes( $pid, $id ) as [ $method, $path, $params ] ) {
			$res = $this->call( $method, $path, $params );
			$this->assertSame( 401, $res->get_status(), "anon $method $path" );
		}
		$this->assertSame( $before, (string) wp_json_encode( ReservationRepository::find( $id ) ), 'yetkisiz istek hiçbir şey değiştirmedi' );
		$this->assertSame( 1, self::rows( 'reservations' ) );

		// Mağaza yöneticisi (manage_woocommerce) erişir.
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $manager );
		$this->assertSame( 200, $this->call( 'GET', '/appointments' )->get_status() );
	}

	public function test_routes_registered_under_admin_namespace(): void {
		$routes = rest_get_server()->get_routes( 'slotiva/v1' );
		foreach ( array( '/appointments', '/appointments/(?P<id>\d+)', '/appointments/(?P<id>\d+)/reschedule', '/appointments/(?P<id>\d+)/status', '/appointments/(?P<id>\d+)/extend-hold', '/availability', '/schedule', '/rules', '/exceptions', '/exceptions/(?P<id>\d+)', '/export\.csv' ) as $p ) {
			$this->assertArrayHasKey( '/slotiva/v1/admin' . $p, $routes, $p );
		}
	}

	/**
	 * Senaryo 15 (gece yarısı, gösterim): Türkiye saatiyle 18 Kasım 01:30 randevusu UTC'de ÖNCEKİ gün (17 Kasım 22:30Z).
	 * Yönetici listesi/ayrıntısı, CSV, müşteri ve yönetici e-postası yerel günü (18 Kasım, 01:30) gösterir; .ics UTC'dir.
	 */
	public function test_midnight_local_day_in_list_csv_email_and_ics(): void {
		\MHM\Slotiva\Schedule\ExceptionRepository::add(
			array(
				'date'      => '2026-11-18',
				'type'      => 'open',
				'from_time' => '00:00',
				'to_time'   => '03:00',
			)
		);
		$pid = $this->bookable();
		$res = $this->call(
			'POST',
			'/appointments',
			array(
				'product_id' => $pid,
				'slot'       => '2026-11-17T22:30:00Z',
				'customer'   => array(
					'name'  => 'Gece Testi',
					'email' => 'gece@example.test',
				),
				'notify'     => true,
			)
		);
		$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$id = (int) self::data( $res )['id'];

		$item = self::data( $this->call( 'GET', '/appointments/' . $id ) );
		$this->assertSame( '2026-11-18', $item['local_date'] );
		$this->assertSame( '01:30', $item['local_time'] );
		$list = self::data( $this->call( 'GET', '/appointments', array( 'tab' => 'upcoming' ) ) );
		$this->assertSame( '2026-11-18', $list['items'][0]['local_date'] );

		$this->assertStringContainsString( "\r\n2026-11-18;01:30;", \MHM\Slotiva\Admin\Csv::build( array() ) );

		$date = (string) wp_date( (string) get_option( 'date_format' ), strtotime( '2026-11-18 12:00:00 +03:00' ) );
		$prev = (string) wp_date( (string) get_option( 'date_format' ), strtotime( '2026-11-17 12:00:00 +03:00' ) );
		foreach ( array( 'slotiva_customer_confirmed', 'slotiva_admin_new' ) as $mail ) {
			$sent = $this->sent_of( $mail );
			$this->assertCount( 1, $sent, $mail );
			$this->assertStringContainsString( $date, $sent[0]['body'], "{$mail}: yerel gün" );
			$this->assertStringNotContainsString( $prev, $sent[0]['body'], "{$mail}: UTC günü sızmaz" );
			$this->assertStringContainsString( '01:30', $sent[0]['body'], "{$mail}: yerel saat" );
			$ics = (string) current( $sent[0]['ics'] );
			$this->assertStringContainsString( 'DTSTART:20261117T223000Z', $ics, "{$mail}: .ics UTC" );
		}
	}

	public function test_list_tabs_and_stats(): void {
		$pid = $this->bookable();
		$a   = $this->manual_id( $pid, self::TODAY_10 );                        // Bugün, onaylı (elle).
		$b   = $this->manual_id( $pid, '2026-11-17T08:00:00Z' );                // Yarın 11:00.
		$c   = $this->manual_id( $pid, '2026-11-18T07:00:00Z' );                // Çarşamba.
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $c . '/status', array( 'status' => 'cancelled' ) )->get_status() );
		[ , $bank ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-19T07:00:00Z' ) );
		[ , $cod ]  = $this->booked_order( 'cod', 'confirmed', 'on_site', null, array( '2026-11-24T07:00:00Z' ) ); // Gelecek hafta.

		$today = self::data( $this->call( 'GET', '/appointments', array( 'tab' => 'today' ) ) );
		$this->assertSame( 1, $today['total'] );
		$this->assertSame( $a, $today['items'][0]['id'] );

		$up = self::data( $this->call( 'GET', '/appointments', array( 'tab' => 'upcoming' ) ) );
		$this->assertSame( array( $a, $b, $bank[0], $cod[0] ), array_column( $up['items'], 'id' ) );
		$this->assertSame( 4, $up['total'] );

		$cancelled = self::data( $this->call( 'GET', '/appointments', array( 'tab' => 'cancelled' ) ) );
		$this->assertSame( array( $c ), array_column( $cancelled['items'], 'id' ) );

		$paged = self::data(
			$this->call(
				'GET',
				'/appointments',
				array(
					'tab'      => 'upcoming',
					'page'     => 2,
					'per_page' => 3,
				)
			)
		);
		$this->assertSame( array( $cod[0] ), array_column( $paged['items'], 'id' ) );
		$this->assertSame( 4, $paged['total'] );

		$this->assertSame(
			array(
				'today'            => 1,
				'week'             => 3, // Pzt–Paz bu hafta: a, b, bank (c iptal, cod gelecek hafta).
				'awaiting_payment' => 1,
				'on_site'          => 1,
			),
			$up['stats']
		);

		// DTO şekli.
		$item = $today['items'][0];
		$this->assertSame( array( 'id', 'starts_at', 'ends_at', 'local_date', 'local_time', 'product', 'customer', 'status', 'payment_state', 'order', 'location' ), array_keys( $item ) );
		$this->assertSame( '2026-11-16T07:00:00Z', $item['starts_at'] );
		$this->assertSame( '2026-11-16T07:30:00Z', $item['ends_at'] );
		$this->assertSame( '2026-11-16', $item['local_date'] );
		$this->assertSame( '10:00', $item['local_time'] );
		$this->assertSame(
			array(
				'id'   => $pid,
				'name' => 'Danışmanlık',
			),
			$item['product']
		);
		$this->assertSame(
			array(
				'name'  => 'Çağrı Şahin',
				'phone' => '05551112233',
				'email' => 'cagri@example.test',
			),
			$item['customer']
		);
		$this->assertSame( 'confirmed', $item['status'] );
		$this->assertSame( 'manual', $item['payment_state'] );
		$this->assertNull( $item['order'] );
		$this->assertSame( 'Atölye, Kadıköy', $item['location'] );

		$bank_item = $up['items'][2];
		$this->assertSame( $bank[0], $bank_item['id'] );
		$this->assertIsArray( $bank_item['order'] );
		$this->assertGreaterThan( 0, $bank_item['order']['id'] );
		$this->assertStringContainsString( (string) $bank_item['order']['id'], $bank_item['order']['url'] );

		// Geçersiz sekme şema ile reddedilir.
		$this->assertSame( 400, $this->call( 'GET', '/appointments', array( 'tab' => 'drop' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/appointments', array( 'per_page' => 500 ) )->get_status() );
	}

	public function test_detail_has_events_minutes_and_reschedule_count(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 );
		$res = $this->call( 'GET', '/appointments/' . $id );
		$this->assertSame( 200, $res->get_status() );
		$d = self::data( $res );
		$this->assertSame( $id, $d['id'] );
		$this->assertSame( 24 * 60 + 60, $d['minutes_to_start'] ); // 06:00Z → ertesi gün 07:00Z.
		$this->assertSame( 0, $d['reschedule_count'] );
		$this->assertSame( 'status', $d['events'][0]['type'] );
		$this->assertSame( 'confirmed', $d['events'][0]['data']['to'] );
		$this->assertSame( (string) $this->admin, $d['events'][0]['actor'] );
		$this->assertArrayHasKey( 'created_at', $d['events'][0] );

		$this->assertSame( 404, $this->call( 'GET', '/appointments/999999' )->get_status() );
	}

	public function test_list_survives_deleted_product(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 );
		wp_delete_post( $pid, true );
		$this->assertFalse( wc_get_product( $pid ) );

		$list = self::data( $this->call( 'GET', '/appointments', array( 'tab' => 'upcoming' ) ) );
		$this->assertSame( $id, $list['items'][0]['id'] );
		$this->assertSame(
			array(
				'id'   => $pid,
				'name' => 'Danışmanlık',
			),
			$list['items'][0]['product']
		);
		$this->assertSame( 'Atölye, Kadıköy', $list['items'][0]['location'] );
	}

	public function test_manual_appointment_survives_deleted_product(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 );
		wp_delete_post( $pid, true );

		$detail = $this->call( 'GET', '/appointments/' . $id );
		$this->assertSame( 200, $detail->get_status() );
		$this->assertSame( 'Danışmanlık', self::data( $detail )['product']['name'] );

		// Ürün silinmiş randevu yine tamamlanabilir (başlamış) ve CSV'de adıyla çıkar.
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T09:00:00Z' ) );
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $id . '/status', array( 'status' => 'completed' ) )->get_status() );
		$this->assertStringContainsString( 'Danışmanlık', \MHM\Slotiva\Admin\Csv::build( array() ) );
	}

	public function test_manual_create_conflict_409(): void {
		$pid = $this->bookable();
		$res = $this->manual( $pid, self::TODAY_10, false ); // Yönetici ön süreye (24 sa) bağlı değil.
		$this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$d = self::data( $res );
		$this->assertSame( 'confirmed', $d['status'] );
		$this->assertSame( 'manual', $d['payment_state'] );
		$this->assertNull( $d['order'] );
		$r = $this->row( (int) $d['id'] );
		$this->assertNull( $r->orderId );
		$this->assertSame( 'Danışmanlık', $r->productName );
		$this->assertSame( 2, self::units_of( $r->id ) );
		$this->assertFalse( ReservationRepository::customerNotify( $r->id ), 'notify=false kalıcı (Task 11)' );

		// Örtüşen dilim (10:15 → 10:00–10:30 ile çakışır) 409.
		$again = $this->manual( $pid, '2026-11-16T07:15:00Z' );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'slotiva_slot_taken', self::data( $again )['code'] );
		$this->assertSame( 1, self::rows( 'reservations' ) );
	}

	public function test_manual_create_validates_input(): void {
		$pid   = $this->bookable();
		$plain = $this->plain_product();
		// Açık saat dışı (09:00 yerel), 15 dk ızgarası dışı (10:05), geçmiş (dün), randevusuz ürün, eksik ad, bozuk e-posta.
		$cases = array(
			array( 400, 'slotiva_invalid_slot', array( 'slot' => '2026-11-17T06:00:00Z' ) ),
			array( 400, 'slotiva_invalid_slot', array( 'slot' => '2026-11-17T07:05:00Z' ) ),
			array( 400, 'slotiva_invalid_slot', array( 'slot' => '2026-11-13T07:00:00Z' ) ),
			array( 404, 'slotiva_not_found', array( 'product_id' => $plain ) ),
			array( 400, 'rest_invalid_param', array( 'customer' => array( 'name' => '' ) ) ),
			array(
				400,
				'rest_invalid_param',
				array(
					'customer' => array(
						'name'  => 'A',
						'email' => 'not-an-email',
					),
				),
			),
			array( 400, 'rest_invalid_param', array( 'slot' => 'yarın' ) ),
		);
		foreach ( $cases as [ $status, $code, $override ] ) {
			$body = array_merge(
				array(
					'product_id' => $pid,
					'slot'       => self::TOMORROW_10,
					'customer'   => array( 'name' => 'Ayşe' ),
				),
				$override
			);
			$res  = $this->call( 'POST', '/appointments', $body );
			$this->assertSame( $status, $res->get_status(), (string) wp_json_encode( $override ) );
			$this->assertSame( $code, self::data( $res )['code'], (string) wp_json_encode( $override ) );
		}
		$this->assertSame( 0, self::rows( 'reservations' ) );
	}

	public function test_reschedule_endpoint_moves_and_notifies(): void {
		$pid   = $this->bookable();
		$id    = $this->manual_id( $pid, self::TOMORROW_10 );
		$moves = array();
		$spy   = static function ( $rid, $old, $notify ) use ( &$moves ): void {
			$moves[] = array( (int) $rid, $old instanceof \DateTimeImmutable ? $old->format( 'c' ) : '', (bool) $notify );
		};
		add_action( 'slotiva_reservation_rescheduled', $spy, 10, 3 );

		$res = $this->call(
			'POST',
			'/appointments/' . $id . '/reschedule',
			array(
				'start'           => '2026-11-17T08:15:00Z', // 11:15 yerel: 15 dk yönetici ızgarası.
				'count_change'    => true,
				'notify'          => true,
				'expect_sequence' => 0,
			)
		);
		remove_action( 'slotiva_reservation_rescheduled', $spy, 10 );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$d = self::data( $res );
		$this->assertSame( '2026-11-17T08:15:00Z', $d['starts_at'] );
		$this->assertSame( '11:15', $d['local_time'] );
		$this->assertSame( 1, $d['reschedule_count'] );
		$this->assertSame( array( array( $id, '2026-11-17T07:00:00+00:00', true ) ), $moves );
		$r = $this->row( $id );
		$this->assertSame( '2026-11-17 08:15:00', $r->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 1, $r->sequence );
		$this->assertSame( 2, self::units_of( $id ) );

		// Sayılmayan, bildirimsiz değişiklik.
		$res = $this->call(
			'POST',
			'/appointments/' . $id . '/reschedule',
			array(
				'start'           => '2026-11-17T07:00:00Z',
				'count_change'    => false,
				'notify'          => false,
				'expect_sequence' => 1,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, self::data( $res )['reschedule_count'] );

		// Dolu saat 409, açık saat dışı 400, bayat sequence 409, bilinmeyen kimlik 404.
		$other = $this->manual_id( $pid, '2026-11-17T08:00:00Z' );
		$taken = $this->call( 'POST', '/appointments/' . $id . '/reschedule', self::move( '2026-11-17T07:45:00Z', 2 ) );
		$this->assertSame( 409, $taken->get_status() );
		$this->assertSame( 'slotiva_slot_taken', self::data( $taken )['code'] );
		$this->assertSame( '2026-11-17 07:00:00', $this->row( $id )->startsAt->format( 'Y-m-d H:i:s' ), 'çakışmada eski saat korunur' );
		$this->assertSame( 400, $this->call( 'POST', '/appointments/' . $id . '/reschedule', self::move( '2026-11-17T12:00:00Z', 2 ) )->get_status() );
		// expect_sequence zorunlu (M7): eksikse şema reddi.
		$missing = $this->call( 'POST', '/appointments/' . $id . '/reschedule', array( 'start' => '2026-11-17T08:30:00Z' ) );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'rest_missing_callback_param', self::data( $missing )['code'] );
		$stale = $this->call(
			'POST',
			'/appointments/' . $id . '/reschedule',
			array(
				'start'           => '2026-11-17T08:30:00Z',
				'expect_sequence' => 0,
			)
		);
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'slotiva_stale', self::data( $stale )['code'] );
		$this->assertSame( 404, $this->call( 'POST', '/appointments/999999/reschedule', self::move( self::TOMORROW_10, 0 ) )->get_status() );

		// İptal edilmiş randevu taşınamaz.
		$this->call( 'POST', '/appointments/' . $other . '/status', array( 'status' => 'cancelled' ) );
		$dead = $this->call( 'POST', '/appointments/' . $other . '/reschedule', self::move( '2026-11-18T07:00:00Z', 0 ) );
		$this->assertSame( 409, $dead->get_status() );
		$this->assertSame( 'slotiva_invalid_state', self::data( $dead )['code'] );
	}

	public function test_status_cancel_frees_slot(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 );
		$res = $this->call(
			'POST',
			'/appointments/' . $id . '/status',
			array(
				'status' => 'cancelled',
				'notify' => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'cancelled', self::data( $res )['status'] );
		$this->assertSame( 0, self::units_of( $id ) );
		$this->assertSame( array( array( $id, 'confirmed', 'cancelled' ) ), array_values( array_filter( $this->status_events, static fn( array $e ): bool => 'cancelled' === $e[2] ) ) );

		// Saat yeniden alınabilir.
		$this->assertSame( 201, $this->manual( $pid, self::TOMORROW_10 )->get_status() );

		// İptal edilmiş satır tamamlanamaz; şema dışı durum 400.
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T09:00:00Z' ) ); // Başlamış: 409 başlama kuralından değil, iptal durumundan gelir.
		$again = $this->call( 'POST', '/appointments/' . $id . '/status', array( 'status' => 'completed' ) );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'slotiva_invalid_state', self::data( $again )['code'] );
		$this->assertSame( 400, $this->call( 'POST', '/appointments/' . $id . '/status', array( 'status' => 'confirmed' ) )->get_status() );
	}

	public function test_status_completed_and_no_show(): void {
		$pid = $this->bookable();
		$a   = $this->manual_id( $pid, self::TODAY_10 );
		$b   = $this->manual_id( $pid, self::TOMORROW_10 );
		Clock::freeze( new \DateTimeImmutable( '2026-11-17T09:00:00Z' ) ); // İkisi de başlamış.
		$this->assertSame( 'completed', self::data( $this->call( 'POST', '/appointments/' . $a . '/status', array( 'status' => 'completed' ) ) )['status'] );
		$this->assertSame( 'no_show', self::data( $this->call( 'POST', '/appointments/' . $b . '/status', array( 'status' => 'no_show' ) ) )['status'] );
		// Tutulan (ödenmemiş) satır tamamlanamaz.
		[ , $held ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) );
		$this->assertSame( 409, $this->call( 'POST', '/appointments/' . $held[0] . '/status', array( 'status' => 'completed' ) )->get_status() );
		$this->assertSame( 'held', $this->row( $held[0] )->status );
	}

	/**
	 * (Final review M3) Başlamamış randevu tamamlandı / gelmedi yapılamaz; başladıktan sonra yapılır; iptal etkilenmez.
	 */
	public function test_completed_and_no_show_rejected_before_start_allowed_after(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 ); // Şimdi 2026-11-16 09:00 yerel; randevu yarın.
		foreach ( array( 'completed', 'no_show' ) as $to ) {
			$res = $this->call( 'POST', '/appointments/' . $id . '/status', array( 'status' => $to ) );
			$this->assertSame( 409, $res->get_status() );
			$this->assertSame( 'slotiva_invalid_state', self::data( $res )['code'] );
			$this->assertSame( 'confirmed', $this->row( $id )->status );
		}

		// Başlama anında (starts_at == şimdi) izinli.
		Clock::freeze( new \DateTimeImmutable( self::TOMORROW_10 ) );
		$this->assertSame( 'completed', self::data( $this->call( 'POST', '/appointments/' . $id . '/status', array( 'status' => 'completed' ) ) )['status'] );
		$this->assertSame( 'no_show', self::data( $this->call( 'POST', '/appointments/' . $id . '/status', array( 'status' => 'no_show' ) ) )['status'] );

		// İptal başlamamış randevuda da çalışır.
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:00:00Z' ) );
		$other = $this->manual_id( $pid, '2026-11-18T07:00:00Z' );
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $other . '/status', array( 'status' => 'cancelled' ) )->get_status() );
	}

	public function test_extend_hold_only_awaiting_transfer(): void {
		[ , $bank ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) );
		$before     = $this->row( $bank[0] )->holdExpiresAt;
		$this->assertNotNull( $before );
		$res = $this->call( 'POST', '/appointments/' . $bank[0] . '/extend-hold', array( 'hours' => 24 ) );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$d = self::data( $res );
		$this->assertSame( 1, $d['extended'] );
		$this->assertSame( $before->modify( '+24 hours' )->format( 'Y-m-d\TH:i:s\Z' ), $d['hold_expires_at'] );
		$this->assertSame( $before->modify( '+24 hours' )->format( 'c' ), $this->row( $bank[0] )->holdExpiresAt?->format( 'c' ) );

		// Kart tutması (pending), onaylı elle randevu: uzatılamaz.
		[ , $card ] = $this->booked_order( 'slotiva_test_card', 'held', 'pending', '+45 minutes', array( '2026-11-19T07:00:00Z' ) );
		$pid        = $this->bookable();
		$manual     = $this->manual_id( $pid, '2026-11-20T07:00:00Z' );
		foreach ( array( $card[0], $manual ) as $rid ) {
			$no = $this->call( 'POST', '/appointments/' . $rid . '/extend-hold', array( 'hours' => 24 ) );
			$this->assertSame( 409, $no->get_status() );
			$this->assertSame( 'slotiva_not_extendable', self::data( $no )['code'] );
		}
		// Saat sınırı şemada.
		$this->assertSame( 400, $this->call( 'POST', '/appointments/' . $bank[0] . '/extend-hold', array( 'hours' => 0 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/appointments/' . $bank[0] . '/extend-hold', array( 'hours' => 1000 ) )->get_status() );
	}

	public function test_extend_hold_lock_busy_maps_to_409(): void {
		[ $order_id, $bank ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) );
		// Sipariş kilidini AYRI bir bağlantıda tut: depo GET_LOCK'u alamaz, LockBusyException atar.
		$rival = mysqli_init();
		$this->assertTrue( @mysqli_real_connect( $rival, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- test bağlantısı.
		$name = $GLOBALS['wpdb']->prefix . 'slotiva_order_' . $order_id;
		$got  = mysqli_query( $rival, "SELECT GET_LOCK('" . mysqli_real_escape_string( $rival, $name ) . "', 0)" );
		$this->assertSame( '1', (string) mysqli_fetch_row( $got )[0] );
		add_filter( 'slotiva_lock_timeout', '__return_zero' );
		try {
			$res = $this->call( 'POST', '/appointments/' . $bank[0] . '/extend-hold', array( 'hours' => 24 ) );
		} finally {
			remove_filter( 'slotiva_lock_timeout', '__return_zero' );
			mysqli_query( $rival, 'DO RELEASE_ALL_LOCKS()' );
			mysqli_close( $rival );
		}
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'slotiva_busy', self::data( $res )['code'] );
		$this->assertSame( 'held', $this->row( $bank[0] )->status );
	}

	public function test_admin_availability_15_minute_grid_and_exclude(): void {
		$pid = $this->bookable();
		$res = $this->call(
			'GET',
			'/availability',
			array(
				'product' => $pid,
				'day'     => '2026-11-16',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$d = self::data( $res );
		// Bugün, ön süreden bağımsız; 10:00–12:00 içinde 30 dk'lık hizmet 15 dk adımla.
		$this->assertSame( array( '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30' ), array_column( $d['slots'], 'label' ) );
		$this->assertSame( '2026-11-16T07:00:00Z', $d['slots'][0]['start'] );
		$this->assertSame( 'Europe/Istanbul', $d['timezone'] );

		$id    = $this->manual_id( $pid, '2026-11-16T07:30:00Z' ); // 10:30–11:00.
		$after = array_column(
			self::data(
				$this->call(
					'GET',
					'/availability',
					array(
						'product' => $pid,
						'day'     => '2026-11-16',
					)
				)
			)['slots'],
			'label'
		);
		$this->assertSame( array( '10:00', '11:00', '11:15', '11:30' ), $after );

		$excl = $this->call(
			'GET',
			'/availability',
			array(
				'product'             => $pid,
				'day'                 => '2026-11-16',
				'exclude_reservation' => $id,
			)
		);
		$this->assertSame( array( '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30' ), array_column( self::data( $excl )['slots'], 'label' ) );

		// Geçmiş saat çıkmaz (saat 10:20 yerel).
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T07:20:00Z' ) );
		$late = array_column(
			self::data(
				$this->call(
					'GET',
					'/availability',
					array(
						'product'             => $pid,
						'day'                 => '2026-11-16',
						'exclude_reservation' => $id,
					)
				)
			)['slots'],
			'label'
		);
		$this->assertSame( array( '10:30', '10:45', '11:00', '11:15', '11:30' ), $late );

		$this->assertSame(
			404,
			$this->call(
				'GET',
				'/availability',
				array(
					'product' => $this->plain_product(),
					'day'     => '2026-11-16',
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->call(
				'GET',
				'/availability',
				array(
					'product' => $pid,
					'day'     => '16.11.2026',
				)
			)->get_status()
		);
		$this->assertSame(
			404,
			$this->call(
				'GET',
				'/availability',
				array(
					'product'             => $pid,
					'day'                 => '2026-11-16',
					'exclude_reservation' => 999999,
				)
			)->get_status()
		);
	}

	public function test_admin_availability_respects_buffer(): void {
		Rules::save( array( 'bufferMin' => 15 ) );
		$pid = $this->bookable();
		$this->manual_id( $pid, '2026-11-17T07:30:00Z' ); // 10:30–11:00 + ara 11:00–11:15.
		$labels = array_column(
			self::data(
				$this->call(
					'GET',
					'/availability',
					array(
						'product' => $pid,
						'day'     => '2026-11-17',
					)
				)
			)['slots'],
			'label'
		);
		// 10:00 (10:00–10:45 ara dahil) 10:30'a değer → yok; 11:15 ve sonrası serbest.
		$this->assertSame( array( '11:15', '11:30' ), $labels );
	}

	public function test_schedule_get_put_and_warning(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 ); // Salı 10:00.
		$get = self::data( $this->call( 'GET', '/schedule' ) );
		$this->assertCount( 7, $get['days'] );
		$this->assertSame(
			array(
				array(
					'from' => '10:00',
					'to'   => '12:00',
				),
			),
			$get['days'][1]
		);

		$day = array(
			array(
				'from' => '13:00',
				'to'   => '17:00',
			),
		);
		$put = $this->call( 'PUT', '/schedule', array( 'days' => array( $day, $day, $day, $day, $day, array(), array() ) ) );
		$this->assertSame( 200, $put->get_status(), (string) wp_json_encode( $put->get_data() ) );
		$d = self::data( $put );
		$this->assertSame( $day, $d['days'][1] );
		$this->assertSame( array( $id ), $d['outside_hours'], 'onaylı randevu yeni saatlerin dışında kalır: engellenmez, uyarılır' );
		$this->assertSame( 'confirmed', $this->row( $id )->status );

		$bad = $this->call(
			'PUT',
			'/schedule',
			array(
				'days' => array(
					array(
						array(
							'from' => '10:00',
							'to'   => '12:00',
						),
						array(
							'from' => '11:00',
							'to'   => '13:00',
						),
					),
					array(),
					array(),
					array(),
					array(),
					array(),
					array(),
				),
			)
		);
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'slotiva_overlap', self::data( $bad )['code'] );
		$this->assertSame(
			400,
			$this->call(
				'PUT',
				'/schedule',
				array(
					'days' => array(
						array(
							array(
								'from' => '10:10',
								'to'   => '12:00',
							),
						),
						array(),
						array(),
						array(),
						array(),
						array(),
						array(),
					),
				)
			)->get_status()
		);
	}

	/**
	 * Review Focus 5: a schedule change that leaves booked appointments outside the open hours is not blocked; the
	 * bookings are kept and `affected` counts the upcoming confirmed and held ones (held rows are confirmed on payment
	 * without a new hours check, so they are just as affected). Cancelled rows do not count.
	 */
	public function test_schedule_change_reports_affected(): void {
		$pid        = $this->bookable();
		$inside     = $this->manual_id( $pid, self::TOMORROW_10 ); // Salı 10:00, yeni düzende de açık.
		$outside    = $this->manual_id( $pid, '2026-11-17T08:00:00Z' ); // Salı 11:00, yeni düzende kapalı.
		[ , $held ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) ); // Çarşamba.
		$gone       = $this->manual_id( $pid, '2026-11-19T07:00:00Z' ); // Perşembe, iptal edilir.
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $gone . '/status', array( 'status' => 'cancelled' ) )->get_status() );

		$mon = array(
			array(
				'from' => '10:00',
				'to'   => '12:00',
			),
		);
		$tue = array(
			array(
				'from' => '10:00',
				'to'   => '11:00',
			),
			array(
				'from' => '13:00',
				'to'   => '17:00',
			),
		);
		$put = $this->call( 'PUT', '/schedule', array( 'days' => array( $mon, $tue, array(), array(), array(), array(), array() ) ) );
		$this->assertSame( 200, $put->get_status(), (string) wp_json_encode( $put->get_data() ) );
		$d = self::data( $put );
		$this->assertSame( 2, $d['affected'], 'onaylı (Salı 11:00) + tutulan (Çarşamba) = 2; iptal ve içerideki sayılmaz' );
		$this->assertIsInt( $put->get_data()['affected'] );
		$this->assertSame( array( $outside, $held[0] ), $d['outside_hours'] );
		$this->assertSame( 'confirmed', $this->row( $inside )->status );
		$this->assertSame( 'confirmed', $this->row( $outside )->status, 'randevu korunur' );
		$this->assertSame( 'held', $this->row( $held[0] )->status, 'tutma korunur' );
		$this->assertSame( 'cancelled', $this->row( $gone )->status );

		$back = self::data( $this->call( 'PUT', '/schedule', array( 'days' => array( $mon, $mon, $mon, $mon, $mon, array(), array() ) ) ) );
		$this->assertSame( 0, $back['affected'] );
		$this->assertSame( array(), $back['outside_hours'] );
	}

	/**
	 * Fix round 1 (I1): there is no row cap. `outside_hours` pages through every upcoming row in batches (batch size
	 * lowered by the filter here) and loads the exceptions once, so the query count does not grow per row.
	 */
	public function test_affected_counts_every_row_in_batches_with_bounded_queries(): void {
		$pid = $this->bookable();
		$ids = array();
		foreach ( array( '2026-11-17T07:00:00Z', '2026-11-17T07:30:00Z', '2026-11-17T08:00:00Z', '2026-11-17T08:30:00Z', '2026-11-18T07:00:00Z' ) as $slot ) {
			$ids[] = $this->manual_id( $pid, $slot );
		}
		$batch = static fn(): int => 2;
		add_filter( 'slotiva_outside_hours_batch', $batch );
		$sql   = array();
		$log   = static function ( $q ) use ( &$sql ) {
			$sql[] = (string) $q;
			return $q;
		};
		$mon   = array(
			array(
				'from' => '10:00',
				'to'   => '12:00',
			),
		);
		add_filter( 'query', $log );
		$put = $this->call( 'PUT', '/schedule', array( 'days' => array( $mon, array(), array(), array(), array(), array(), array() ) ) );
		remove_filter( 'query', $log );
		remove_filter( 'slotiva_outside_hours_batch', $batch );

		$d = self::data( $put );
		$this->assertSame( 5, $d['affected'], 'beşi de sayılır: 2’lik üç sayfa' );
		$this->assertSame( $ids, $d['outside_hours'] );
		$exc = array_filter( $sql, static fn( string $q ): bool => false !== strpos( $q, 'slotiva_exceptions' ) && 0 === stripos( ltrim( $q ), 'SELECT' ) );
		$res = array_filter( $sql, static fn( string $q ): bool => false !== strpos( $q, 'slotiva_reservations' ) && 0 === stripos( ltrim( $q ), 'SELECT' ) );
		$this->assertCount( 1, $exc, 'istisnalar bir kez okunur (satır başına değil)' );
		$this->assertLessThanOrEqual( 4, count( $res ), 'rezervasyonlar sayfa sayfa: ceil(5/2) + boş son sayfa' );
	}

	/**
	 * Fix round 1 (M4, M7): only future rows count; a booking that ends exactly at the range end is inside; an expired
	 * but not yet released hold does not count; a closed exception can push a booking outside; an extra-open exception
	 * keeps one inside.
	 */
	public function test_affected_edges_past_range_end_exceptions_and_expired_holds(): void {
		global $wpdb;
		$pid           = $this->bookable();
		$at_end        = $this->manual_id( $pid, '2026-11-17T08:30:00Z' ); // Salı 11:30–12:00, aralık sonu 12:00.
		$closed        = $this->manual_id( $pid, '2026-11-18T08:00:00Z' ); // Çarşamba 11:00, kapalı istisnaya düşecek.
		[ , $expired ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '-1 hours', array( '2026-11-20T07:00:00Z' ) ); // Süresi dolmuş tutma, Cuma (yeni düzende kapalı).
		$past          = $this->manual_id( $pid, '2026-11-19T08:00:00Z' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test: geçmişe taşı (Cuma 13 Kasım, yeni düzende kapalı).
		$wpdb->update(
			\MHM\Slotiva\Db\Schema::table( 'reservations' ),
			array(
				'starts_at' => '2026-11-13 08:00:00',
				'ends_at'   => '2026-11-13 08:30:00',
			),
			array( 'id' => $past )
		);
		$this->assertSame(
			201,
			$this->call(
				'POST',
				'/exceptions',
				array(
					'date'      => '2026-11-21',
					'type'      => 'open',
					'from_time' => '10:00',
					'to_time'   => '12:00',
				)
			)->get_status()
		);
		$sat = $this->manual_id( $pid, '2026-11-21T07:00:00Z' ); // Cumartesi, yalnız ek açık istisnayla açık.
		$this->assertSame(
			201,
			$this->call(
				'POST',
				'/exceptions',
				array(
					'date'      => '2026-11-18',
					'type'      => 'closed',
					'from_time' => '11:00',
					'to_time'   => '12:00',
				)
			)->get_status()
		);

		$day = array(
			array(
				'from' => '10:00',
				'to'   => '12:00',
			),
		);
		$d   = self::data( $this->call( 'PUT', '/schedule', array( 'days' => array( $day, $day, $day, $day, array(), array(), array() ) ) ) );
		$this->assertSame( array( $closed ), $d['outside_hours'], 'yalnız kapalı istisnaya düşen' );
		$this->assertSame( 1, $d['affected'] );
		$this->assertNotContains( $at_end, $d['outside_hours'], 'aralık sonunda biten içeride' );
		$this->assertNotContains( $expired[0], $d['outside_hours'], 'süresi dolmuş tutma sayılmaz' );
		$this->assertNotContains( $past, $d['outside_hours'], 'geçmiş sayılmaz' );
		$this->assertNotContains( $sat, $d['outside_hours'], 'ek açık istisna içeride tutar' );
	}

	public function test_rules_retention_months_bounded(): void {
		$this->assertSame( 200, $this->call( 'PUT', '/rules', array( 'retentionMonths' => 120 ) )->get_status() );
		$bad = $this->call( 'PUT', '/rules', array( 'retentionMonths' => 121 ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'slotiva_invalid_value', self::data( $bad )['code'] );
		$this->assertSame( 120, Rules::load()->retentionMonths );
	}

	public function test_rules_get_put(): void {
		$get = self::data( $this->call( 'GET', '/rules' ) );
		$this->assertSame( 24, $get['leadHours'] );
		$this->assertArrayHasKey( 'bufferMin', $get );

		$put = $this->call(
			'PUT',
			'/rules',
			array(
				'leadHours'  => 2,
				'reminderOn' => false,
			)
		);
		$this->assertSame( 200, $put->get_status() );
		$this->assertSame( 2, self::data( $put )['leadHours'] );
		$this->assertFalse( self::data( $put )['reminderOn'] );
		$this->assertSame( 2, Rules::load()->leadHours );

		$bad = $this->call( 'PUT', '/rules', array( 'bufferMin' => 10 ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'slotiva_invalid_step', self::data( $bad )['code'] );
		$this->assertSame( 400, $this->call( 'PUT', '/rules', array( 'horizonDays' => 'çok' ) )->get_status() );
		$this->assertSame( 0, Rules::load()->bufferMin );
	}

	/**
	 * Codex pre-ZIP: veritabanı hatası doğrulama (400) ya da bulunamadı (404) olarak görünmez; 500 döner.
	 * Hata `query` süzgeciyle benzetilir (EraserTest ile aynı yöntem).
	 */
	public function test_exception_db_failure_is_500_not_400_or_404(): void {
		global $wpdb;
		$table    = Schema::table( 'exceptions' );
		$break    = static fn( $q ) => preg_match( '/^\s*(INSERT INTO|DELETE FROM)\s+`' . preg_quote( $table, '/' ) . '`/i', (string) $q ) ? 'SELECT broken syntax FROM' : $q;
		$suppress = $wpdb->suppress_errors( true );
		try {
			add_filter( 'query', $break );
			$add = $this->call(
				'POST',
				'/exceptions',
				array(
					'date' => '2026-11-17',
					'type' => 'closed',
					'note' => 'x',
				)
			);
			$this->assertSame( 500, $add->get_status() );
			$this->assertSame( 'slotiva_db_error', self::data( $add )['code'] );
			remove_filter( 'query', $break );

			$ok = $this->call(
				'POST',
				'/exceptions',
				array(
					'date' => '2026-11-17',
					'type' => 'closed',
					'note' => 'x',
				)
			);
			$this->assertSame( 201, $ok->get_status() );
			$id = (int) self::data( $ok )['id'];

			add_filter( 'query', $break );
			$del = $this->call( 'DELETE', '/exceptions/' . $id );
			$this->assertSame( 500, $del->get_status(), 'DB hatası 404 olmamalı' );
			$this->assertSame( 'slotiva_db_error', self::data( $del )['code'] );
			remove_filter( 'query', $break );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( $suppress );
		}

		// Hata yokken: silme 200, ikinci silme (satır yok) 404, geçersiz girdi 400.
		$this->assertSame( 200, $this->call( 'DELETE', '/exceptions/' . $id )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/exceptions/' . $id )->get_status() );
		$this->assertSame(
			400,
			$this->call(
				'POST',
				'/exceptions',
				array(
					'date'      => '2026-11-17',
					'type'      => 'open',
					'from_time' => '10:00',
					'to_time'   => '09:00',
				)
			)->get_status()
		);
	}

	public function test_exceptions_crud(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 );
		$add = $this->call(
			'POST',
			'/exceptions',
			array(
				'date' => '2026-11-17',
				'type' => 'closed',
				'note' => 'Tatil',
			)
		);
		$this->assertSame( 201, $add->get_status(), (string) wp_json_encode( $add->get_data() ) );
		$d = self::data( $add );
		$this->assertGreaterThan( 0, $d['id'] );
		$this->assertSame( array( $id ), $d['outside_hours'] );

		$open = $this->call(
			'POST',
			'/exceptions',
			array(
				'date'      => '2026-11-21',
				'type'      => 'open',
				'from_time' => '10:00',
				'to_time'   => '14:00',
			)
		);
		$this->assertSame( 201, $open->get_status() );

		$list = self::data(
			$this->call(
				'GET',
				'/exceptions',
				array(
					'from' => '2026-11-01',
					'to'   => '2026-11-30',
				)
			)
		);
		$this->assertSame( array( '2026-11-17', '2026-11-21' ), array_column( $list['items'], 'date' ) );
		$this->assertSame( 'Tatil', $list['items'][0]['note'] );

		$this->assertSame(
			400,
			$this->call(
				'POST',
				'/exceptions',
				array(
					'date' => '2026-11-31',
					'type' => 'closed',
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->call(
				'POST',
				'/exceptions',
				array(
					'date' => '2026-11-22',
					'type' => 'holiday',
				)
			)->get_status()
		);

		$this->assertSame( 200, $this->call( 'DELETE', '/exceptions/' . $d['id'] )->get_status() );
		$this->assertSame( 404, $this->call( 'DELETE', '/exceptions/' . $d['id'] )->get_status() );
		$this->assertCount(
			1,
			self::data(
				$this->call(
					'GET',
					'/exceptions',
					array(
						'from' => '2026-11-01',
						'to'   => '2026-11-30',
					)
				)
			)['items']
		);
	}

	public function test_export_requires_query_nonce_and_serves_raw_csv(): void {
		$pid = $this->bookable();
		$this->manual_id( $pid, self::TOMORROW_10 );

		$missing = $this->call( 'GET', '/export.csv' );
		$this->assertSame( 403, $missing->get_status() );
		$this->assertSame( 'slotiva_invalid_nonce', self::data( $missing )['code'] );
		$this->assertSame( 403, $this->call( 'GET', '/export.csv', array( '_wpnonce' => 'deadbeef00' ) )->get_status() );

		$res = $this->call( 'GET', '/export.csv', array( '_wpnonce' => wp_create_nonce( 'wp_rest' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$headers = $res->get_headers();
		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertStringContainsString( 'attachment; filename="slotiva-', $headers['Content-Disposition'] );
		$this->assertStringContainsString( 'no-cache', $headers['Cache-Control'] );
		$body = (string) $res->get_data();
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $body );

		// Ham gövde: rest_pre_serve_request JSON kodlamasını atlar.
		$req = new \WP_REST_Request( 'GET', self::NS . '/export.csv' );
		ob_start();
		$served = RestController::serve_csv( false, $res, $req, rest_get_server() );
		$out    = (string) ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( $body, $out );

		// Başka uçların yanıtına dokunmaz; CSV ucunun hata yanıtı JSON kalır.
		ob_start();
		$this->assertFalse( RestController::serve_csv( false, $this->call( 'GET', '/rules' ), new \WP_REST_Request( 'GET', self::NS . '/rules' ), rest_get_server() ) );
		$this->assertFalse( RestController::serve_csv( false, $missing, $req, rest_get_server() ) );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_admin_cancel_order_linked_adds_note_and_leaves_order(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) );
		$this->order( $oid )->update_status( 'on-hold' );
		$res = $this->call( 'POST', '/appointments/' . $ids[0] . '/status', array( 'status' => 'cancelled' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'on-hold', $this->order( $oid )->get_status(), 'sipariş değişmez' );
		$notes = implode( "\n", $this->notes( $oid ) );
		$this->assertStringContainsString( 'booking #' . $ids[0], $notes );
		$this->assertStringContainsString( 'was cancelled by', $notes );
		$this->assertStringContainsString( 'The order itself was not changed', $notes );
		$this->assertSame( 0, self::units_of( $ids[0] ) );

		// Siparişsiz (elle) randevuda not yazılacak sipariş yok; hata da yok.
		$pid = $this->bookable();
		$mid = $this->manual_id( $pid, '2026-11-19T07:00:00Z' );
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $mid . '/status', array( 'status' => 'cancelled' ) )->get_status() );
	}

	public function test_admin_cancelled_then_paid_raises_conflict_once(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) );
		$this->order( $oid )->update_status( 'on-hold' );
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $ids[0] . '/status', array( 'status' => 'cancelled' ) )->get_status() );
		$this->sent = array();

		$this->order( $oid )->update_status( 'processing' ); // Havale geldi; yönetici siparişi ödendi yaptı.
		$this->assertSame( array( array( $oid, array( $ids[0] ), 'admin_cancelled_paid' ) ), $this->conflicts );
		$this->assertSame( 'cancelled', $this->row( $ids[0] )->status, 'saat yeniden alınmaz' );
		$this->assertSame( 0, self::units_of( $ids[0] ) );
		$this->assertSame( 1, self::rows( 'reservations' ), 'yeni tutma kurulmadı' );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_conflict' ) );
		$this->assertStringContainsString( 'cancelled by an administrator', $this->sent_of( 'slotiva_admin_conflict' )[0]['body'] );
		$this->assertStringContainsString( 'cancelled by an administrator before the payment arrived', implode( "\n", $this->notes( $oid ) ) );

		// Yinelenen ödeme bildirimi ikinci çakışma üretmez.
		PaymentListener::onPaid( $oid );
		$this->assertCount( 1, $this->conflicts );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_conflict' ) );
	}

	public function test_non_admin_cancelled_order_paid_is_not_admin_conflict(): void {
		// Siparişin kendi iptali (reason admin değil) yönetici iptali sayılmaz: çakışma yok.
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) );
		ReservationRepository::release( $ids[0], 'cancelled', array( 'reason' => 'order_cancelled' ) );
		$this->order( $oid )->update_status( 'processing' );
		$this->assertSame( array(), $this->conflicts );
	}

	public function test_reschedule_grid_survives_deleted_product(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 );
		wp_delete_post( $pid, true );
		$res = $this->call(
			'GET',
			'/availability',
			array(
				'product'             => $pid,
				'day'                 => '2026-11-17',
				'exclude_reservation' => $id,
			)
		);
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( array( '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30' ), array_column( self::data( $res )['slots'], 'label' ) );
		$move = $this->call( 'POST', '/appointments/' . $id . '/reschedule', self::move( '2026-11-17T08:00:00Z', 0 ) );
		$this->assertSame( 200, $move->get_status(), (string) wp_json_encode( $move->get_data() ) );
		$this->assertSame( '11:00', self::data( $move )['local_time'] );
		// Ürünsüz, dışlamasız istek hâlâ 404.
		$this->assertSame(
			404,
			$this->call(
				'GET',
				'/availability',
				array(
					'product' => $pid,
					'day'     => '2026-11-17',
				)
			)->get_status()
		);
	}

	public function test_manual_create_rejects_blank_name_and_page_cap(): void {
		$pid = $this->bookable();
		$res = $this->call(
			'POST',
			'/appointments',
			array(
				'product_id' => $pid,
				'slot'       => self::TOMORROW_10,
				'customer'   => array( 'name' => "  \t " ),
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_invalid_value', self::data( $res )['code'] );
		$this->assertSame( 0, self::rows( 'reservations' ) );
		$ok = $this->call(
			'POST',
			'/appointments',
			array(
				'product_id' => $pid,
				'slot'       => self::TOMORROW_10,
				'customer'   => array( 'name' => '  Ayşe  ' ),
			)
		);
		$this->assertSame( 'Ayşe', self::data( $ok )['customer']['name'] );

		$this->assertSame( 400, $this->call( 'GET', '/appointments', array( 'page' => 100001 ) )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/appointments', array( 'page' => 100000 ) )->get_status() );
	}

	public function test_extend_hold_writes_history_and_order_note(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) );
		$res           = $this->call( 'POST', '/appointments/' . $ids[0] . '/extend-hold', array( 'hours' => 12 ) );
		$this->assertSame( 200, $res->get_status() );
		$until  = self::data( $res )['hold_expires_at'];
		$events = array_values( array_filter( self::data( $this->call( 'GET', '/appointments/' . $ids[0] ) )['events'], static fn( array $e ): bool => 'hold_extended' === $e['type'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( $until, $events[0]['data']['until'] );
		$this->assertSame( 12, $events[0]['data']['hours'] );
		$this->assertSame( (string) $this->admin, $events[0]['actor'] );
		$this->assertStringContainsString( 'payment deadline was extended', implode( "\n", $this->notes( $oid ) ) );
	}

	public function test_put_schedule_requires_all_seven_days(): void {
		$day = array(
			array(
				'from' => '13:00',
				'to'   => '17:00',
			),
		);
		$res = $this->call( 'PUT', '/schedule', array( 'days' => array( $day, $day, $day, $day, $day ) ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'rest_invalid_param', self::data( $res )['code'] );
		$this->assertSame( '10:00', self::data( $this->call( 'GET', '/schedule' ) )['days'][1][0]['from'], 'düzen değişmedi' );
	}

	public function test_stored_notify_flag_reaches_mailer_through_rest(): void {
		$pid = $this->bookable();
		// notify=false ile eklenen: sonradan notify=true istense de müşteriye posta gitmez.
		$quiet = (int) self::data( $this->manual( $pid, self::TOMORROW_10, false ) )['id'];
		$this->assertSame( array(), $this->sent_of( 'slotiva_customer_confirmed' ) );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_new' ) );
		$this->call( 'POST', '/appointments/' . $quiet . '/reschedule', self::move( '2026-11-17T08:00:00Z', 0 ) + array( 'notify' => true ) );
		$this->call(
			'POST',
			'/appointments/' . $quiet . '/status',
			array(
				'status' => 'cancelled',
				'notify' => true,
			)
		);
		$this->assertSame( array(), $this->sent_of( 'slotiva_customer_rescheduled' ) );
		$this->assertSame( array(), $this->sent_of( 'slotiva_customer_cancelled' ) );

		// Kontrol: notify=true ile eklenen aynı yoldan posta alır.
		$loud = (int) self::data( $this->manual( $pid, '2026-11-18T07:00:00Z', true ) )['id'];
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_confirmed' ) );
		$this->call( 'POST', '/appointments/' . $loud . '/reschedule', self::move( '2026-11-18T08:00:00Z', 0 ) + array( 'notify' => true ) );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_rescheduled' ) );
		$this->call(
			'POST',
			'/appointments/' . $loud . '/status',
			array(
				'status' => 'cancelled',
				'notify' => true,
			)
		);
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_cancelled' ) );
		$this->assertSame( 'cagri@example.test', $this->sent_of( 'slotiva_customer_cancelled' )[0]['to'] );
	}

	public function test_export_case_variant_route_still_served_raw(): void {
		$res = new \WP_REST_Response( "\xEF\xBB\xBFDate\r\n", 200 );
		ob_start();
		$served = RestController::serve_csv( false, $res, new \WP_REST_Request( 'GET', '/slotiva/v1/admin/EXPORT.CSV' ), rest_get_server() );
		$out    = (string) ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( "\xEF\xBB\xBFDate\r\n", $out );
	}

	public function test_admin_cancel_of_paid_booking_then_completed_is_not_conflict(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z' ) );
		$this->order( $oid )->update_status( 'processing' ); // Ödendi: satır confirmed/paid.
		$this->assertSame( 'paid', $this->row( $ids[0] )->paymentState );
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $ids[0] . '/status', array( 'status' => 'cancelled' ) )->get_status() );
		$this->sent = array();

		$this->order( $oid )->update_status( 'completed' ); // Yeniden "ödendi" durumu (onPaid tekrar koşar).
		PaymentListener::onPaid( $oid );                     // payment_complete / sync yinelemesi.
		$this->assertSame( array(), $this->conflicts );
		$this->assertSame( array(), $this->sent_of( 'slotiva_admin_conflict' ) );
		$this->assertStringNotContainsString( 'before the payment arrived', implode( "\n", $this->notes( $oid ) ) );
		$this->assertSame( 'cancelled', $this->row( $ids[0] )->status );
	}

	public function test_superseded_and_admin_cancelled_rows_send_one_combined_conflict(): void {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( '2026-11-18T07:00:00Z', '2026-11-19T07:00:00Z' ) );
		$this->order( $oid )->update_status( 'on-hold' );
		ReservationRepository::release( $ids[0], 'superseded', array( 'reason' => 'session' ) );
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $ids[1] . '/status', array( 'status' => 'cancelled' ) )->get_status() );
		$this->sent = array();

		$this->order( $oid )->update_status( 'processing' );
		$this->assertCount( 1, $this->conflicts );
		$this->assertSame( $oid, $this->conflicts[0][0] );
		$this->assertSame( array( $ids[0], $ids[1] ), $this->conflicts[0][1] );
		$this->assertSame( 'superseded_admin_cancelled_paid', $this->conflicts[0][2] );
		$this->assertCount( 1, $this->sent_of( 'slotiva_admin_conflict' ) );
		$this->assertSame( 'conflict', $this->row( $ids[0] )->status );
		$this->assertSame( 'cancelled', $this->row( $ids[1] )->status );
	}

	public function test_availability_product_optional_with_exclude(): void {
		$pid = $this->bookable();
		$id  = $this->manual_id( $pid, self::TOMORROW_10 );
		$res = $this->call(
			'GET',
			'/availability',
			array(
				'day'                 => '2026-11-17',
				'exclude_reservation' => $id,
			)
		);
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertCount( 7, self::data( $res )['slots'] );
		$none = $this->call( 'GET', '/availability', array( 'day' => '2026-11-17' ) );
		$this->assertSame( 400, $none->get_status() );
		$this->assertSame( 'rest_missing_callback_param', self::data( $none )['code'] );
	}

	/**
	 * Ödenmiş ama saati alınamamış (`conflict/paid`) siparişli randevu.
	 *
	 * @param string $slot Dilim.
	 * @return array{0:int,1:int} [sipariş, rezervasyon]
	 */
	private function conflict_row( string $slot = self::TOMORROW_10 ): array {
		[ $oid, $ids ] = $this->booked_order( 'bacs', 'held', 'awaiting_transfer', '+24 hours', array( $slot ) );
		$this->assertTrue( ReservationRepository::setStatus( $ids[0], 'conflict', 'paid', array( 'reason' => 'late_paid_taken' ) ) );
		$this->assertSame( 0, self::units_of( $ids[0] ) );
		$this->sent = array();
		return array( $oid, $ids[0] );
	}

	/**
	 * (Final review F3 / I-3) Çakışmalı randevuya yönetici yeni saat verir: birimler alınır, `confirmed/paid`,
	 * sequence++, müşteriye tek "saat değişti" e-postası (onay/yeni randevu e-postası ayrıca gitmez), siparişin kayıtlı
	 * talebi yeni saate yazılır.
	 */
	public function test_conflict_row_can_be_rescheduled(): void {
		[ $oid, $id ] = $this->conflict_row();
		$res          = $this->call( 'POST', '/appointments/' . $id . '/reschedule', self::move( '2026-11-17T08:00:00Z', 0 ) + array( 'notify' => true ) );
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$r = $this->row( $id );
		$this->assertSame( 'confirmed', $r->status );
		$this->assertSame( 'paid', $r->paymentState );
		$this->assertSame( '2026-11-17 08:00:00', $r->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 1, $r->sequence );
		$this->assertSame( 2, self::units_of( $id ), '30 dk = 2 birim alındı' );
		$this->assertCount( 1, $this->sent_of( 'slotiva_customer_rescheduled' ) );
		$this->assertCount( 0, $this->sent_of( 'slotiva_customer_confirmed' ) );
		$this->assertCount( 0, $this->sent_of( 'slotiva_admin_new' ) );
		$this->assertSame( array( '2026-11-17T08:00:00Z' ), $this->order( $oid )->get_meta( \MHM\Slotiva\Checkout\CheckoutFlow::META_REQUESTED ) );

		// Dolu saate verilemez (başka randevunun üstüne yazılmaz).
		[ , $other ] = $this->conflict_row( '2026-11-18T07:00:00Z' );
		$taken       = $this->call( 'POST', '/appointments/' . $other . '/reschedule', self::move( '2026-11-17T08:00:00Z', 0 ) );
		$this->assertSame( 409, $taken->get_status() );
		$this->assertSame( 'conflict', $this->row( $other )->status );
		$this->assertSame( 0, self::units_of( $other ) );
	}

	/**
	 * (Final review F3) Çakışmalı randevu iptal edilebilir (birim yok); listeden "İptal" sekmesine geçer.
	 */
	public function test_conflict_row_can_be_cancelled(): void {
		[ , $id ] = $this->conflict_row();
		$res      = $this->call(
			'POST',
			'/appointments/' . $id . '/status',
			array(
				'status' => 'cancelled',
				'notify' => false,
			)
		);
		$this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'cancelled', $this->row( $id )->status );
		$cancelled = self::data( $this->call( 'GET', '/appointments', array( 'tab' => 'cancelled' ) ) );
		$this->assertSame( array( $id ), array_column( $cancelled['items'], 'id' ) );
	}

	/**
	 * (1.0.1) Çakışmalı randevu saat tutmaz: iptal notu "saat boşaldı" demez, saat tutmadığını söyler.
	 */
	public function test_conflict_cancel_note_does_not_claim_a_freed_slot(): void {
		[ $oid, $id ] = $this->conflict_row();
		$this->assertSame( 200, $this->call( 'POST', '/appointments/' . $id . '/status', array( 'status' => 'cancelled' ) )->get_status() );
		$notes = implode( "\n", $this->notes( $oid ) );
		$this->assertStringContainsString( 'was cancelled by', $notes );
		$this->assertStringContainsString( 'held no time slot', $notes );
		$this->assertStringNotContainsString( 'free again', $notes );
		// Müşteri e-postası da saat boşaldı demez (Fable 1.0.1 ön-ZIP bulgusu).
		$mail = $this->sent_of( 'slotiva_customer_cancelled' );
		$this->assertCount( 1, $mail );
		$this->assertStringContainsString( 'Your booking has been cancelled', $mail[0]['body'] );
		$this->assertStringNotContainsString( 'released', $mail[0]['body'] );
	}

	/**
	 * (Final review F3) Çakışmalı randevu listede kalır ama "bugün"/"bu hafta" sayaçlarına girmez (saati yok).
	 */
	public function test_conflict_row_not_counted_in_stats(): void {
		$this->conflict_row( self::TODAY_10 );
		$today = self::data( $this->call( 'GET', '/appointments', array( 'tab' => 'today' ) ) );
		$this->assertSame( 1, $today['total'], 'listede görünür' );
		$this->assertSame( 0, $today['stats']['today'] );
		$this->assertSame( 0, $today['stats']['week'] );
	}

	/**
	 * (Final review F11 / m-7) `deleteDataOnUninstall` yalnız `manage_options` ile değişir: mağaza yöneticisi açamaz
	 * (403), aynı değeri göndermesi (ayar ekranı tüm alanları yollar) ise engellenmez.
	 */
	public function test_delete_data_flag_requires_manage_options(): void {
		$manager = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $manager );
		$res = $this->call( 'PUT', '/rules', array( 'deleteDataOnUninstall' => true ) );
		$this->assertSame( 403, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
		$this->assertFalse( Rules::load()->deleteDataOnUninstall );

		$same = $this->call(
			'PUT',
			'/rules',
			array(
				'leadHours'             => 3,
				'deleteDataOnUninstall' => false,
			)
		);
		$this->assertSame( 200, $same->get_status(), (string) wp_json_encode( $same->get_data() ) );
		$this->assertSame( 3, Rules::load()->leadHours );

		wp_set_current_user( $this->admin );
		$this->assertSame( 200, $this->call( 'PUT', '/rules', array( 'deleteDataOnUninstall' => true ) )->get_status() );
		$this->assertTrue( Rules::load()->deleteDataOnUninstall );

		// Yönetici açtıysa mağaza yöneticisi kapatamaz da.
		wp_set_current_user( $manager );
		$this->assertSame( 403, $this->call( 'PUT', '/rules', array( 'deleteDataOnUninstall' => false ) )->get_status() );
		$this->assertTrue( Rules::load()->deleteDataOnUninstall );
	}
}
