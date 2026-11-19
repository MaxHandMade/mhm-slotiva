<?php
/**
 * Support\Fresh::order — başka bir isteğin yaptığı değişiklik önbellekten değil veritabanından okunur.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Checkout;

use MHM\Slotiva\Support\Fresh;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery -- test: başka isteği taklit eden, önbelleği atlayan doğrudan yazım.

class FreshOrderTest extends SlotivaTestCase {

	/**
	 * Depolama kipleri.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public function storage_modes(): array {
		return array(
			'legacy' => array( false ),
			'hpos'   => array( true ),
		);
	}

	/**
	 * Başka isteğin yazdığı meta ve durum görülür.
	 *
	 * @dataProvider storage_modes
	 *
	 * @param bool $hpos HPOS açık mı.
	 */
	public function test_reads_meta_and_status_changed_behind_the_cache( bool $hpos ): void {
		$before = get_option( 'woocommerce_custom_orders_table_enabled' );
		if ( $hpos ) {
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		}
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos ? 'yes' : 'no' );
		try {
			$this->assert_fresh_read();
		} finally {
			update_option( 'woocommerce_custom_orders_table_enabled', $before );
		}
	}

	private function assert_fresh_read(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_slotiva_requested_slots', array( '2026-11-17T07:00:00Z' ) );
		$order->save();
		$id = $order->get_id();
		$this->assertSame( array( '2026-11-17T07:00:00Z' ), wc_get_order( $id )->get_meta( '_slotiva_requested_slots' ) );

		// Başka bir istek (ör. PayTR geri çağrısı) doğrudan veritabanına yazdı; bu sürecin önbelleği bunu bilmiyor.
		global $wpdb;
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$wpdb->update(
				$wpdb->prefix . 'wc_orders_meta',
				array( 'meta_value' => maybe_serialize( array( '2026-11-17T08:00:00Z' ) ) ),
				array(
					'order_id' => $id,
					'meta_key' => '_slotiva_requested_slots',
				)
			);
			$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'status' => 'wc-processing' ), array( 'id' => $id ) );
		} else {
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => maybe_serialize( array( '2026-11-17T08:00:00Z' ) ) ),
				array(
					'post_id'  => $id,
					'meta_key' => '_slotiva_requested_slots',
				)
			);
			$wpdb->update( $wpdb->posts, array( 'post_status' => 'wc-processing' ), array( 'ID' => $id ) );
		}

		$fresh = Fresh::order( $id );
		$this->assertNotNull( $fresh );
		$this->assertSame( array( '2026-11-17T08:00:00Z' ), $fresh->get_meta( '_slotiva_requested_slots' ) );
		$this->assertSame( 'processing', $fresh->get_status() );
		$fresh->delete( true );
	}

	public function test_missing_or_refund_is_null(): void {
		$this->assertNull( Fresh::order( 0 ) );
		$this->assertNull( Fresh::order( 987654 ) );
		$order = wc_create_order();
		$order->set_total( 10 );
		$order->save();
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 1,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertNull( Fresh::order( $refund->get_id() ) );
	}
}
