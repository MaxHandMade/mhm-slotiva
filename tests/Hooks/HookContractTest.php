<?php
/**
 * Task 14: tarafsız genişletme noktalarının sözleşme testleri (D6). Kanca adı, bağımsız değişken sayısı/türü ve
 * `docs/hooks.md` ile kaynak kodun birebir uyumu.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Hooks;

use MHM\Slotiva\Availability\RestController as AvailabilityRest;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Product\ProductTab;
use MHM\Slotiva\Reservation\Capacity;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class HookContractTest extends SlotivaTestCase {

	/**
	 * Yakalanan çağrılar: [kanca => list<args>].
	 *
	 * @var array<string,list<array<int,mixed>>>
	 */
	private array $calls = array();

	public function set_up(): void {
		parent::set_up();
		$this->calls = array();
	}

	public function tear_down(): void {
		$_POST = array();
		remove_all_filters( 'slotiva_availability_slot' );
		remove_all_filters( 'slotiva_product_capacity' );
		remove_all_actions( 'slotiva_product_tab_fields' );
		remove_all_actions( 'slotiva_product_tab_save' );
		remove_all_actions( 'slotiva_after_booking_saved' );
		parent::tear_down();
	}

	/**
	 * Kancayı tüm bağımsız değişkenleriyle dinler.
	 *
	 * @param string $hook Kanca.
	 */
	private function spy( string $hook ): void {
		add_action(
			$hook,
			function ( ...$args ) use ( $hook ) {
				$this->calls[ $hook ][] = $args;
			},
			10,
			10
		);
	}

	private static function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Geçerli bir claim isteği.
	 *
	 * @param array<string,mixed> $over Ezmeler.
	 * @return array<string,mixed>
	 */
	private function req( array $over = array() ): array {
		return array_merge(
			array(
				'product_id'      => 11,
				'variation_id'    => 0,
				'order_id'        => 801,
				'order_item_id'   => 7,
				'slots'           => array( self::utc( '2026-11-17 07:00:00' ) ),
				'duration'        => 30,
				'buffer'          => 0,
				'status'          => 'confirmed',
				'payment_state'   => 'paid',
				'hold_expires_at' => null,
				'customer'        => array(
					'name'  => 'Ayşe Test',
					'email' => 'ayse@example.test',
					'phone' => '5550000000',
				),
				'location'        => 'Atölye',
			),
			$over
		);
	}

	/**
	 * Kaynakta bulunan sabit kanca adları: süzgeç/eylem çağrıları + COMMIT sonrası kuyruğa alınanlar.
	 *
	 * @return list<string>
	 */
	private function source_hooks(): array {
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 2 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			if ( preg_match_all( '/(?:apply_filters|do_action|\$pending\[\] = array)\(\s*\'(slotiva_[a-z_]+)\'/', $code, $m ) ) {
				$found = array_merge( $found, $m[1] );
			}
		}
		$found = array_values( array_unique( $found ) );
		sort( $found );
		return $found;
	}

	public function test_hooks_doc_lists_exactly_the_hooks_fired_by_the_source(): void {
		$doc = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/hooks.md' );
		$this->assertNotSame( '', $doc, 'docs/hooks.md okunamadı' );
		$section = explode( '## Actions Slotiva listens to', $doc )[0];
		preg_match_all( '/^\| `(slotiva_[a-z_]+)` \|/m', $section, $m );
		$documented = array_values( array_unique( $m[1] ) );
		sort( $documented );
		$this->assertSame( $this->source_hooks(), $documented );
		foreach ( explode( "\n", $section ) as $row ) {
			if ( preg_match( '/^\| `slotiva_/', $row ) ) {
				$this->assertMatchesRegularExpression( '/\| 1\.\d\.\d \|$/', $row, 'her satırın "since" sütunu var' );
			}
		}
	}

	public function test_hooks_doc_uses_neutral_wording(): void {
		$doc = strtolower( (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/hooks.md' ) );
		$this->assertDoesNotMatchRegularExpression( '/\b(pro|license|licence|premium|upgrade|unlock\w*)\b/', $doc );
	}

	public function test_capacity_filter_passes_through_and_gets_documented_args(): void {
		$this->assertSame( 1, Capacity::forProduct( 11 ), 'hiçbir şey bağlı değilse 1' );
		$seen = array();
		add_filter(
			'slotiva_product_capacity',
			static function ( ...$a ) use ( &$seen ) {
				$seen = $a;
				return 99;
			},
			10,
			2
		);
		$this->assertSame( 50, Capacity::forProduct( 11 ), 'Lite 1–50 aralığına kırpar' );
		$this->assertSame( array( 1, 11 ), $seen );
	}

	public function test_availability_slot_filter_args_and_null_fallback(): void {
		$slots = array(
			array(
				'start'     => '2026-11-17T10:00:00+03:00',
				'label'     => '10:00',
				'seatsLeft' => 3,
			),
		);
		$plain = AvailabilityRest::slot_payload( $slots, 5, 11 );
		$this->assertSame(
			array(
				array(
					'start' => '2026-11-17T10:00:00+03:00',
					'label' => '10:00',
				),
			),
			$plain
		);

		$seen = array();
		add_filter(
			'slotiva_availability_slot',
			static function ( ...$a ) use ( &$seen ) {
				$seen = $a;
				return $a[0];
			},
			10,
			4
		);
		AvailabilityRest::slot_payload( $slots, 5, 11 );
		$this->assertSame( $plain[0], $seen[0] );
		$this->assertSame( 3, $seen[1] );
		$this->assertSame( 5, $seen[2] );
		$this->assertSame( 11, $seen[3] );
		remove_all_filters( 'slotiva_availability_slot' );

		add_filter( 'slotiva_availability_slot', '__return_null' );
		$this->assertSame( $plain, AvailabilityRest::slot_payload( $slots, 5, 11 ), 'null dönerse özgün dilim' );
		remove_all_filters( 'slotiva_availability_slot' );
		add_filter( 'slotiva_availability_slot', '__return_false' );
		$this->assertSame( $plain, AvailabilityRest::slot_payload( $slots, 5, 11 ), 'dizi olmayan dönerse özgün dilim' );
	}

	private function bookable_product(): \WC_Product_Simple {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Danışmanlık' );
		$p->set_regular_price( '100' );
		$p->update_meta_data( '_slotiva_enabled', 'yes' );
		$p->save();
		return $p;
	}

	public function test_product_tab_fields_fires_inside_panel_and_adds_no_markup_when_unhooked(): void {
		global $post;
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		$p    = $this->bookable_product();
		$post = get_post( $p->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WC alan yardımcısı $post okur.

		ob_start();
		ProductTab::render_panel();
		$plain = (string) ob_get_clean();

		$this->spy( 'slotiva_product_tab_fields' );
		add_action(
			'slotiva_product_tab_fields',
			static function () {
				echo '<i id="extra-field"></i>';
			}
		);
		ob_start();
		ProductTab::render_panel();
		$with = (string) ob_get_clean();

		$this->assertSame( array( array( $p->get_id() ) ), $this->calls['slotiva_product_tab_fields'] );
		$this->assertSame( $plain, str_replace( '<i id="extra-field"></i>', '', $with ), 'bağlı kanca yokken ek işaretleme yok' );
		$this->assertStringContainsString( '<i id="extra-field"></i></div>', $with, 'panelin içinde, Lite alanlarından hemen sonra' );
	}

	public function test_product_tab_save_fires_after_lite_checks_with_product_id(): void {
		$p = $this->bookable_product();
		$this->spy( 'slotiva_product_tab_save' );

		// Yetkisiz: Lite kendi denetiminde durur, kanca tetiklenmez.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ProductTab::save( $p->get_id() );
		$this->assertArrayNotHasKey( 'slotiva_product_tab_save', $this->calls );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array(
			'_slotiva_enabled'       => 'yes',
			'_slotiva_duration'      => '45',
			'_slotiva_min_slots'     => '1',
			'_slotiva_max_slots'     => '3',
			'_slotiva_pricing'       => 'per_slot',
			'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
		);
		$saved = null;
		add_action(
			'slotiva_product_tab_save',
			static function ( int $id ) use ( &$saved ) {
				$saved = (string) wc_get_product( $id )->get_meta( '_slotiva_duration' );
			}
		);
		ProductTab::save( $p->get_id() );
		$_POST = array();
		$this->assertSame( array( array( $p->get_id() ) ), $this->calls['slotiva_product_tab_save'] );
		$this->assertSame( '45', $saved, 'kanca Lite kaydından sonra çalışır' );
	}

	public function test_after_booking_saved_contexts_once_per_reservation(): void {
		$this->spy( 'slotiva_after_booking_saved' );

		$ids = ReservationRepository::claim( $this->req( array( 'slots' => array( self::utc( '2026-11-17 07:00:00' ), self::utc( '2026-11-17 09:00:00' ) ) ) ) );
		$this->assertSame( array( array( $ids[0], 'claim' ), array( $ids[1], 'claim' ) ), $this->calls['slotiva_after_booking_saved'] );

		$this->calls = array();
		$this->assertTrue( ReservationRepository::reschedule( $ids[0], self::utc( '2026-11-17 11:00:00' ), true, false ) );
		$this->assertSame( array( array( $ids[0], 'reschedule' ) ), $this->calls['slotiva_after_booking_saved'] );

		$this->calls = array();
		$this->assertTrue( ReservationRepository::setStatus( $ids[1], 'expired', null, array( 'reason' => 'test' ) ) );
		$this->assertSame( array( array( $ids[1], 'status' ) ), $this->calls['slotiva_after_booking_saved'] );

		$this->calls = array();
		$this->assertTrue( ReservationRepository::reclaim( $ids[1], array( 'reason' => 'late' ) ) );
		$this->assertSame( array( array( $ids[1], 'reclaim' ) ), $this->calls['slotiva_after_booking_saved'] );

		$this->calls = array();
		$new         = ReservationRepository::replaceForOrder( 801, $this->req( array( 'slots' => array( self::utc( '2026-11-18 07:00:00' ) ) ) ) );
		$got         = $this->calls['slotiva_after_booking_saved'];
		$this->assertCount( 3, $got, 'siparişin 2 satırı supersede + 1 yeni' );
		$this->assertSame( array( array( $new[0], 'claim' ) ), array_values( array_filter( $got, static fn( array $c ): bool => 'claim' === $c[1] ) ) );
		$this->assertCount( 2, array_filter( $got, static fn( array $c ): bool => 'replace' === $c[1] ) );
	}

	/**
	 * (Final review M10) Durum değişmese de `status` bağlamı tam bir kez tetiklenir; `slotiva_reservation_status` tetiklenmez.
	 */
	public function test_after_booking_saved_status_fires_once_on_a_no_op_status_write(): void {
		$ids = ReservationRepository::claim( $this->req() );
		$this->spy( 'slotiva_after_booking_saved' );
		$this->spy( 'slotiva_reservation_status' );
		$this->calls = array();
		$before      = ReservationRepository::find( $ids[0] );
		$this->assertTrue( ReservationRepository::setStatus( $ids[0], $before->status, null, array( 'reason' => 'noop' ) ) );
		$this->assertSame( array( array( $ids[0], 'status' ) ), $this->calls['slotiva_after_booking_saved'] );
		$this->assertArrayNotHasKey( 'slotiva_reservation_status', $this->calls, 'durum değişmedi: durum kancası yok' );
		$this->assertSame( $before->status, ReservationRepository::find( $ids[0] )->status );
	}

	/**
	 * (Final review M10) `slotiva_product_tab_save`: kapalı ürün ve doğrulama hatası yollarında tetiklenir (Lite alanlarından bağımsız);
	 * geçersiz nonce'ta tetiklenmez.
	 */
	public function test_product_tab_save_negative_and_rejected_paths(): void {
		$p = $this->bookable_product();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->spy( 'slotiva_product_tab_save' );

		// Geçersiz nonce: Lite yazmaz, kanca tetiklenmez.
		$_POST = array(
			'_slotiva_enabled'       => 'yes',
			'_slotiva_duration'      => '45',
			'woocommerce_meta_nonce' => 'bozuk',
		);
		ProductTab::save( $p->get_id() );
		$this->assertArrayNotHasKey( 'slotiva_product_tab_save', $this->calls );

		// Doğrulama hatası: Lite hiçbir şey yazmaz ama kanca bir kez tetiklenir.
		$_POST = array(
			'_slotiva_enabled'       => 'yes',
			'_slotiva_duration'      => '7',
			'_slotiva_min_slots'     => '1',
			'_slotiva_max_slots'     => '3',
			'_slotiva_pricing'       => 'per_slot',
			'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
		);
		ProductTab::save( $p->get_id() );
		$this->assertSame( array( array( $p->get_id() ) ), $this->calls['slotiva_product_tab_save'] );
		$this->assertNotSame( '7', (string) wc_get_product( $p->get_id() )->get_meta( '_slotiva_duration' ) );

		// Kapatma yolu: bir kez daha.
		$this->calls = array();
		$_POST       = array(
			'_slotiva_enabled'       => 'no',
			'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
		);
		ProductTab::save( $p->get_id() );
		$this->assertSame( array( array( $p->get_id() ) ), $this->calls['slotiva_product_tab_save'] );
	}

	public function test_after_booking_saved_never_fires_on_rollback_and_runs_after_commit(): void {
		global $wpdb;
		ReservationRepository::claim( $this->req() );
		$this->spy( 'slotiva_after_booking_saved' );
		$this->calls = array();
		try {
			ReservationRepository::claim( $this->req( array( 'order_id' => 802 ) ) );
			$this->fail( 'çakışma bekleniyordu' );
		} catch ( SlotTakenException $e ) {
			unset( $e );
		}
		$this->assertArrayNotHasKey( 'slotiva_after_booking_saved', $this->calls, 'geri alınan işlem olay üretmez' );

		// Kanca çalışırken satır görünür (COMMIT sonrası).
		$visible = null;
		add_action(
			'slotiva_after_booking_saved',
			static function ( int $id ) use ( &$visible, $wpdb ) {
				$table   = Schema::table( 'reservations' );
				$visible = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test.
			}
		);
		ReservationRepository::claim(
			$this->req(
				array(
					'order_id' => 803,
					'slots'    => array( self::utc( '2026-11-17 15:00:00' ) ),
				)
			)
		);
		$this->assertSame( 1, $visible );
	}
}
