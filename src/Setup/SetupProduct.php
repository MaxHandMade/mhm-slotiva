<?php
/**
 * Kurulum sihirbazının tek randevulu ürünü: oluşturma / mevcut ürünü randevulu yapma (1.0.2).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Setup;

use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Product\ProductTab;
use MHM\Slotiva\Schedule\Rules;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- BookableProduct alan adları sözleşmeyle camelCase.

/**
 * Doğrulama `ProductTab::save` ile aynı kural; meta yazımı `ProductTab::commit` ortak yolundan geçer.
 */
final class SetupProduct {

	/** Sihirbazın basit/yeni ürüne yazdığı en yüksek fiyat (dahil, 1e12); money.js `PRICE_MAX` ile aynı. Değişken ürüne uygulanmaz. */
	public const PRICE_MAX = 1000000000000;

	/**
	 * Gövdedeki mantıksal değer (JSON true/1/"1"/"true").
	 *
	 * @param mixed $v Değer.
	 */
	public static function flag( $v ): bool {
		return true === $v || 1 === $v || '1' === $v || 'true' === $v;
	}

	/**
	 * Ürünü oluşturur veya günceller.
	 *
	 * @param array<string,mixed> $in `{productId?, name?, price, durationMin, multi, maxSlots?}`.
	 * @return \WC_Product|\WP_Error Ürün; hata: slotiva_setup_invalid (data.fields) / _product_type / _product_missing.
	 */
	public static function apply( array $in ): \WC_Product|\WP_Error {
		$product_id = isset( $in['productId'] ) && is_scalar( $in['productId'] ) ? (int) $in['productId'] : 0;
		$existing   = null;
		if ( $product_id > 0 ) {
			$existing = wc_get_product( $product_id );
			// Çöpteki ürün sihirbazdan yayına geri döndürülmez (arayüz zaten listelemez).
			if ( ! $existing || 'trash' === $existing->get_status() ) {
				return new \WP_Error( 'slotiva_setup_product_missing', __( 'The selected product was not found.', 'mhm-slotiva' ), array( 'status' => 404 ) );
			}
			if ( ! $existing->is_type( array( 'simple', 'variable' ) ) ) {
				return new \WP_Error( 'slotiva_setup_product_type', __( 'Only simple and variable products can be made bookable.', 'mhm-slotiva' ), array( 'status' => 400 ) );
			}
		}
		// Nesne yetkisi: rota `manage_woocommerce` ister; ürün sekmesi yolu gibi bu ürünü düzenleme ve yayımlama yetkisi de gerekir.
		$type    = get_post_type_object( 'product' );
		$publish = $type ? (string) $type->cap->publish_posts : 'publish_posts';
		if ( ( $existing && ! current_user_can( 'edit_post', $product_id ) ) || ! current_user_can( $publish ) ) {
			return new \WP_Error( 'slotiva_setup_forbidden', __( 'You are not allowed to edit or publish this product.', 'mhm-slotiva' ), array( 'status' => 403 ) );
		}

		$fields = array();

		$name = isset( $in['name'] ) && is_scalar( $in['name'] ) ? trim( sanitize_text_field( (string) $in['name'] ) ) : '';
		if ( ! $existing && '' === $name ) {
			$fields[] = 'name';
		}

		$price_raw = isset( $in['price'] ) && is_scalar( $in['price'] ) ? trim( (string) $in['price'] ) : '';
		$price     = '' === $price_raw ? '' : wc_format_decimal( $price_raw );
		// Değişken ürünün fiyatı sihirbazca hiç yazılmaz (yalnız basit ürüne `set_regular_price`); istemci mevcut değeri aynen yollar,
		// bu yüzden o yolda fiyat doğrulaması ve tavan uygulanmaz.
		$writes_price = ! $existing || $existing->is_type( 'simple' );
		if ( $writes_price && ( '' === $price || ! is_numeric( $price ) || (float) $price < 0 || (float) $price > self::PRICE_MAX ) ) {
			$fields[] = 'price';
		}

		$duration_raw = isset( $in['durationMin'] ) && is_scalar( $in['durationMin'] ) ? trim( (string) $in['durationMin'] ) : '';
		$duration     = ctype_digit( $duration_raw ) ? (int) $duration_raw : 0;
		// Üst sınır yalnız süre kullanılıyorsa (yeni ürün ya da randevusuz mevcut ürün) denetlenir; randevulu ürün kendi süresini
		// korur, depoda kalmış büyük varsayılan süre sihirbaz adımını engellemez. Adım/alt sınır her durumda aranır.
		$uses_duration = ! ( $existing && BookableProduct::from( $existing ) );
		if ( $duration < Rules::STEP || 0 !== $duration % Rules::STEP || ( $uses_duration && $duration > Rules::DURATION_MAX ) ) {
			$fields[] = 'durationMin';
		}

		$multi = isset( $in['multi'] ) && self::flag( $in['multi'] );
		$max   = 1;
		if ( $multi ) {
			$max_raw = isset( $in['maxSlots'] ) && is_scalar( $in['maxSlots'] ) ? trim( (string) $in['maxSlots'] ) : '';
			$max     = ctype_digit( $max_raw ) ? (int) $max_raw : 0;
			if ( $max < 1 || $max > 10 ) {
				$fields[] = 'maxSlots';
			}
		}

		if ( $fields ) {
			return new \WP_Error(
				'slotiva_setup_invalid',
				__( 'Some fields are not valid.', 'mhm-slotiva' ),
				array(
					'status' => 400,
					'fields' => $fields,
				)
			);
		}

		$product = $existing ? $existing : new \WC_Product_Simple();
		if ( ! $existing ) {
			$product->set_name( $name );
			$product->set_virtual( true );
		} elseif ( '' !== $name ) {
			$product->set_name( $name );
		}
		if ( $product->is_type( 'simple' ) ) {
			$product->set_regular_price( $price );
		}
		$product->set_status( 'publish' );

		// Mevcut ürünün konum / erken-geç rezervasyon / politika alanları korunur.
		$keep = static fn( string $k ): string => (string) $product->get_meta( '_slotiva_' . $k );
		// Zaten randevulu ürünün kendi süresi / asgari dilimi / fiyatlaması korunur; sihirbaz yalnız yeni veya randevusuz ürüne yazar.
		// Süre yine de yukarıda doğrulanır (geçersiz girdi her durumda slotiva_setup_invalid döner).
		$b        = $existing ? BookableProduct::from( $existing ) : null;
		$keep_dur = false;
		if ( $b ) {
			$duration = $b->durationMin;
			// Geçersiz süre metası (adıma bölünmeyen/sayısal olmayan) mağaza varsayılanıyla kalıcı ezilmez: olduğu gibi bırakılır.
			$raw_dur  = $product->get_meta( '_slotiva_duration' );
			$keep_dur = '' !== (string) $raw_dur && ( ! is_numeric( $raw_dur ) || (int) $raw_dur < Rules::STEP || 0 !== (int) $raw_dur % Rules::STEP );
			$min      = min( $b->minSlots, $max );
			$pricing  = $multi ? 'per_slot' : $b->pricing;
		} else {
			$min     = 1;
			$pricing = $multi ? 'per_slot' : 'fixed';
		}
		$values = array(
			'duration'          => $duration,
			'min'               => $min,
			'max'               => $max,
			'pricing'           => $pricing,
			'location'          => $keep( 'location' ),
			'lead'              => $keep( 'lead_hours' ),
			'horizon'           => $keep( 'horizon_days' ),
			'policy'            => $keep( 'policy' ),
			'sold_individually' => ! $multi,
		);
		if ( $keep_dur ) {
			unset( $values['duration'] );
		}
		ProductTab::commit( $product, $values );
		// WC `save()` ekleme başarısız olursa kimliği 0 bırakır; 201 ile kimliksiz ürün dönülmez.
		if ( $product->get_id() <= 0 ) {
			return new \WP_Error( 'slotiva_setup_product_save', __( 'The product could not be saved. Please try again.', 'mhm-slotiva' ), array( 'status' => 500 ) );
		}
		return $product;
	}
}
