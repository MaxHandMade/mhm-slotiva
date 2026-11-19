<?php
/**
 * R3: 1.0.3 kapasite-1 müsaitlik yanıtının ham JSON gövdesini kaydeder/karşılaştırır.
 *
 * Kayıt: SLOTIVA_RECORD_FIXTURE=1 ile çalıştırılır (yalnız 1.0.3 davranışındaki kodda). Aksi hâlde güncel yanıt dosyayla
 * bayt bayt eşit olmalı; böylece motor değişiklikleri (kapasite-1'de) müsaitliği DEĞİŞTİREMEZ.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Availability;

use MHM\Slotiva\Tests\Support\AvailabilityFixtureSeed;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class Cap1FixtureRecorderTest extends SlotivaTestCase {

	private const FILE = __DIR__ . '/../fixtures/availability-cap1-1.0.3.json';

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
	}

	public function tear_down(): void {
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	/** Ham JSON gövdesi (deterministik kodlama). */
	private function body(): string {
		$pid = AvailabilityFixtureSeed::seed();
		$req = new \WP_REST_Request( 'GET', '/slotiva/v1/availability' );
		$req->set_query_params(
			array(
				'product' => $pid,
				'from'    => AvailabilityFixtureSeed::TARGET_DAY,
				'to'      => AvailabilityFixtureSeed::RANGE_TO,
				'day'     => AvailabilityFixtureSeed::TARGET_DAY,
			)
		);
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		return (string) wp_json_encode( $res->get_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	public function test_cap1_availability_matches_recorded_1_0_3_fixture(): void {
		$body = $this->body();
		if ( getenv( 'SLOTIVA_RECORD_FIXTURE' ) ) {
			file_put_contents( self::FILE, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test kaydı.
			$this->markTestSkipped( 'Fixture recorded: ' . self::FILE );
		}
		$this->assertFileExists( self::FILE );
		$this->assertSame( file_get_contents( self::FILE ), $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- yerel fikstür.
	}

	public function test_fixture_exercises_blocking_states(): void {
		$data  = json_decode( $this->body(), true );
		$times = array_column( $data['slots'], 'label' );
		$this->assertNotContains( '10:00', $times, 'held engellemeli' );
		$this->assertNotContains( '14:00', $times, 'confirmed engellemeli' );
		$this->assertContains( '11:15', $times, 'iptal edilen (11:00-12:00) engellememeli' );
		$this->assertNotEmpty( $times );
	}
}
