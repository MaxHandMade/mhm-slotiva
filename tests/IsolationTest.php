<?php
/**
 * SlotivaTestCase yalıtımı: önceki testin kalıcılaştırdığı terim/yorum/seçenek/transient sonraki teste sızmamalı.
 * İki test sırayla çalışır (PHPUnit dosya sırası): önce yazar, sonra yokluğu doğrular.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests;

use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class IsolationTest extends SlotivaTestCase {

	public function test_1_leaves_committed_leftovers(): void {
		global $wpdb;
		$term = wp_insert_term( 'Slotiva Probe Category', 'product_cat' );
		$this->assertIsArray( $term );
		$post_id = self::factory()->post->create();
		wp_insert_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'slotiva-probe-comment',
			)
		);
		update_option( 'woocommerce_slotiva_probe', 'x' );
		set_transient( 'slotiva_probe_transient', 'y', 600 );
		// Depo davranışını taklit et: açık işlemi COMMIT et; kütüphanenin ROLLBACK'i artık geri almaz.
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- sızıntı denetimi.
		$this->assertSame( 'x', get_option( 'woocommerce_slotiva_probe' ) );
	}

	public function test_2_leftovers_are_gone(): void {
		global $wpdb;
		$this->assertNull( term_exists( 'Slotiva Probe Category', 'product_cat' ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_content = 'slotiva-probe-comment'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- sızıntı denetimi.
		$this->assertFalse( get_option( 'woocommerce_slotiva_probe' ) );
		$this->assertFalse( get_transient( 'slotiva_probe_transient' ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%slotiva\_probe%'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- sızıntı denetimi.
	}
}
