<?php
/**
 * `POST slotiva/v1/admin/setup/product` ve `/setup/state` (1.0.2).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Setup;

use MHM\Slotiva\Setup\SetupStatus;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class SetupProductTest extends SlotivaTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_option( SetupStatus::OPTION );
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		parent::tear_down();
	}

	/**
	 * Yönetici ucuna JSON gövdeyle POST.
	 *
	 * @param string              $route Uç (product|state).
	 * @param array<string,mixed> $body  Gövde.
	 */
	private function post( string $route, array $body ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'POST', '/slotiva/v1/admin/setup/' . $route );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	private function productCount(): int {
		return count(
			get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}

	public function test_creates_published_virtual_product_with_meta(): void {
		$res = $this->post(
			'product',
			array(
				'name'        => 'Seans',
				'price'       => '75',
				'durationMin' => 60,
				'multi'       => true,
				'maxSlots'    => 3,
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$id = $res->get_data()['product']['id'];
		$p  = wc_get_product( $id );
		$this->assertSame( 'publish', $p->get_status() );
		$this->assertTrue( $p->is_virtual() );
		$this->assertSame( '75', $p->get_regular_price() );
		$this->assertSame( 'yes', $p->get_meta( '_slotiva_enabled' ) );
		$this->assertSame( '60', $p->get_meta( '_slotiva_duration' ) );
		$this->assertSame( 'per_slot', $p->get_meta( '_slotiva_pricing' ) );
		$this->assertSame( '1', $p->get_meta( '_slotiva_min_slots' ) );
		$this->assertSame( '3', $p->get_meta( '_slotiva_max_slots' ) );
		$this->assertFalse( $p->get_sold_individually() );
		$this->assertSame( 'Seans', $res->get_data()['product']['name'] );
		$this->assertSame( 3, $res->get_data()['product']['maxSlots'] );
	}

	public function test_single_hour_sets_fixed_and_sold_individually(): void {
		$res = $this->post(
			'product',
			array(
				'name'        => 'Tek',
				'price'       => '40',
				'durationMin' => 45,
				'multi'       => false,
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$p = wc_get_product( $res->get_data()['product']['id'] );
		$this->assertSame( 'fixed', $p->get_meta( '_slotiva_pricing' ) );
		$this->assertSame( '1', $p->get_meta( '_slotiva_min_slots' ) );
		$this->assertSame( '1', $p->get_meta( '_slotiva_max_slots' ) );
		$this->assertTrue( $p->get_sold_individually() );
	}

	public function test_updates_existing_product_without_creating(): void {
		$e = new \WC_Product_Simple();
		$e->set_name( 'Mevcut' );
		$e->set_status( 'draft' );
		$e->set_regular_price( '10' );
		$e->update_meta_data( '_slotiva_location', 'Salon' );
		$id     = $e->save();
		$before = $this->productCount();

		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '20',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $before, $this->productCount() );
		$p = wc_get_product( $id );
		$this->assertSame( 'Mevcut', $p->get_name() );
		$this->assertSame( 'publish', $p->get_status() );
		$this->assertSame( '20', $p->get_regular_price() );
		$this->assertSame( 'yes', $p->get_meta( '_slotiva_enabled' ) );
		$this->assertSame( 'Salon', $p->get_meta( '_slotiva_location' ) );
		$this->assertSame( $id, $res->get_data()['product']['id'] );
	}

	public function test_product_price_accepts_store_decimal_separator(): void {
		update_option( 'woocommerce_price_decimal_sep', ',' );
		try {
			$res = $this->post(
				'product',
				array(
					'name'        => 'Virgül',
					'price'       => '12,50',
					'durationMin' => 30,
					'multi'       => false,
				)
			);
			$this->assertSame( 201, $res->get_status() );
			$this->assertSame( '12.50', wc_get_product( $res->get_data()['product']['id'] )->get_regular_price() );
		} finally {
			update_option( 'woocommerce_price_decimal_sep', '.' );
		}
	}

	public function test_rejects_bad_duration_and_max(): void {
		$res = $this->post(
			'product',
			array(
				'name'        => 'X',
				'price'       => '10',
				'durationMin' => 50,
				'multi'       => true,
				'maxSlots'    => 11,
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'slotiva_setup_invalid', $res->get_data()['code'] );
		$this->assertEqualsCanonicalizing( array( 'durationMin', 'maxSlots' ), $res->get_data()['data']['fields'] );
		$this->assertSame( 0, $this->productCount() );
	}

	public function test_rejects_duration_above_max(): void {
		$res = $this->post(
			'product',
			array(
				'name'        => 'X',
				'price'       => '10',
				'durationMin' => '495',
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertContains( 'durationMin', $res->get_data()['data']['fields'] );
		$res = $this->post(
			'product',
			array(
				'name'        => 'X',
				'price'       => '10',
				'durationMin' => '480',
			)
		);
		$this->assertSame( 201, $res->get_status() );
	}

	public function test_price_ceiling_inclusive(): void {
		$res = $this->post(
			'product',
			array(
				'name'        => 'X',
				'price'       => '1000000000000.01',
				'durationMin' => '60',
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertContains( 'price', $res->get_data()['data']['fields'] );
		$res = $this->post(
			'product',
			array(
				'name'        => 'X',
				'price'       => '1000000000000',
				'durationMin' => '60',
			)
		);
		$this->assertSame( 201, $res->get_status() );
	}

	public function test_variable_product_over_old_price_ceiling_is_accepted(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Pahalı değişken' );
		$parent_id = $parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '2000000' );
		$variation->set_status( 'publish' );
		$variation->save();
		\WC_Product_Variable::sync( $parent_id );

		$res = $this->post(
			'product',
			array(
				'productId'   => $parent_id,
				'price'       => '2000000',
				'durationMin' => 60,
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_bookable_product_ignores_over_max_duration(): void {
		$parent = new \WC_Product_Simple();
		$parent->set_name( 'Randevulu' );
		$parent->set_regular_price( '50' );
		$id = $parent->save();
		$p  = wc_get_product( $id );
		\MHM\Slotiva\Product\ProductTab::commit(
			$p,
			array(
				'duration'          => 30,
				'min'               => 1,
				'max'               => 1,
				'pricing'           => 'fixed',
				'location'          => '',
				'lead'              => '',
				'horizon'           => '',
				'policy'            => '',
				'sold_individually' => true,
			)
		);
		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '50',
				'durationMin' => '600',
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 30, \MHM\Slotiva\Product\BookableProduct::from( wc_get_product( $id ) )->durationMin );
	}

	public function test_rejects_empty_and_negative_price(): void {
		foreach ( array( '', '-5', 'abc' ) as $price ) {
			$res = $this->post(
				'product',
				array(
					'name'        => 'X',
					'price'       => $price,
					'durationMin' => 30,
					'multi'       => false,
				)
			);
			$this->assertSame( 400, $res->get_status(), "price '$price'" );
			$this->assertSame( array( 'price' ), $res->get_data()['data']['fields'] );
		}
	}

	public function test_setup_product_rejects_grouped_and_external(): void {
		$g = new \WC_Product_Grouped();
		$g->set_name( 'G' );
		$x = new \WC_Product_External();
		$x->set_name( 'E' );
		foreach ( array( $g->save(), $x->save() ) as $id ) {
			$res = $this->post(
				'product',
				array(
					'productId'   => $id,
					'price'       => '10',
					'durationMin' => 30,
					'multi'       => false,
				)
			);
			$this->assertSame( 400, $res->get_status() );
			$this->assertSame( 'slotiva_setup_product_type', $res->get_data()['code'] );
		}
	}

	public function test_missing_product_is_404(): void {
		$res = $this->post(
			'product',
			array(
				'productId'   => 99999999,
				'price'       => '10',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'slotiva_setup_product_missing', $res->get_data()['code'] );
	}

	/** Yeni ürün kaydedilemezse uç 201 değil hata döner (pre-ZIP Codex 2: `save()` sonucu denetlenir). */
	public function test_failed_insert_is_an_error_not_201(): void {
		add_filter( 'wp_insert_post_empty_content', '__return_true' );
		$res = $this->post(
			'product',
			array(
				'name'        => 'Kaydedilemez',
				'price'       => '10',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		$this->assertSame( 500, $res->get_status() );
		$this->assertSame( 'slotiva_setup_product_save', $res->get_data()['code'] );
		$this->assertSame( 0, $this->productCount() );
	}

	/** Çöpteki ürün sihirbazdan yayına geri döndürülemez (pre-ZIP Fable 2a). */
	public function test_trashed_product_is_refused_and_stays_in_trash(): void {
		$e = new \WC_Product_Simple();
		$e->set_name( 'Çöp' );
		$e->set_regular_price( '10' );
		$id = $e->save();
		wp_trash_post( $id );

		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '10',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'slotiva_setup_product_missing', $res->get_data()['code'] );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	/**
	 * Nesne yetkisi (pre-ZIP Fable 2c): rota `manage_woocommerce` ister; ayrıca mevcut ürün için `edit_post`,
	 * yayına alma için `publish_products` gerekir (ürün sekmesi yolu ile aynı).
	 */
	public function test_object_capabilities_are_required(): void {
		$uid  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = new \WP_User( $uid );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $uid );

		$e = new \WC_Product_Simple();
		$e->set_name( 'Başkası' );
		$e->set_regular_price( '10' );
		$id = $e->save();

		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '99',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		$this->assertSame( 403, $res->get_status(), 'edit_post yok' );
		$this->assertSame( '10', wc_get_product( $id )->get_regular_price() );

		$res = $this->post(
			'product',
			array(
				'name'        => 'Yeni',
				'price'       => '10',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		$this->assertSame( 403, $res->get_status(), 'publish_products yok' );
		$this->assertSame( 1, $this->productCount() );
	}

	public function test_commit_announces_schedule_change(): void {
		$fired = 0;
		add_action(
			'slotiva_schedule_changed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		$this->post(
			'product',
			array(
				'name'        => 'Olay',
				'price'       => '10',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		$this->assertGreaterThan( 0, $fired );
	}

	public function test_state_dismiss_and_complete(): void {
		$res = $this->post( 'state', array( 'dismissed' => true ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['dismissed'] );
		$this->assertFalse( $res->get_data()['complete'] );

		$res = $this->post( 'state', array( 'completed' => true ) );
		$this->assertTrue( $res->get_data()['complete'] );
		$this->assertTrue( $res->get_data()['dismissed'] );
		$this->assertGreaterThan( 0, get_option( SetupStatus::OPTION )['completed_at'] );

		$res = $this->post( 'state', array( 'dismissed' => false ) );
		$this->assertFalse( $res->get_data()['dismissed'] );
		$this->assertTrue( $res->get_data()['complete'] );
	}

	public function test_non_numeric_product_id_creates_and_answers_201(): void {
		$res = $this->post(
			'product',
			array(
				'productId'   => 'x',
				'name'        => 'Yeni',
				'price'       => '10',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 1, $this->productCount() );
	}

	/**
	 * Belgelenmiş davranış: sihirbazda seçilen taslak/bekleyen/özel ürün yayımlanır (ürün kontrolü yayımlanmış ürün
	 * ister); arayüz bunu "Bu ürün yayınlanacak." notuyla söyler.
	 */
	public function test_picked_private_product_is_published(): void {
		$e = new \WC_Product_Simple();
		$e->set_name( 'Özel' );
		$e->set_status( 'private' );
		$e->set_regular_price( '10' );
		$id = $e->save();

		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '10',
				'durationMin' => 30,
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'publish', wc_get_product( $id )->get_status() );
		$this->assertSame( 'publish', $res->get_data()['product']['status'] );
	}

	public function test_existing_product_keeps_location_lead_horizon_policy(): void {
		$e = new \WC_Product_Simple();
		$e->set_name( 'Mevcut' );
		$e->set_regular_price( '10' );
		$e->update_meta_data( '_slotiva_enabled', 'yes' );
		$e->update_meta_data( '_slotiva_location', 'Salon 2' );
		$e->update_meta_data( '_slotiva_lead_hours', '12' );
		$e->update_meta_data( '_slotiva_horizon_days', '30' );
		$e->update_meta_data( '_slotiva_policy', 'İptal 24 saat önce.' );
		$id = $e->save();

		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '15',
				'durationMin' => 60,
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$p = wc_get_product( $id );
		$this->assertSame( 'Salon 2', $p->get_meta( '_slotiva_location' ) );
		$this->assertSame( '12', $p->get_meta( '_slotiva_lead_hours' ) );
		$this->assertSame( '30', $p->get_meta( '_slotiva_horizon_days' ) );
		$this->assertSame( 'İptal 24 saat önce.', $p->get_meta( '_slotiva_policy' ) );
	}

	public function test_single_then_multi_clears_sold_individually(): void {
		$res = $this->post(
			'product',
			array(
				'name'        => 'Tek',
				'price'       => '40',
				'durationMin' => 60,
				'multi'       => false,
			)
		);
		$id  = $res->get_data()['product']['id'];
		$this->assertTrue( wc_get_product( $id )->get_sold_individually() );

		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '40',
				'durationMin' => 60,
				'multi'       => true,
				'maxSlots'    => 3,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$p = wc_get_product( $id );
		$this->assertFalse( $p->get_sold_individually() );
		$this->assertSame( 'per_slot', $p->get_meta( '_slotiva_pricing' ) );
		$this->assertSame( '3', $p->get_meta( '_slotiva_max_slots' ) );
	}

	public function test_variable_product_price_is_left_untouched(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Değişken' );
		$parent_id = $parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '30' );
		$variation->set_status( 'publish' );
		$variation_id = $variation->save();
		\WC_Product_Variable::sync( $parent_id );

		$res = $this->post(
			'product',
			array(
				'productId'   => $parent_id,
				'price'       => '99',
				'durationMin' => 60,
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '30', wc_get_product( $variation_id )->get_regular_price() );
		$this->assertSame( '', wc_get_product( $parent_id )->get_regular_price() );
		$this->assertSame( '30', wc_get_product( $parent_id )->get_price() );
	}

	/**
	 * Randevulu (veya değil) mevcut basit ürün oluşturur.
	 *
	 * @param array<string,string> $meta Ek meta (anahtar `_slotiva_` önekiyle).
	 * @param bool                 $bookable Randevulu mu.
	 */
	private function existingProduct( array $meta, bool $bookable = true ): int {
		$e = new \WC_Product_Simple();
		$e->set_name( 'Mevcut' );
		$e->set_regular_price( '10' );
		if ( $bookable ) {
			$e->update_meta_data( '_slotiva_enabled', 'yes' );
		}
		foreach ( $meta as $k => $v ) {
			$e->update_meta_data( '_slotiva_' . $k, $v );
		}
		return $e->save();
	}

	/**
	 * 1.0.4 D3: geçersiz süre metası (adıma bölünmeyen / sayısal olmayan) sihirbazca mağaza varsayılanıyla kalıcı ezilmez.
	 *
	 * @dataProvider invalidDurationProvider
	 *
	 * @param string $bad Geçersiz süre metası.
	 */
	public function test_rerun_keeps_invalid_duration_meta_as_is( string $bad ): void {
		$id  = $this->existingProduct( array( 'duration' => $bad ) );
		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '100',
				'durationMin' => '60',
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $bad, wc_get_product( $id )->get_meta( '_slotiva_duration' ) );
	}

	/**
	 * Geçersiz süre metası örnekleri.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function invalidDurationProvider(): array {
		return array(
			'not a multiple of step' => array( '50' ),
			'non numeric'            => array( 'abc' ),
		);
	}

	public function test_rerun_keeps_bookable_product_duration(): void {
		$id  = $this->existingProduct( array( 'duration' => '90' ) );
		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '100',
				'durationMin' => '60',
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '90', wc_get_product( $id )->get_meta( '_slotiva_duration' ) );
	}

	public function test_rerun_keeps_min_slots_clamped_to_max(): void {
		$id  = $this->existingProduct(
			array(
				'pricing'   => 'per_slot',
				'min_slots' => '2',
				'max_slots' => '3',
			)
		);
		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '10',
				'durationMin' => 60,
				'multi'       => true,
				'maxSlots'    => '3',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '2', wc_get_product( $id )->get_meta( '_slotiva_min_slots' ) );

		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '10',
				'durationMin' => 60,
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$p = wc_get_product( $id );
		$this->assertSame( '1', $p->get_meta( '_slotiva_min_slots' ) );
		$this->assertSame( '1', $p->get_meta( '_slotiva_max_slots' ) );
	}

	public function test_rerun_single_hour_keeps_per_slot_pricing(): void {
		$id  = $this->existingProduct(
			array(
				'pricing'   => 'per_slot',
				'max_slots' => '1',
			)
		);
		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '10',
				'durationMin' => 60,
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'per_slot', wc_get_product( $id )->get_meta( '_slotiva_pricing' ) );
	}

	public function test_non_bookable_existing_product_takes_wizard_duration(): void {
		$id  = $this->existingProduct( array( 'duration' => '90' ), false );
		$res = $this->post(
			'product',
			array(
				'productId'   => $id,
				'price'       => '10',
				'durationMin' => 60,
				'multi'       => false,
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$p = wc_get_product( $id );
		$this->assertSame( '60', $p->get_meta( '_slotiva_duration' ) );
		$this->assertSame( '1', $p->get_meta( '_slotiva_min_slots' ) );
		$this->assertSame( 'fixed', $p->get_meta( '_slotiva_pricing' ) );
	}

	public function test_post_routes_require_manage_woocommerce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->post( 'product', array( 'name' => 'X' ) )->get_status() );
		$this->assertSame( 403, $this->post( 'state', array( 'dismissed' => true ) )->get_status() );
	}
}
