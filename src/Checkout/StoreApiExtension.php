<?php
/**
 * Store API şema uzantıları: ödeme isteğindeki `slotiva.slots`, sepet öğesi randevu bilgisi, adet güncelleme geri çağrısı.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Checkout;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\LockBusyException;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Fresh;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- imzalar sözleşmeyle camelCase.

/**
 * Kayıtsız ad alanı doğrulamasız geçer (spec §6.3) → kayıt zorunlu. `slots` isteğe bağlıdır: randevulu ürün yoksa
 * istek onsuz geçer; varsa seçimsizliği CheckoutFlow reddeder.
 */
final class StoreApiExtension {

	public const NS = 'slotiva';

	/**
	 * WC Blocks yüklendiğinde (ya da yüklendiyse hemen) kaydeder.
	 */
	public static function register(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::registerData();
			return;
		}
		add_action( 'woocommerce_blocks_loaded', array( self::class, 'registerData' ) );
	}

	/**
	 * Uç verisi + güncelleme geri çağrısı.
	 */
	public static function registerData(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => self::NS,
				'schema_callback' => array( self::class, 'checkoutSchema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartItemSchema::IDENTIFIER,
				'namespace'       => self::NS,
				'data_callback'   => array( self::class, 'cartItemData' ),
				'schema_callback' => array( self::class, 'cartItemSchema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::NS,
				'callback'  => array( self::class, 'update' ),
			)
		);
	}

	/**
	 * Ödeme isteği şeması.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function checkoutSchema(): array {
		return array(
			'slots' => array(
				'description' => __( 'Chosen appointment start times (ISO-8601, UTC).', 'mhm-slotiva' ),
				'type'        => array( 'array', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'items'       => array( 'type' => 'string' ),
				'maxItems'    => SlotValidator::MAX_SLOTS,
			),
		);
	}

	/**
	 * Sepet öğesi şeması (Task 9 seçicisi okur).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function cartItemSchema(): array {
		$ro = array(
			'context'  => array( 'view', 'edit' ),
			'readonly' => true,
		);
		return array(
			'bookable'   => array( 'type' => 'boolean' ) + $ro,
			'min'        => array( 'type' => 'integer' ) + $ro,
			'max'        => array( 'type' => 'integer' ) + $ro,
			'pricing'    => array( 'type' => 'string' ) + $ro,
			'duration'   => array( 'type' => 'integer' ) + $ro,
			'product_id' => array( 'type' => 'integer' ) + $ro,
			'policy'     => array( 'type' => 'string' ) + $ro,
		);
	}

	/**
	 * Sepet öğesi verisi.
	 *
	 * @param array<string,mixed> $cartItem Sepet satırı.
	 * @return array{bookable:bool,min:int,max:int,pricing:string,duration:int,product_id:int,policy:string}
	 */
	public static function cartItemData( array $cartItem ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- sözleşme.
		$product = $cartItem['data'] ?? null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- sözleşme.
		$bp      = $product instanceof \WC_Product ? BookableProduct::from( $product ) : null;
		if ( ! $bp ) {
			return array(
				'bookable'   => false,
				'min'        => 0,
				'max'        => 0,
				'pricing'    => '',
				'duration'   => 0,
				'product_id' => 0,
				'policy'     => '',
			);
		}
		return array(
			'bookable'   => true,
			'min'        => $bp->minSlots,
			'max'        => $bp->maxSlots,
			'pricing'    => $bp->pricing,
			'duration'   => $bp->durationMin,
			'product_id' => $bp->productId,
			'policy'     => $bp->policyText,
		);
	}

	/**
	 * `cart/extensions` geri çağrısı: `{ count:int }` → `per_slot` randevulu satırın adedi `max(1,min)..max` aralığına
	 * kırpılarak `count` yapılır (0 satırı silmez). Adet değişince sepet özeti değişir ve WC eski bekleyen siparişi yeniden
	 * kullanmaz → aynı oturumun önceki ödenmemiş taslağının tutması hemen bırakılır (§15.3).
	 *
	 * @param array<string,mixed> $data İstek verisi.
	 * @throws RouteException 409 slotiva_busy (oturum kilidi alınamadı).
	 */
	public static function update( array $data ): void {
		$cart = WC()->cart;
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		$lines = CheckoutFlow::cartBookableLines( $cart );
		if ( 1 !== count( $lines ) ) {
			return;
		}
		$key = (string) array_key_first( $lines );
		$bp  = $lines[ $key ];
		if ( 'per_slot' !== $bp->pricing ) {
			return;
		}
		$count = isset( $data['count'] ) && is_numeric( $data['count'] ) ? (int) $data['count'] : 0;
		$count = max( max( 1, $bp->minSlots ), min( $bp->maxSlots, $count ) );
		$line  = $cart->get_cart_item( $key );
		if ( (int) ( $line['quantity'] ?? 0 ) === $count ) {
			return;
		}
		$cart->set_quantity( $key, $count, true );
		self::releasePreviousDraft();
	}

	/**
	 * Oturumun Store API taslağı ödenmemişse (`checkout-draft|pending|failed`) oturuma bağlı tutmaları bırakılır.
	 *
	 * @throws RouteException 409 slotiva_busy.
	 */
	private static function releasePreviousDraft(): void {
		$hash    = CheckoutFlow::sessionHash();
		$session = WC()->session;
		if ( null === $hash || ! $session instanceof \WC_Session ) {
			return;
		}
		$draft = Fresh::order( (int) $session->get( 'store_api_draft_order', 0 ) );
		if ( ! $draft || ! $draft->has_status( array( 'checkout-draft', 'pending', 'failed' ) ) ) {
			return;
		}
		try {
			ReservationRepository::supersedeForSession( $hash, 0 );
		} catch ( LockBusyException $e ) {
			throw new RouteException( 'slotiva_busy', esc_html__( 'Your order is being processed. Please try again in a few seconds.', 'mhm-slotiva' ), 409 );
		}
	}
}
