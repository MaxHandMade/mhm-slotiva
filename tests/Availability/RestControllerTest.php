<?php
/**
 * Müsaitlik REST ucu testleri.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Availability;

use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class RestControllerTest extends SlotivaTestCase {

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
				'horizonDays' => 90,
				'bufferMin'   => 0,
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 254 );

		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
	}

	public function tear_down(): void {
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		unset( $_SERVER['REMOTE_ADDR'] );
		update_option( 'timezone_string', $this->tz_backup['timezone_string'] );
		update_option( 'gmt_offset', $this->tz_backup['gmt_offset'] );
		parent::tear_down();
	}

	private function make_product( bool $enabled = true, string $status = 'publish' ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Danışmanlık' );
		$p->set_regular_price( '100' );
		$p->set_status( $status );
		if ( $enabled ) {
			$p->update_meta_data( '_slotiva_enabled', 'yes' );
			$p->update_meta_data( '_slotiva_duration', '30' );
			$p->update_meta_data( '_slotiva_horizon_days', '90' );
		}
		return $p->save();
	}

	/**
	 * İstek yollar.
	 *
	 * @param array<string,mixed> $params Sorgu.
	 */
	private function get( array $params ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'GET', '/slotiva/v1/availability' );
		$req->set_query_params( $params );
		return rest_do_request( $req );
	}

	public function test_returns_days_and_slots(): void {
		$id  = $this->make_product();
		$res = $this->get(
			array(
				'product' => $id,
				'day'     => '2026-11-17',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'Europe/Istanbul', $data['timezone'] );
		$this->assertStringContainsString( 'UTC+03:00', $data['tz_label'] );
		$this->assertSame( '2026-11-17', $data['days'][0]['date'] );
		$this->assertTrue( $data['days'][0]['open'] );
		$this->assertSame( 4, $data['days'][0]['free'] );
		$this->assertSame( array( '10:00', '10:30', '11:00', '11:30' ), array_column( $data['slots'], 'label' ) );
		$this->assertSame( '2026-11-17T07:00:00Z', $data['slots'][0]['start'] );
	}

	public function test_range_clamped_to_horizon(): void {
		$id  = $this->make_product();
		$res = $this->get(
			array(
				'product' => $id,
				'from'    => '2026-11-16',
				'to'      => '2027-12-20',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 62, $res->get_data()['days'] );
	}

	public function test_past_date_400(): void {
		$id = $this->make_product();
		$this->assertSame(
			400,
			$this->get(
				array(
					'product' => $id,
					'day'     => '2026-11-15',
				)
			)->get_status()
		);
		$this->assertSame(
			400,
			$this->get(
				array(
					'product' => $id,
					'from'    => '2026-11-01',
					'to'      => '2026-11-20',
				)
			)->get_status()
		);
	}

	public function test_non_bookable_404(): void {
		$id = $this->make_product( false );
		$this->assertSame( 404, $this->get( array( 'product' => $id ) )->get_status() );
		$this->assertSame( 404, $this->get( array( 'product' => 99999999 ) )->get_status() );
	}

	public function test_unpublished_product_404(): void {
		foreach ( array( 'draft', 'private', 'pending' ) as $status ) {
			$id = $this->make_product( true, $status );
			$this->assertSame( 404, $this->get( array( 'product' => $id ) )->get_status(), $status );
		}
	}

	public function test_rate_limited_429(): void {
		$id = $this->make_product();
		for ( $i = 1; $i <= 60; $i++ ) {
			$this->assertSame( 200, $this->get( array( 'product' => $id ) )->get_status(), "istek $i" );
		}
		$this->assertSame( 429, $this->get( array( 'product' => $id ) )->get_status() );
		// X-Forwarded-For sayacı etkilemez.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		$this->assertSame( 429, $this->get( array( 'product' => $id ) )->get_status() );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_no_pii_in_response(): void {
		$id   = $this->make_product();
		$data = $this->get(
			array(
				'product' => $id,
				'day'     => '2026-11-17',
			)
		)->get_data();
		$keys = array_keys( $data );
		sort( $keys );
		$this->assertSame( array( 'days', 'slots', 'timezone', 'tz_label' ), $keys );
		$this->assertNotEmpty( $data['days'] );
		$this->assertNotEmpty( $data['slots'] );
		foreach ( $data['days'] as $d ) {
			$k = array_keys( $d );
			sort( $k );
			$this->assertSame( array( 'date', 'free', 'open' ), $k );
		}
		foreach ( $data['slots'] as $s ) {
			$k = array_keys( $s );
			sort( $k );
			$this->assertSame( array( 'label', 'start' ), $k );
		}
	}

	public function test_cache_invalidated_on_claim(): void {
		$id     = $this->make_product();
		$params = array(
			'product' => $id,
			'day'     => '2026-11-17',
		);
		$before = $this->get( $params )->get_data();
		$this->assertSame( 4, $before['days'][0]['free'] );
		// Aynı istek önbellekten gelir (ikinci kez aynı).
		$this->assertSame( $before, $this->get( $params )->get_data() );

		ReservationRepository::claim(
			array(
				'product_id' => $id,
				'slots'      => array( new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ),
				'duration'   => 30,
				'buffer'     => 0,
				'status'     => 'confirmed',
			)
		);

		$after = $this->get( $params )->get_data();
		$this->assertSame( 3, $after['days'][0]['free'] );
		$this->assertSame( array( '10:30', '11:00', '11:30' ), array_column( $after['slots'], 'label' ) );
	}

	public function test_variation_id_as_product_404(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Değişken' );
		$parent->set_status( 'draft' );
		$parent->update_meta_data( '_slotiva_enabled', 'yes' );
		$parent->update_meta_data( '_slotiva_duration', '30' );
		$pid = $parent->save();
		$var = new \WC_Product_Variation();
		$var->set_parent_id( $pid );
		$var->set_regular_price( '10' );
		$vid = $var->save();
		$this->assertSame( 404, $this->get( array( 'product' => $vid ) )->get_status() );
		$this->assertSame(
			404,
			$this->get(
				array(
					'product'   => $pid,
					'variation' => $vid,
				)
			)->get_status()
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
	}

	public function test_variation_checks(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Değişken' );
		$parent->set_status( 'publish' );
		$parent->update_meta_data( '_slotiva_enabled', 'yes' );
		$parent->update_meta_data( '_slotiva_duration', '30' );
		$pid = $parent->save();
		$var = new \WC_Product_Variation();
		$var->set_parent_id( $pid );
		$var->set_regular_price( '10' );
		$var->set_status( 'publish' );
		$vid = $var->save();
		$this->assertSame(
			200,
			$this->get(
				array(
					'product'   => $pid,
					'variation' => $vid,
				)
			)->get_status()
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		// Başka ürünün varyasyonu.
		$other = $this->make_product();
		$this->assertSame(
			404,
			$this->get(
				array(
					'product'   => $other,
					'variation' => $vid,
				)
			)->get_status()
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment

		// Devre dışı (private) varyasyon.
		wp_update_post(
			array(
				'ID'          => $vid,
				'post_status' => 'private',
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame(
			404,
			$this->get(
				array(
					'product'   => $pid,
					'variation' => $vid,
				)
			)->get_status()
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
	}

	public function test_password_protected_product_404(): void {
		$id = $this->make_product();
		wp_update_post(
			array(
				'ID'            => $id,
				'post_password' => 'gizli',
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( 404, $this->get( array( 'product' => $id ) )->get_status() );
	}

	public function test_beyond_horizon_not_cached_and_empty(): void {
		$id  = $this->make_product();
		$res = $this->get(
			array(
				'product' => $id,
				'day'     => '2030-01-01',
			)
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array(), $res->get_data()['slots'] );
		$this->assertSame( array(), $res->get_data()['days'] );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_slotiva\_avail\_" . $id . "\_%'" ) ); // phpcs:ignore WordPress.DB
	}
}
