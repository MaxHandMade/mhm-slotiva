<?php
/**
 * Ürün düzenleme ekranındaki "Slotiva" sekmesi.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Product;

use MHM\Slotiva\Schedule\Rules;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Rules/BookableProduct alanları sözleşmeyle camelCase.

/**
 * Sekme + panel çizer; kaydı `woocommerce_process_product_meta` ile (WC kendi nonce/yetkisini denetledikten sonra) yapar.
 */
final class ProductTab {

	/**
	 * Kancaları bağlar.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( self::class, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( self::class, 'render_panel' ) );
		// WC kendi betiklerini `admin_enqueue_scripts` 10'da kaydeder; satır içi betik onların ardından (20) eklenir.
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_open_script' ), 20 );
		add_action( 'woocommerce_process_product_meta', array( self::class, 'save' ) );
	}

	/**
	 * Sekmeyi listeye ekler.
	 *
	 * @param array<string,array<string,mixed>> $tabs Mevcut sekmeler.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_tab( array $tabs ): array {
		$tabs['slotiva'] = array(
			// WC `html-product-data-panel.php`te `esc_html( $tab['label'] )` ile basar; burada ikinci kez kaçışlanmaz.
			'label'    => __( 'Slotiva', 'mhm-slotiva' ),
			'target'   => 'slotiva_product_data',
			'class'    => array(),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * Paneli çizer.
	 */
	public static function render_panel(): void {
		$rules = Rules::load();
		echo '<div id="slotiva_product_data" class="panel woocommerce_options_panel hidden">';
		woocommerce_wp_checkbox(
			array(
				'id'    => '_slotiva_enabled',
				'label' => __( 'Bookable product', 'mhm-slotiva' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_slotiva_duration',
				'label'             => __( 'Slot length (minutes)', 'mhm-slotiva' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => Rules::STEP,
					'min'  => Rules::STEP,
				),
				'placeholder'       => (string) $rules->durationDefault,
				'desc_tip'          => true,
				'description'       => __( 'Must be a multiple of 15 minutes.', 'mhm-slotiva' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_slotiva_min_slots',
				'label'             => __( 'Minimum slots', 'mhm-slotiva' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => 1,
					'max' => 10,
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_slotiva_max_slots',
				'label'             => __( 'Maximum slots', 'mhm-slotiva' ),
				'description'       => __( 'How many times a customer can book in one order. For 2–3 hour sessions set 3 and use Per slot pricing.', 'mhm-slotiva' ),
				'desc_tip'          => false,
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => 1,
					'max' => 10,
				),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_slotiva_pricing',
				'label'   => __( 'Pricing', 'mhm-slotiva' ),
				'options' => array(
					'per_slot' => __( 'Per slot', 'mhm-slotiva' ),
					'fixed'    => __( 'Fixed (single slot)', 'mhm-slotiva' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_slotiva_location',
				'label' => __( 'Location', 'mhm-slotiva' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_slotiva_lead_hours',
				'label'       => __( 'Earliest booking (hours ahead)', 'mhm-slotiva' ),
				'type'        => 'number',
				'placeholder' => (string) $rules->leadHours,
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_slotiva_horizon_days',
				'label'             => __( 'Latest booking (days ahead)', 'mhm-slotiva' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => 1,
					'max' => 62,
				),
				'placeholder'       => (string) $rules->horizonDays,
			)
		);
		woocommerce_wp_textarea_input(
			array(
				'id'          => '_slotiva_policy',
				'label'       => __( 'Cancellation and change policy', 'mhm-slotiva' ),
				'placeholder' => __( 'Leave empty to use the general policy.', 'mhm-slotiva' ),
			)
		);
		$product_id = (int) get_the_ID();
		/**
		 * Lite'ın kendi alanlarından hemen sonra, panelin içinde tetiklenir; ek alanlar burada basılır.
		 *
		 * @param int $product_id Ürün kimliği.
		 */
		do_action( 'slotiva_product_tab_fields', $product_id );
		echo '</div>';
	}

	/**
	 * Ayarlar → "Slotiva sekmesini aç" bu panele #slotiva_product_data ile gelir; WC sekmeyi adresten açmaz.
	 * WC sekme tıklamalarını jQuery ready'de bağlar, bu yüzden `load` sonrası sekmeye tıklanıp kutuya kaydırılır.
	 * Yalnız ürün düzenleme ekranında, WC'nin ürün meta kutusu betiğine bağlı satır içi betik olarak eklenir.
	 *
	 * @param string $hook Ekran kancası (`admin_enqueue_scripts` argümanı).
	 */
	public static function enqueue_open_script( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'product' !== $screen->post_type ) {
			return;
		}
		wp_add_inline_script(
			'wc-admin-product-meta-boxes',
			"window.addEventListener('load',function(){if(window.location.hash!=='#slotiva_product_data'){return;}"
			. "var a=document.querySelector('.product_data_tabs a[href=\"#slotiva_product_data\"]');if(!a){return;}a.click();"
			. "var box=document.getElementById('woocommerce-product-data');if(box){box.scrollIntoView({block:'start'});}});"
		);
	}

	/**
	 * Sekmedeki alanları doğrulayıp kaydeder. Hata varsa HİÇBİRİ yazılmaz (eski değerler korunur).
	 *
	 * Nonce, fail-closed: WC `WC_Admin_Meta_Boxes::save_meta_boxes` bu kancadan önce `woocommerce_meta_nonce`'u
	 * (`woocommerce_save_data`) denetler; kanca başka bir yoldan tetiklenebileceği için aynı nonce burada da doğrulanır.
	 * Geçersiz ya da eksikse hiçbir alan yazılmaz.
	 *
	 * @param int $post_id Ürün kimliği.
	 */
	public static function save( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! current_user_can( 'edit_product', $post_id ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WC ürün meta yetkisi.
			return;
		}
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		$before = self::snapshot( $product );

		$enabled = isset( $_POST['_slotiva_enabled'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['_slotiva_enabled'] ) ) ? 'yes' : 'no';
		$raw     = static fn( string $k ): string => isset( $_POST[ $k ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ) : '';
		$policy  = isset( $_POST['_slotiva_policy'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_slotiva_policy'] ) ) : '';

		if ( 'no' === $enabled ) {
			$product->update_meta_data( '_slotiva_enabled', 'no' );
			$product->save();
			self::maybe_announce( $before, $product );
			self::announce_saved( $post_id );
			return;
		}

		$duration = $raw( '_slotiva_duration' );
		$min      = $raw( '_slotiva_min_slots' );
		$max      = $raw( '_slotiva_max_slots' );
		$lead     = $raw( '_slotiva_lead_hours' );
		$horizon  = $raw( '_slotiva_horizon_days' );
		$pricing  = 'fixed' === $raw( '_slotiva_pricing' ) ? 'fixed' : 'per_slot';

		$errors = array();
		if ( ! ctype_digit( $duration ) || (int) $duration < Rules::STEP || 0 !== (int) $duration % Rules::STEP || (int) $duration > Rules::DURATION_MAX ) {
			$errors[] = __( 'Slotiva: the slot length must be a multiple of 15 minutes (15 to 480).', 'mhm-slotiva' );
		}
		$min_i = ctype_digit( $min ) ? (int) $min : 0;
		$max_i = ctype_digit( $max ) ? (int) $max : 0;
		if ( $min_i < 1 || $max_i > 10 || $min_i > $max_i ) {
			$errors[] = __( 'Slotiva: the slot counts must be 1 ≤ minimum ≤ maximum ≤ 10.', 'mhm-slotiva' );
		}
		if ( '' !== $lead && ! ctype_digit( $lead ) ) {
			$errors[] = __( 'Slotiva: the earliest booking time cannot be negative.', 'mhm-slotiva' );
		}
		if ( '' !== $horizon && ( ! ctype_digit( $horizon ) || (int) $horizon < 1 || (int) $horizon > 62 ) ) {
			$errors[] = __( 'Slotiva: the latest booking day must be between 1 and 62 days ahead.', 'mhm-slotiva' );
		}

		if ( $errors ) {
			if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				foreach ( $errors as $e ) {
					\WC_Admin_Meta_Boxes::add_error( $e );
				}
			}
			self::announce_saved( $post_id );
			return;
		}

		self::commit(
			$product,
			array(
				'duration' => (int) $duration,
				'min'      => $min_i,
				'max'      => $max_i,
				'pricing'  => $pricing,
				'location' => $raw( '_slotiva_location' ),
				'lead'     => $lead,
				'horizon'  => $horizon,
				'policy'   => $policy,
			)
		);
		self::announce_saved( $post_id );
	}

	/**
	 * Lite'ın kendi yetki denetimi ve kayıtları bittikten sonra ek alan kaydı için kanca.
	 *
	 * @param int $post_id Ürün kimliği.
	 */
	private static function announce_saved( int $post_id ): void {
		/**
		 * Lite'ın nonce/yetki denetimi ve kendi kayıtlarından sonra tetiklenir; ek alanlar burada kaydedilir.
		 *
		 * @param int $post_id Ürün kimliği.
		 */
		do_action( 'slotiva_product_tab_save', $post_id );
	}

	/**
	 * Doğrulanmış değerleri yazar, kaydeder ve gerekirse önbellek olayını tetikler (sekme ve kurulum sihirbazı ortak yolu).
	 *
	 * @param \WC_Product         $product Ürün.
	 * @param array<string,mixed> $v       Bkz. writeMeta().
	 */
	public static function commit( \WC_Product $product, array $v ): void {
		$before = self::snapshot( $product );
		self::writeMeta( $product, $v );
		$product->save();
		self::maybe_announce( $before, $product );
	}

	/**
	 * Slotiva metalarını yazar (kaydetmez; değerler önceden doğrulanmış olmalı).
	 *
	 * `$v`: duration?:int (yoksa meta dokunulmaz), min:int, max:int, pricing:'fixed'|'per_slot', location:string, lead:string, horizon:string,
	 * policy:string, sold_individually?:bool (verilmezse yalnız `fixed` iken true yapılır).
	 *
	 * @param \WC_Product         $product Ürün.
	 * @param array<string,mixed> $v       Değerler.
	 */
	public static function writeMeta( \WC_Product $product, array $v ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- yöntem adları sözleşmeyle camelCase.
		$min_i   = (int) $v['min'];
		$max_i   = (int) $v['max'];
		$pricing = 'fixed' === $v['pricing'] ? 'fixed' : 'per_slot';
		if ( 'fixed' === $pricing ) {
			$min_i = 1;
			$max_i = 1;
		}
		if ( array_key_exists( 'sold_individually', $v ) ) {
			$product->set_sold_individually( (bool) $v['sold_individually'] );
		} elseif ( 'fixed' === $pricing ) {
			$product->set_sold_individually( true );
		}
		$lead    = (string) $v['lead'];
		$horizon = (string) $v['horizon'];

		$product->update_meta_data( '_slotiva_enabled', 'yes' );
		// Süre anahtarı verilmezse mevcut meta olduğu gibi kalır (sihirbaz geçersiz süreyi varsayılanla ezmez).
		if ( isset( $v['duration'] ) ) {
			$product->update_meta_data( '_slotiva_duration', (string) (int) $v['duration'] );
		}
		$product->update_meta_data( '_slotiva_min_slots', (string) $min_i );
		$product->update_meta_data( '_slotiva_max_slots', (string) $max_i );
		$product->update_meta_data( '_slotiva_pricing', $pricing );
		$product->update_meta_data( '_slotiva_location', sanitize_text_field( (string) $v['location'] ) );
		$product->update_meta_data( '_slotiva_lead_hours', '' === $lead ? '' : (string) (int) $lead );
		$product->update_meta_data( '_slotiva_horizon_days', '' === $horizon ? '' : (string) (int) $horizon );
		$product->update_meta_data( '_slotiva_policy', (string) $v['policy'] );
	}

	/**
	 * Slotiva'ya ait meta + tek satış durumunun anlık görüntüsü.
	 *
	 * @param \WC_Product $product Ürün.
	 * @return array<string,string>
	 */
	private static function snapshot( \WC_Product $product ): array {
		$out = array( 'sold_individually' => $product->get_sold_individually() ? '1' : '0' );
		foreach ( array( 'enabled', 'duration', 'min_slots', 'max_slots', 'pricing', 'location', 'lead_hours', 'horizon_days', 'policy' ) as $k ) {
			$out[ $k ] = (string) $product->get_meta( '_slotiva_' . $k );
		}
		return $out;
	}

	/**
	 * Kayıt Slotiva verisini değiştirdiyse kullanılabilirlik önbelleği için olayı tetikler.
	 *
	 * @param array<string,string> $before Kayıt öncesi görüntü.
	 * @param \WC_Product          $product Kayıt sonrası ürün.
	 */
	private static function maybe_announce( array $before, \WC_Product $product ): void {
		if ( self::snapshot( $product ) !== $before ) {
			do_action( 'slotiva_schedule_changed' );
		}
	}
}
