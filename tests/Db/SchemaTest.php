<?php
/**
 * Veritabanı şeması testleri.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Db;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class SchemaTest extends SlotivaTestCase {

	public function test_tables_created_innodb(): void {
		global $wpdb;
		foreach ( array( 'reservations', 'occupancy', 'exceptions', 'events' ) as $short ) {
			$table = Schema::table( $short );
			$this->assertSame( $wpdb->prefix . 'slotiva_' . $short, $table );
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- şema denetimi.
			$this->assertNotNull( $status, "{$table} yok" );
			$this->assertSame( 'InnoDB', $status->Engine, "{$table} InnoDB değil" ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL sütun adı.
		}
	}

	public function test_unknown_short_name_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		Schema::table( 'nope' );
	}

	public function test_occupancy_unique_key(): void {
		global $wpdb;
		$table = Schema::table( 'occupancy' );
		$row   = array(
			'resource_id'    => 1,
			'unit_start'     => '2026-12-01 09:00:00',
			'seat'           => 1,
			'reservation_id' => 10,
		);
		$wpdb->suppress_errors( true );
		$this->assertSame( 1, $wpdb->insert( $table, $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tablo adı beyaz listeden.
		$row['reservation_id'] = 11;
		$this->assertFalse( $wpdb->insert( $table, $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- tablo adı beyaz listeden.
		$this->assertStringContainsString( 'Duplicate entry', $wpdb->last_error );
		$wpdb->suppress_errors( false );
	}

	public function test_exceptions_columns_named_from_time_to_time(): void {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::table( 'exceptions' ), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- tablo adı Schema::table() beyaz listesinden.
		$this->assertContains( 'from_time', $columns );
		$this->assertContains( 'to_time', $columns );
		$this->assertNotContains( 'from', $columns );
		$this->assertNotContains( 'to', $columns );
	}

	public function test_reservations_has_spec_columns_and_sequence(): void {
		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::table( 'reservations' ), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- tablo adı Schema::table() beyaz listesinden.
		foreach ( array( 'id', 'resource_id', 'product_id', 'variation_id', 'order_id', 'order_item_id', 'starts_at', 'ends_at', 'status', 'payment_state', 'hold_expires_at', 'customer_name', 'customer_email', 'customer_phone', 'location_text', 'reschedule_count', 'token', 'sequence', 'created_at', 'updated_at' ) as $name ) {
			$this->assertContains( $name, $columns, "reservations.{$name} yok" );
		}
	}

	public function test_install_idempotent(): void {
		$this->assertTrue( Schema::install() );
		$this->assertTrue( Schema::install() );
		$this->assertSame( '', $GLOBALS['wpdb']->last_error );
		$this->assertSame( '4', get_option( 'slotiva_db_version' ) );
		$this->assertSame( '4', Schema::VERSION );
		$this->assertNull( Schema::verify() );
	}

	public function test_occupancy_unique_key_is_named_slotiva_unit(): void {
		global $wpdb;
		$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::table( 'occupancy' ) . " WHERE Key_name = 'slotiva_unit'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- tablo adı Schema'dan.
		$this->assertSame( array( 'resource_id', 'unit_start', 'seat' ), array_column( $rows, 'Column_name' ) );
		$this->assertSame( array( '0', '0', '0' ), array_map( 'strval', array_column( $rows, 'Non_unique' ) ) );
		$legacy = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::table( 'occupancy' ) . " WHERE Key_name = 'unit_seat'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- tablo adı Schema'dan.
		$this->assertSame( array(), $legacy );
	}

	public function test_reservations_v2_columns_and_indexes(): void {
		global $wpdb;
		$cols = array();
		foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . Schema::table( 'reservations' ), ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- tablo adı Schema'dan.
			$cols[ $c['Field'] ] = $c;
		}
		$this->assertSame( 'NO', $cols['product_name']['Null'] );
		$this->assertSame( 'char(32)', strtolower( $cols['session_hash']['Type'] ) );
		$this->assertSame( 'YES', $cols['session_hash']['Null'] );
		$this->assertSame( 'char(32)', strtolower( $cols['ip_hash']['Type'] ) );
		$keys = array_column( (array) $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::table( 'reservations' ), ARRAY_A ), 'Key_name' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- tablo adı Schema'dan.
		$this->assertContains( 'ip_hash', $keys );
		$this->assertContains( 'session_hash', $keys );
	}

	public function test_upgrade_from_v1_preserves_rows(): void {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$occ = Schema::table( 'occupancy' );
		// Gerçek v1 şekline indir (DROP/CREATE değil: test kütüphanesi onları geçici tabloya çevirir).
		$wpdb->query( "ALTER TABLE {$res} DROP INDEX session_hash, DROP INDEX ip_hash, DROP COLUMN product_name, DROP COLUMN session_hash, DROP COLUMN ip_hash" ); // phpcs:ignore WordPress.DB -- test DDL.
		$wpdb->query( "ALTER TABLE {$occ} RENAME INDEX slotiva_unit TO unit_seat" ); // phpcs:ignore WordPress.DB -- test DDL.
		$this->assertNotNull( Schema::verify() );
		$wpdb->query( "INSERT INTO {$res} (product_id, starts_at, ends_at, token, created_at, updated_at) VALUES (5, '2026-12-01 09:00:00', '2026-12-01 09:30:00', REPEAT('a', 32), NOW(), NOW())" ); // phpcs:ignore WordPress.DB -- test verisi.
		$wpdb->query( "INSERT INTO {$occ} (resource_id, unit_start, seat, reservation_id) VALUES (1, '2026-12-01 09:00:00', 1, 1)" ); // phpcs:ignore WordPress.DB -- test verisi.
		update_option( 'slotiva_db_version', '1' );

		Schema::maybe_install();

		$this->assertSame( '4', get_option( 'slotiva_db_version' ) );
		$this->assertNull( Schema::verify() );
		$row = $wpdb->get_row( "SELECT * FROM {$res}", ARRAY_A ); // phpcs:ignore WordPress.DB -- test okuması.
		$this->assertSame( '5', (string) $row['product_id'] );
		$this->assertSame( '', $row['product_name'] );
		$this->assertNull( $row['session_hash'] );
		$this->assertNull( $row['ip_hash'] );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$occ}" ) ); // phpcs:ignore WordPress.DB -- test okuması.
		$this->test_occupancy_unique_key_is_named_slotiva_unit();
	}

	public function test_reservations_capacity_column_snapshot_default_one(): void {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$col = $wpdb->get_row( "SHOW COLUMNS FROM {$res} LIKE 'capacity'", ARRAY_A ); // phpcs:ignore WordPress.DB -- şema denetimi.
		$this->assertIsArray( $col, 'reservations.capacity yok' );
		$this->assertSame( 'smallint unsigned', strtolower( (string) preg_replace( '/\(\d+\)/', '', $col['Type'] ) ) );
		$this->assertSame( 'NO', $col['Null'] );
		$this->assertSame( '1', (string) $col['Default'] );
		$wpdb->query( "INSERT INTO {$res} (starts_at, ends_at, token, created_at, updated_at) VALUES ('2031-07-14 09:00:00', '2031-07-14 09:45:00', REPEAT('b', 32), NOW(), NOW())" ); // phpcs:ignore WordPress.DB -- test verisi.
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT capacity FROM {$res}" ) ); // phpcs:ignore WordPress.DB -- test okuması.
	}

	public function test_verify_reports_missing_capacity_column(): void {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$wpdb->query( "ALTER TABLE {$res} DROP COLUMN capacity" ); // phpcs:ignore WordPress.DB -- test DDL.
		$this->assertStringContainsString( 'capacity', (string) Schema::verify() );
		$this->assertTrue( Schema::install() );
		$this->assertNull( Schema::verify() );
	}

	public function test_install_does_not_write_version_when_verification_fails(): void {
		global $wpdb;
		$occ = Schema::table( 'occupancy' );
		$wpdb->query( "ALTER TABLE {$occ} DROP INDEX slotiva_unit" ); // phpcs:ignore WordPress.DB -- test DDL.
		delete_option( 'slotiva_db_version' );
		$log      = wp_tempnam( 'slotiva-schema-log' );
		$prev_log = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- günlüğü yakalamak için.
		$break    = static fn( string $q ): string => str_contains( $q, 'slotiva_unit' ) && str_starts_with( ltrim( $q ), 'ALTER' ) ? 'ALTER TABLE slotiva_no_such_table ADD x INT' : $q;
		add_filter( 'query', $break );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$this->assertFalse( Schema::install() );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $break );
			ini_set( 'error_log', (string) $prev_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- geri yükleme.
		}
		$this->assertFalse( get_option( 'slotiva_db_version' ) );
		$this->assertStringContainsString( 'slotiva_unit', (string) Schema::verify() );
		$logged = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test günlüğü.
		$this->assertStringContainsString( 'slotiva_unit', $logged );
		$this->assertStringContainsString( 'slotiva_no_such_table', $logged ); // $wpdb->last_error günlükte.
		unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test günlüğü.

		// Engel kalkınca kurulum kendini onarır.
		$this->assertTrue( Schema::install() );
		$this->assertSame( '4', get_option( 'slotiva_db_version' ) );
		$this->assertNull( Schema::verify() );
	}

	public function test_base_class_restores_options_snapshot(): void {
		update_option( 'slotiva_probe', 'x' );
		$this->assertSame( 'x', get_option( 'slotiva_probe' ) );
	}

	public function test_base_class_leaves_no_probe_option(): void {
		// Önceki test `slotiva_probe` yazdı; tear_down silmiş olmalı (sıra bağımlı değil: yoksa da geçer).
		$this->assertFalse( get_option( 'slotiva_probe' ) );
	}
}
