<?php
/**
 * 1.0.3 (şema '2') ve ara Lite derlemesi (şema '3') sitelerinden yerinde yükseltme: `capacity` sütunu.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Db;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Lifecycle\OrphanJobs;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class UpgradeFrom103Test extends SlotivaTestCase {

	/**
	 * Tabloyu 1.0.3 şekline indirir (sütun düşür; DROP/CREATE değil: test kütüphanesi onları geçici tabloya çevirir),
	 * birkaç satır ekler ve verilen sürümü yazar.
	 *
	 * @param string $version Yazılacak `slotiva_db_version`.
	 */
	private function downgrade_to( string $version ): void {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$wpdb->query( "ALTER TABLE {$res} DROP COLUMN capacity" ); // phpcs:ignore WordPress.DB -- test DDL.
		$this->assertStringContainsString( 'capacity', (string) Schema::verify() );
		$statuses = array( 'held', 'confirmed', 'cancelled' );
		foreach ( $statuses as $i => $status ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$res} (product_id, starts_at, ends_at, status, token, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, NOW(), NOW())", 5 + $i, '2026-12-0' . ( $i + 1 ) . ' 09:00:00', '2026-12-0' . ( $i + 1 ) . ' 09:30:00', $status, str_repeat( (string) $i, 32 ) ) ); // phpcs:ignore WordPress.DB -- test verisi.
		}
		update_option( 'slotiva_db_version', $version );
	}

	/**
	 * Eski şema sürümleri.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function old_versions(): array {
		return array(
			'1.0.3 sitesi (2)'       => array( '2' ),
			'ara Lite derlemesi (3)' => array( '3' ),
		);
	}

	/**
	 * Yükseltme senaryosu.
	 *
	 * @dataProvider old_versions
	 * @param string $version Eski şema sürümü.
	 */
	public function test_upgrade_keeps_rows_and_defaults_capacity_to_one( string $version ): void {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$this->downgrade_to( $version );

		Schema::maybe_install();

		$this->assertSame( '4', get_option( 'slotiva_db_version' ) );
		$this->assertNull( Schema::verify() );
		$rows = $wpdb->get_results( "SELECT product_id, status, capacity FROM {$res} ORDER BY product_id", ARRAY_A ); // phpcs:ignore WordPress.DB -- test okuması.
		$this->assertCount( 3, $rows );
		foreach ( $rows as $i => $row ) {
			$this->assertSame( (string) ( 5 + $i ), (string) $row['product_id'] );
			$this->assertSame( '1', (string) $row['capacity'] );
		}
		$this->assertSame( array( 'held', 'confirmed', 'cancelled' ), array_column( $rows, 'status' ) );
	}

	/**
	 * Yükseltme senaryosu.
	 *
	 * @dataProvider old_versions
	 * @param string $version Eski şema sürümü.
	 */
	public function test_upgrade_still_queues_orphan_sweep( string $version ): void {
		$this->downgrade_to( $version );
		remove_all_actions( 'init' );

		Schema::maybe_install();

		$this->assertNotFalse( has_action( 'init', array( OrphanJobs::class, 'sweep' ) ) );
	}
}
