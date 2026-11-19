<?php
/**
 * WP silici + WC sipariş anonimleştirme (spec senaryo 21): kişisel alanlar boşalır, randevu satırı kalır; çok sayfada
 * satır atlanmaz; yönetim listesi/CSV boş müşteri alanlarını kaldırır.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Privacy;

use MHM\Slotiva\Admin\Csv;
use MHM\Slotiva\Admin\RestController;
use MHM\Slotiva\Privacy\Eraser;
use MHM\Slotiva\Reservation\EventLog;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\LifecycleFixtures;
use MHM\Slotiva\Tests\Support\PrivacyFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;
use MHM\Slotiva\Tests\Support\TestCardGateway;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek/WC kancaları.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.

class EraserTest extends SlotivaTestCase {

	use CheckoutFixtures;
	use LifecycleFixtures;
	use PrivacyFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
		$this->set_up_lifecycle();
	}

	public function tear_down(): void {
		$this->tear_down_lifecycle();
		$this->tear_down_checkout();
		parent::tear_down();
	}

	public function test_registered_with_wp(): void {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertArrayHasKey( 'mhm-slotiva', $erasers );
		$this->assertIsCallable( $erasers['mhm-slotiva']['callback'] );
	}

	public function test_erase_blanks_pii_keeps_row(): void {
		$ids    = $this->make_bookings( 1, 'ayse@example.test' );
		$other  = $this->make_bookings( 1, 'baska@example.test', '2026-12-05T07:00:00Z', 'Başka Kişi' );
		$before = $this->raw_row( $ids[0] );

		$out = Eraser::erase( 'ayse@example.test' );

		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$out
		);
		$row = $this->raw_row( $ids[0] );
		$this->assertSame( '', $row['customer_name'] );
		$this->assertSame( '', $row['customer_email'] );
		$this->assertSame( '', $row['customer_phone'] );
		$this->assertNull( $row['session_hash'] );
		$this->assertNull( $row['ip_hash'] );
		foreach ( array( 'starts_at', 'ends_at', 'product_id', 'product_name', 'status', 'payment_state', 'token' ) as $keep ) {
			$this->assertSame( $before[ $keep ], $row[ $keep ], "$keep kalır" );
		}
		$this->assertNotNull( ReservationRepository::find( $ids[0] ), 'randevu satırı kalır' );

		$text = $this->all_event_text();
		$this->assertStringNotContainsString( 'Yönetici Kişi', $text );
		$this->assertStringNotContainsString( 'ayse@example.test', $text );
		$this->assertStringNotContainsString( 'Ayşe', $text, 'metin içinde geçen ad da silinir' );
		$this->assertStringContainsString( '"reason":"admin"', $text, 'işlevsel alanlar kalır' );
		$this->assertStringContainsString( '"notify":false', $text, 'bildirim tercihi kalır' );

		$this->assertSame( 'Başka Kişi', $this->raw_row( $other[0] )['customer_name'], 'başkasına dokunulmaz' );
	}

	public function test_erase_unknown_email_is_a_noop(): void {
		$out = Eraser::erase( 'yok@example.test' );
		$this->assertFalse( $out['items_removed'] );
		$this->assertTrue( $out['done'] );
	}

	public function test_erase_over_150_records_skips_no_row(): void {
		$ids = $this->make_bookings( 150, 'cok@example.test' );

		$p1 = Eraser::erase( 'cok@example.test', 1 );
		$this->assertFalse( $p1['done'] );
		$this->assertTrue( $p1['items_removed'] );
		$this->assertSame( 100, $this->count_blank( $ids ), 'ilk çağrı tam 100 satır' );

		// WP bir sonraki çağrıda page=2 gönderir; OFFSET kullanılsaydı kalan 50'nin 50'si de atlanırdı.
		$p2 = Eraser::erase( 'cok@example.test', 2 );
		$this->assertTrue( $p2['done'] );
		$this->assertSame( 150, $this->count_blank( $ids ), 'hiçbir satır atlanmadı' );

		$p3 = Eraser::erase( 'cok@example.test', 3 );
		$this->assertTrue( $p3['done'] );
		$this->assertFalse( $p3['items_removed'] );
		$this->assertStringNotContainsString( 'cok@example.test', $this->all_event_text() );
		$this->assertStringNotContainsString( 'Yönetici Kişi', $this->all_event_text() );
	}

	public function test_erase_scrubs_actor_of_the_matching_user(): void {
		$uid = self::factory()->user->create( array( 'user_email' => 'ayse@example.test' ) );
		$ids = $this->make_bookings( 1, 'ayse@example.test' );
		global $wpdb;
		$table = \MHM\Slotiva\Db\Schema::table( 'events' );
		$wpdb->update( $table, array( 'actor' => (string) $uid ), array( 'reservation_id' => $ids[0] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test kurgusu.

		Eraser::erase( 'ayse@example.test' );

		$this->assertSame( array( 'system' ), array_values( array_unique( (array) $wpdb->get_col( $wpdb->prepare( "SELECT actor FROM {$table} WHERE reservation_id = %d", $ids[0] ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test kurgusu.
	}

	public function test_name_like_a_functional_value_does_not_destroy_the_audit_trail(): void {
		$ids = $this->make_bookings( 1, 'can@example.test', '2026-12-01T07:00:00Z', 'Can' );
		EventLog::add(
			$ids[0],
			'status',
			array(
				'from'   => 'confirmed',
				'to'     => 'cancelled',
				'reason' => 'admin',
			)
		);

		Eraser::erase( 'can@example.test' );

		$text = $this->all_event_text();
		$this->assertStringContainsString( '"to":"cancelled"', $text, '"Can" adı "cancelled" değerini silmez' );
		$this->assertStringContainsString( '"from":"confirmed"', $text );
		$this->assertStringNotContainsString( 'can@example.test', $text );
		$this->assertStringNotContainsString( 'text_copy', $text, 'serbest metin alanı yine silinir' );
	}

	public function test_only_the_subjects_actor_is_replaced_admins_stay(): void {
		global $wpdb;
		$uid   = self::factory()->user->create( array( 'user_email' => 'ayse@example.test' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$ids   = $this->make_bookings( 1, 'ayse@example.test' );
		$table = \MHM\Slotiva\Db\Schema::table( 'events' );
		$evs   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE reservation_id = %d ORDER BY id", $ids[0] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test kurgusu.
		$wpdb->update( $table, array( 'actor' => (string) $uid ), array( 'id' => (int) $evs[0] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test kurgusu.
		$wpdb->update( $table, array( 'actor' => (string) $admin ), array( 'id' => (int) $evs[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test kurgusu.

		Eraser::erase( 'ayse@example.test' );

		$this->assertSame( 'system', $wpdb->get_var( $wpdb->prepare( "SELECT actor FROM {$table} WHERE id = %d", (int) $evs[0] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test kurgusu.
		$this->assertSame( (string) $admin, $wpdb->get_var( $wpdb->prepare( "SELECT actor FROM {$table} WHERE id = %d", (int) $evs[1] ) ), 'yönetici denetim izi kalır' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test kurgusu.
	}

	public function test_failed_event_write_keeps_the_row_matchable_and_retry_succeeds(): void {
		global $wpdb;
		$ids      = $this->make_bookings( 1, 'ayse@example.test' );
		$table    = \MHM\Slotiva\Db\Schema::table( 'events' );
		$break    = static fn( $q ) => str_starts_with( ltrim( (string) $q ), "UPDATE `{$table}`" ) ? 'SELECT broken syntax FROM' : $q;
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		$out = Eraser::erase( 'ayse@example.test' );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );
		$this->assertTrue( $out['items_retained'] );
		$this->assertFalse( $out['items_removed'] );
		$this->assertTrue( $out['done'] );
		$this->assertNotEmpty( $out['messages'] );
		$this->assertSame( 'ayse@example.test', $this->raw_row( $ids[0] )['customer_email'], 'satır hâlâ eşleşir' );

		$retry = Eraser::erase( 'ayse@example.test' );
		$this->assertTrue( $retry['items_removed'] );
		$this->assertSame( '', $this->raw_row( $ids[0] )['customer_email'] );
		$this->assertStringNotContainsString( 'Yönetici Kişi', $this->all_event_text() );
	}

	/**
	 * (Pre-ZIP gate G5) Satır boşaltma (scrubPersonal) başarısızsa satır "anonimleşti" İŞARETİ almaz: işaretli satır
	 * kişisel veri taşıyamaz. Yeniden deneme hem boşaltır hem işaretler.
	 */
	public function test_failed_row_scrub_leaves_no_anonymised_marker(): void {
		global $wpdb;
		$ids      = $this->make_bookings( 1, 'ayse@example.test' );
		$notify   = ReservationRepository::customerNotify( $ids[0] );
		$table    = \MHM\Slotiva\Db\Schema::table( 'reservations' );
		$break    = static fn( $q ) => str_starts_with( ltrim( (string) $q ), "UPDATE `{$table}` SET customer_name = ''" ) ? 'SELECT broken syntax FROM' : $q;
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );

		$out = Eraser::erase( 'ayse@example.test' );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );
		$this->assertTrue( $out['items_retained'] );
		$this->assertSame( 'ayse@example.test', $this->raw_row( $ids[0] )['customer_email'], 'satır boşalmadı' );
		$this->assertFalse( ReservationRepository::isAnonymised( $ids[0] ), 'boşalmamış satır işaretlenmez' );
		$this->assertSame( $notify, ReservationRepository::customerNotify( $ids[0] ), 'bildirim tercihi değişmez' );

		$retry = Eraser::erase( 'ayse@example.test' );
		$this->assertTrue( $retry['items_removed'] );
		$this->assertSame( '', $this->raw_row( $ids[0] )['customer_email'] );
		$this->assertTrue( ReservationRepository::isAnonymised( $ids[0] ) );
		$this->assertFalse( ReservationRepository::customerNotify( $ids[0] ) );
	}

	public function test_exactly_100_records_needs_one_more_empty_call(): void {
		$ids = $this->make_bookings( 100, 'yuz@example.test' );

		$p1 = Eraser::erase( 'yuz@example.test', 1 );
		$this->assertFalse( $p1['done'], 'sayfa tam doldu: bitti denemez' );
		$this->assertSame( 100, $this->count_blank( $ids ) );

		$p2 = Eraser::erase( 'yuz@example.test', 2 );
		$this->assertTrue( $p2['done'] );
		$this->assertFalse( $p2['items_removed'] );
	}

	public function test_order_anonymisation_blanks_snapshot(): void {
		[ $order_id, $ids ] = $this->booked_order( TestCardGateway::ID );
		global $wpdb;
		$table = \MHM\Slotiva\Db\Schema::table( 'reservations' );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test kurgusu: anlık kopyayı doldur.
			$table,
			array(
				'customer_name'  => 'Ayşe Yılmaz',
				'customer_email' => 'ayse@example.test',
				'customer_phone' => '+905551112233',
				'session_hash'   => str_repeat( 'a', 32 ),
				'ip_hash'        => str_repeat( 'b', 32 ),
			),
			array( 'id' => $ids[0] )
		);
		$this->assertSame( 'ayse@example.test', ReservationRepository::find( $ids[0] )->customerEmail, 'kurgu: kopya dolu' );

		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		do_action( 'woocommerce_privacy_remove_order_personal_data', $order );

		$r = ReservationRepository::find( $ids[0] );
		$this->assertNotNull( $r, 'satır kalır' );
		$this->assertSame( '', $r->customerName );
		$this->assertSame( '', $r->customerEmail );
		$this->assertSame( '', $r->customerPhone );
		$this->assertNull( $r->sessionHash );
		$this->assertNull( $r->ipHash );
	}

	public function test_admin_list_and_csv_handle_blank_customer_fields(): void {
		$ids = $this->make_bookings( 1, 'ayse@example.test' );
		Eraser::erase( 'ayse@example.test' );

		$csv = Csv::build( array() );
		$this->assertStringContainsString( ';;;', $csv, 'boş ad/telefon/e-posta hücreleri' );
		$this->assertStringContainsString( '2026-12-01;10:00;', $csv );
		$this->assertStringNotContainsString( 'ayse@example.test', $csv );

		$data = RestController::dto( ReservationRepository::find( $ids[0] ) );
		$this->assertSame(
			array(
				'name'  => '',
				'phone' => '',
				'email' => '',
			),
			$data['customer']
		);
		$this->assertSame( 'confirmed', $data['status'] );
	}

	/**
	 * Kişisel alanı tamamen boş satır sayısı.
	 *
	 * @param list<int> $ids Kimlikler.
	 */
	private function count_blank( array $ids ): int { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$n = 0;
		foreach ( $ids as $id ) {
			$r = $this->raw_row( $id );
			if ( '' === $r['customer_name'] && '' === $r['customer_email'] && '' === $r['customer_phone'] ) {
				++$n;
			}
		}
		return $n;
	}
}
