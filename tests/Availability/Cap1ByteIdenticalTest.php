<?php
/**
 * Task 12 (K8): kapasite 1 üründe 1.1.0 müsaitlik yanıtı, kaydedilmiş 1.0.3 fikstürüyle bayt bayt aynı.
 *
 * Fikstür Task 8'de 1.0.3 kodunda kaydedildi (`Cap1FixtureRecorderTest`); burada YENİDEN KAYDEDİLMEZ.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Availability;

use MHM\Slotiva\Tests\Support\AvailabilityFixtureSeed;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class Cap1ByteIdenticalTest extends SlotivaTestCase {

	private const FILE = __DIR__ . '/../fixtures/availability-cap1-1.0.3.json';

	public function set_up(): void {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.78';
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
	}

	public function tear_down(): void {
		remove_filter( 'slotiva_product_capacity', array( $this, 'one' ), 10 );
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	/** Açık kapasite 1 süzgeci. */
	public function one(): int {
		return 1;
	}

	/** Tohumlanmış günün ham JSON gövdesi (kaydedicideki kodlamayla). */
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

	public function test_default_capacity_response_equals_recorded_1_0_3_bytes(): void {
		$this->assertFileExists( self::FILE );
		$this->assertSame( file_get_contents( self::FILE ), $this->body() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- yerel fikstür.
	}

	public function test_explicit_capacity_1_response_equals_recorded_1_0_3_bytes(): void {
		add_filter( 'slotiva_product_capacity', array( $this, 'one' ), 10, 0 );
		$this->assertSame( file_get_contents( self::FILE ), $this->body() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- yerel fikstür.
	}
}
