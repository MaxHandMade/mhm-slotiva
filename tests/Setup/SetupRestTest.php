<?php
/**
 * `GET slotiva/v1/admin/setup` (1.0.2).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Setup;

use MHM\Slotiva\Setup\SetupRest;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class SetupRestTest extends SlotivaTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		parent::tear_down();
	}

	private function fetch(): \WP_REST_Response {
		return rest_do_request( new \WP_REST_Request( 'GET', '/slotiva/v1/admin/setup' ) );
	}

	private function simple( string $name, bool $bookable ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( '50' );
		if ( $bookable ) {
			$p->update_meta_data( '_slotiva_enabled', 'yes' );
			$p->update_meta_data( '_slotiva_duration', '45' );
			$p->update_meta_data( '_slotiva_pricing', 'per_slot' );
			$p->update_meta_data( '_slotiva_min_slots', '1' );
			$p->update_meta_data( '_slotiva_max_slots', '3' );
		}
		return $p->save();
	}

	public function test_get_setup_requires_manage_woocommerce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->fetch()->get_status() );
	}

	public function test_setup_summary_carries_the_week_for_the_checklist(): void {
		$s = SetupRest::setupSummary();
		$this->assertSame( array( 'complete', 'dismissed', 'checks', 'schedule' ), array_keys( $s ) );
		$this->assertCount( 7, $s['schedule']['days'] );
	}

	public function test_get_setup_shape(): void {
		$bookable = $this->simple( 'Seans', true );
		$plain    = $this->simple( 'Kitap', false );
		$grouped  = new \WC_Product_Grouped();
		$grouped->set_name( 'Grup' );
		$grouped_id = $grouped->save();

		$res = $this->fetch();
		$this->assertSame( 200, $res->get_status() );
		$d = $res->get_data();
		$this->assertSame(
			array( 'complete', 'dismissed', 'checks', 'products', 'candidates', 'currency', 'rules', 'schedule' ),
			array_keys( $d )
		);
		$this->assertSame( array( 'hours', 'checkout', 'product', 'timezone', 'payment' ), array_keys( $d['checks'] ) );
		$this->assertCount( 7, $d['schedule']['days'] );
		$this->assertArrayHasKey( 'durationDefault', $d['rules'] );
		$this->assertSame(
			array( 'symbol', 'decimals', 'decimalSep', 'thousandSep', 'position' ),
			array_keys( $d['currency'] )
		);

		$this->assertCount( 1, $d['products'] );
		$p = $d['products'][0];
		$this->assertSame( $bookable, $p['id'] );
		$this->assertSame( 45, $p['durationMin'] );
		$this->assertSame( 3, $p['maxSlots'] );
		$this->assertSame( 'per_slot', $p['pricing'] );
		$this->assertSame( 'publish', $p['status'] );
		$this->assertSame( 'simple', $p['type'] );
		foreach ( array( 'name', 'minSlots', 'editUrl', 'permalink', 'price' ) as $k ) {
			$this->assertArrayHasKey( $k, $p );
		}

		$cand_ids = array_column( $d['candidates'], 'id' );
		$this->assertContains( $plain, $cand_ids );
		$this->assertNotContains( $grouped_id, $cand_ids );
		$this->assertNotContains( $bookable, $cand_ids );
		$cand = $d['candidates'][ array_search( $plain, $cand_ids, true ) ];
		$this->assertSame( 'simple', $cand['type'] );
		// Seçicide yayımlanmamış ürünün durumu gösterilir ("Bu ürün yayınlanacak.").
		$this->assertSame( 'publish', $cand['status'] );
	}
}
