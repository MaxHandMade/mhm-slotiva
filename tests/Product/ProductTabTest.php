<?php
/**
 * Ürün sekmesi kayıt sınırları.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Product;

use MHM\Slotiva\Product\ProductTab;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class ProductTabTest extends SlotivaTestCase {

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	public function test_save_rejects_duration_above_max(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$p = new \WC_Product_Simple();
		$p->set_name( 'Danışmanlık' );
		$p->set_regular_price( '100' );
		$p->update_meta_data( '_slotiva_enabled', 'yes' );
		$p->update_meta_data( '_slotiva_duration', '60' );
		$p->save();
		$id = $p->get_id();

		$_POST = array(
			'_slotiva_enabled'       => 'yes',
			'_slotiva_duration'      => '495',
			'_slotiva_min_slots'     => '1',
			'_slotiva_max_slots'     => '3',
			'_slotiva_pricing'       => 'per_slot',
			'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ),
		);
		ProductTab::save( $id );
		$this->assertSame( '60', (string) wc_get_product( $id )->get_meta( '_slotiva_duration' ) );
		$this->assertContains(
			'Slotiva: the slot length must be a multiple of 15 minutes (15 to 480).',
			\WC_Admin_Meta_Boxes::$meta_box_errors
		);

		$_POST['_slotiva_duration'] = '480';
		ProductTab::save( $id );
		$this->assertSame( '480', (string) wc_get_product( $id )->get_meta( '_slotiva_duration' ) );
	}

	/**
	 * R26: kayıt yalnız geçerli `woocommerce_meta_nonce` (`woocommerce_save_data`) ile yazar; eksik ya da bozuk nonce'ta
	 * hiçbir alan değişmez ve ölümcül hata yoktur.
	 */
	public function test_save_without_valid_nonce_writes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$p = new \WC_Product_Simple();
		$p->set_name( 'Danışmanlık' );
		$p->set_regular_price( '100' );
		$p->update_meta_data( '_slotiva_enabled', 'yes' );
		$p->update_meta_data( '_slotiva_duration', '60' );
		$p->save();
		$id = $p->get_id();

		foreach ( array( null, 'bozuk' ) as $nonce ) {
			$_POST = array(
				'_slotiva_enabled'   => 'no',
				'_slotiva_duration'  => '120',
				'_slotiva_min_slots' => '1',
				'_slotiva_max_slots' => '1',
				'_slotiva_pricing'   => 'per_slot',
			);
			if ( null !== $nonce ) {
				$_POST['woocommerce_meta_nonce'] = $nonce;
			}
			ProductTab::save( $id );
			$product = wc_get_product( $id );
			$this->assertSame( 'yes', (string) $product->get_meta( '_slotiva_enabled' ), 'nonce yok/bozuk: kapatma yazılmadı' );
			$this->assertSame( '60', (string) $product->get_meta( '_slotiva_duration' ) );
		}
	}

	/**
	 * (Final review M5) "Sekmeyi aç" betiği WC'nin ürün meta kutusu betiğine bağlı satır içi betiktir; yalnız ürün ekranında eklenir.
	 */
	public function test_open_tab_script_is_inline_on_wc_handle_only_on_product_screen(): void {
		$handle = 'wc-admin-product-meta-boxes';
		// Küresel betik kaydı önceki testlerden kalıntı taşıyabilir (WC bu tutamağa satır içi veri eklemiş olabilir): kendi durumumuz.
		$saved                 = $GLOBALS['wp_scripts'] ?? null;
		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test kendi küresel kaydını kurar ve geri koyar.
		try {
			wp_register_script( $handle, '', array(), '1', true ); // WC'nin kaydını taklit eder (10'da kaydolur).
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

			set_current_screen( 'edit-page' );
			ProductTab::enqueue_open_script( 'edit.php' );
			$this->assertEmpty( wp_scripts()->get_data( $handle, 'after' ), 'ürün olmayan ekranda eklenmez' );

			set_current_screen( 'product' );
			ProductTab::enqueue_open_script( 'post.php' );
			$after = (string) implode( '', (array) wp_scripts()->get_data( $handle, 'after' ) );
			$this->assertStringContainsString( '#slotiva_product_data', $after );
			$this->assertStringContainsString( "addEventListener('load'", $after );
		} finally {
			set_current_screen( 'front' );
			$GLOBALS['wp_scripts'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test kendi küresel kaydını kurar ve geri koyar.
		}
	}

	/**
	 * (Final review M6) Sekme etiketi ham metindir: WC `esc_html` ile kendisi kaçışlar.
	 */
	public function test_tab_label_is_not_pre_escaped(): void {
		$tabs = ProductTab::add_tab( array() );
		$this->assertSame( __( 'Slotiva', 'mhm-slotiva' ), $tabs['slotiva']['label'] );
	}
}
