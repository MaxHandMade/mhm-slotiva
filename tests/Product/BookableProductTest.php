<?php
/**
 * Randevulu ürün + Slotiva sekmesi testleri.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Product;

use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Product\ProductTab;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- alan adları sözleşmeyle camelCase (spec §5).

class BookableProductTest extends SlotivaTestCase {

	/**
	 * Ürün oluşturur.
	 *
	 * @param array<string,string> $meta Ürün metası.
	 */
	private function make_product( array $meta = array() ): \WC_Product_Simple {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Danışmanlık' );
		$p->set_regular_price( '100' );
		foreach ( $meta as $k => $v ) {
			$p->update_meta_data( $k, $v );
		}
		$p->save();
		return $p;
	}

	/**
	 * Formu gönderir.
	 *
	 * @param int                 $id   Ürün kimliği.
	 * @param array<string,mixed> $post Gönderilen alanlar.
	 */
	private function submit( int $id, array $post ): void {
		$_POST = $post + array( 'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ) );
		ProductTab::save( $id );
		$_POST = array();
	}

	private function admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	public function test_non_bookable_returns_null(): void {
		$this->assertNull( BookableProduct::from( $this->make_product() ) );
		$this->assertNull( BookableProduct::from( $this->make_product( array( '_slotiva_enabled' => 'no' ) ) ) );
	}

	public function test_bookable_reads_meta(): void {
		$p = $this->make_product(
			array(
				'_slotiva_enabled'      => 'yes',
				'_slotiva_duration'     => '45',
				'_slotiva_min_slots'    => '1',
				'_slotiva_max_slots'    => '4',
				'_slotiva_pricing'      => 'per_slot',
				'_slotiva_location'     => 'Ofis',
				'_slotiva_lead_hours'   => '2',
				'_slotiva_horizon_days' => '30',
			)
		);
		$b = BookableProduct::from( $p );
		$this->assertInstanceOf( BookableProduct::class, $b );
		$this->assertSame( $p->get_id(), $b->productId );
		$this->assertSame( 45, $b->durationMin );
		$this->assertSame( 1, $b->minSlots );
		$this->assertSame( 4, $b->maxSlots );
		$this->assertSame( 'per_slot', $b->pricing );
		$this->assertSame( 'Ofis', $b->location );
		$this->assertSame( 2, $b->leadHours );
		$this->assertSame( 30, $b->horizonDays );
	}

	public function test_bookable_falls_back_to_rules(): void {
		Rules::save(
			array(
				'durationDefault' => 60,
				'leadHours'       => 12,
				'horizonDays'     => 21,
			)
		);
		$b = BookableProduct::from( $this->make_product( array( '_slotiva_enabled' => 'yes' ) ) );
		$this->assertSame( 60, $b->durationMin );
		$this->assertSame( 12, $b->leadHours );
		$this->assertSame( 21, $b->horizonDays );
		$this->assertSame( 1, $b->minSlots );
		$this->assertSame( 'per_slot', $b->pricing );
	}

	public function test_bookable_fixed_forces_one_slot_and_sold_individually(): void {
		$b = BookableProduct::from(
			$this->make_product(
				array(
					'_slotiva_enabled'   => 'yes',
					'_slotiva_pricing'   => 'fixed',
					'_slotiva_min_slots' => '2',
					'_slotiva_max_slots' => '5',
				)
			)
		);
		$this->assertSame( 1, $b->maxSlots );
		$this->assertSame( 1, $b->minSlots );

		$this->admin();
		$p = $this->make_product();
		$this->submit(
			$p->get_id(),
			array(
				'_slotiva_enabled'   => 'yes',
				'_slotiva_duration'  => '60',
				'_slotiva_min_slots' => '1',
				'_slotiva_max_slots' => '3',
				'_slotiva_pricing'   => 'fixed',
			)
		);
		$fresh = wc_get_product( $p->get_id() );
		$this->assertTrue( $fresh->is_sold_individually() );
		$this->assertSame( '1', (string) $fresh->get_meta( '_slotiva_max_slots' ) );
		$this->assertSame( 1, BookableProduct::from( $fresh )->maxSlots );
	}

	public function test_variation_inherits_parent_meta(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Paket' );
		$parent->update_meta_data( '_slotiva_enabled', 'yes' );
		$parent->update_meta_data( '_slotiva_duration', '90' );
		$parent->update_meta_data( '_slotiva_pricing', 'fixed' );
		$parent->save();
		$var = new \WC_Product_Variation();
		$var->set_parent_id( $parent->get_id() );
		$var->set_regular_price( '50' );
		$var->save();

		$b = BookableProduct::from( $var );
		$this->assertInstanceOf( BookableProduct::class, $b );
		$this->assertSame( $parent->get_id(), $b->productId );
		$this->assertSame( $var->get_id(), $b->variationId );
		$this->assertSame( 90, $b->durationMin );
	}

	public function test_policy_override_falls_back_to_rules(): void {
		Rules::save( array( 'policyText' => 'Genel kural' ) );
		$a = BookableProduct::from( $this->make_product( array( '_slotiva_enabled' => 'yes' ) ) );
		$this->assertSame( 'Genel kural', $a->policyText );
		$b = BookableProduct::from(
			$this->make_product(
				array(
					'_slotiva_enabled' => 'yes',
					'_slotiva_policy'  => 'Ürüne özel',
				)
			)
		);
		$this->assertSame( 'Ürüne özel', $b->policyText );
	}

	/**
	 * Geçerli form + değişiklik.
	 *
	 * @param array<string,string> $override Üzerine yazılacak alanlar.
	 * @return array<string,string>
	 */
	private function post_with( array $override ): array {
		return array_merge(
			array(
				'_slotiva_enabled'   => 'yes',
				'_slotiva_duration'  => '45',
				'_slotiva_min_slots' => '1',
				'_slotiva_max_slots' => '3',
				'_slotiva_pricing'   => 'per_slot',
			),
			$override
		);
	}

	public function test_product_tab_rejects_bad_duration_and_min_gt_max(): void {
		$this->admin();
		$p  = $this->make_product(
			array(
				'_slotiva_enabled'   => 'yes',
				'_slotiva_duration'  => '30',
				'_slotiva_min_slots' => '1',
				'_slotiva_max_slots' => '2',
				'_slotiva_pricing'   => 'per_slot',
			)
		);
		$id = $p->get_id();

		$this->submit( $id, $this->post_with( array( '_slotiva_duration' => '20' ) ) );
		$this->assertSame( '30', (string) wc_get_product( $id )->get_meta( '_slotiva_duration' ) );

		$this->submit(
			$id,
			$this->post_with(
				array(
					'_slotiva_min_slots' => '3',
					'_slotiva_max_slots' => '2',
				)
			)
		);
		$f = wc_get_product( $id );
		$this->assertSame( '30', (string) $f->get_meta( '_slotiva_duration' ) );
		$this->assertSame( '2', (string) $f->get_meta( '_slotiva_max_slots' ) );

		$this->submit( $id, $this->post_with( array( '_slotiva_max_slots' => '11' ) ) );
		$this->assertSame( '30', (string) wc_get_product( $id )->get_meta( '_slotiva_duration' ) );

		$this->submit( $id, $this->post_with( array( '_slotiva_policy' => 'Ürün kuralı' ) ) );
		$f = wc_get_product( $id );
		$this->assertSame( '45', (string) $f->get_meta( '_slotiva_duration' ) );
		$this->assertSame( 'Ürün kuralı', (string) $f->get_meta( '_slotiva_policy' ) );
	}

	public function test_product_tab_requires_capability(): void {
		$p = $this->make_product(
			array(
				'_slotiva_enabled'  => 'yes',
				'_slotiva_duration' => '30',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->submit( $p->get_id(), $this->post_with( array( '_slotiva_duration' => '60' ) ) );
		$this->assertSame( '30', (string) wc_get_product( $p->get_id() )->get_meta( '_slotiva_duration' ) );
	}

	public function test_max_slots_field_has_help_text(): void {
		global $post;
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		$p    = $this->make_product( array( '_slotiva_enabled' => 'yes' ) );
		$post = get_post( $p->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WC alan yardımcısı $post okur.
		ob_start();
		ProductTab::render_panel();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'How many times a customer can book in one order. For 2–3 hour sessions set 3 and use Per slot pricing.', $html );
		$this->assertStringContainsString( '<span class="description">How many times', $html, 'ipucu simgesi değil, alanın yanında görünür metin' );
	}

	public function test_panel_opens_itself_when_linked_with_its_hash(): void {
		// Ayarlar'daki "Slotiva sekmesini aç" bağlantısı #slotiva_product_data ile gelir; WC sekmeyi kendisi açmaz.
		// Betik panelde değil, ürün ekranında WC'nin ürün meta kutusu betiğine satır içi eklenir (final review M5).
		global $post;
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		$p    = $this->make_product( array( '_slotiva_enabled' => 'yes' ) );
		$post = get_post( $p->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WC alan yardımcısı $post okur.
		ob_start();
		ProductTab::render_panel();
		$this->assertStringNotContainsString( '<script', (string) ob_get_clean(), 'panel betik basmaz' );

		$handle = 'wc-admin-product-meta-boxes';
		// Küresel betik kaydı önceki testlerden kalıntı taşıyabilir: kendi durumumuzla çalış, sonunda geri koy.
		$saved                 = $GLOBALS['wp_scripts'] ?? null;
		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test kendi küresel kaydını kurar ve geri koyar.
		try {
			wp_register_script( $handle, '', array(), '1', true );
			set_current_screen( 'product' );
			ProductTab::enqueue_open_script( 'post.php' );
			$js = implode( '', (array) wp_scripts()->get_data( $handle, 'after' ) );
		} finally {
			set_current_screen( 'front' );
			$GLOBALS['wp_scripts'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test kendi küresel kaydını kurar ve geri koyar.
		}
		$this->assertStringContainsString( "'#slotiva_product_data'", $js );
		$this->assertStringContainsString( 'a[href="#slotiva_product_data"]', $js );
		$this->assertStringContainsString( 'scrollIntoView', $js );
	}

	public function test_product_tab_registers_hooks(): void {
		ProductTab::register();
		$tabs = apply_filters( 'woocommerce_product_data_tabs', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WC kancası.
		$this->assertArrayHasKey( 'slotiva', $tabs );
		$this->assertNotFalse( has_action( 'woocommerce_process_product_meta', array( ProductTab::class, 'save' ) ) );
	}

	public function test_from_normalizes_raw_bad_meta(): void {
		Rules::save(
			array(
				'durationDefault' => 60,
				'leadHours'       => 12,
				'horizonDays'     => 21,
			)
		);
		$p     = $this->make_product();
		$id    = $p->get_id();
		$cases = array(
			array( '-15', 60 ),
			array( '0', 60 ),
			array( '20', 60 ),
			array( 'abc', 60 ),
			array( '45', 45 ),
		);
		update_post_meta( $id, '_slotiva_enabled', 'yes' );
		foreach ( $cases as $c ) {
			update_post_meta( $id, '_slotiva_duration', $c[0] );
			$this->assertSame( $c[1], BookableProduct::from( wc_get_product( $id ) )->durationMin, 'süre ' . $c[0] );
		}

		update_post_meta( $id, '_slotiva_horizon_days', '500' );
		$this->assertSame( 62, BookableProduct::from( wc_get_product( $id ) )->horizonDays );
		update_post_meta( $id, '_slotiva_horizon_days', '0' );
		$this->assertSame( 1, BookableProduct::from( wc_get_product( $id ) )->horizonDays );
		update_post_meta( $id, '_slotiva_lead_hours', '-5' );
		$this->assertSame( 0, BookableProduct::from( wc_get_product( $id ) )->leadHours );

		update_post_meta( $id, '_slotiva_min_slots', '5' );
		update_post_meta( $id, '_slotiva_max_slots', '2' );
		$b = BookableProduct::from( wc_get_product( $id ) );
		$this->assertSame( 5, $b->minSlots );
		$this->assertSame( 5, $b->maxSlots );
		update_post_meta( $id, '_slotiva_min_slots', '0' );
		update_post_meta( $id, '_slotiva_max_slots', '99' );
		$b = BookableProduct::from( wc_get_product( $id ) );
		$this->assertSame( 1, $b->minSlots );
		$this->assertSame( 10, $b->maxSlots );
		update_post_meta( $id, '_slotiva_min_slots', '30' );
		$b = BookableProduct::from( wc_get_product( $id ) );
		$this->assertSame( 10, $b->minSlots );
		$this->assertSame( 10, $b->maxSlots );
	}

	public function test_product_tab_save_fires_schedule_changed_only_on_change(): void {
		$this->admin();
		$p = $this->make_product();
		$n = 0;
		add_action(
			'slotiva_schedule_changed',
			static function () use ( &$n ) {
				++$n;
			}
		);
		$this->submit( $p->get_id(), $this->post_with( array( '_slotiva_duration' => '20' ) ) );
		$this->assertSame( 0, $n, 'hatalı kayıt olay tetiklememeli' );
		$this->submit( $p->get_id(), $this->post_with( array() ) );
		$this->assertSame( 1, $n );
		$this->submit( $p->get_id(), $this->post_with( array() ) );
		$this->assertSame( 1, $n, 'değişiklik yoksa tetiklenmemeli' );
		$this->submit( $p->get_id(), array() );
		$this->assertSame( 2, $n, 'kapatma da bir değişikliktir' );
	}
}
