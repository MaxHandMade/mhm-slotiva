<?php
/**
 * Kurulum uçları (1.0.2): `GET slotiva/v1/admin/setup`.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Setup;

use MHM\Slotiva\Admin\Menu;
use MHM\Slotiva\Admin\RestController;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- yöntem ve BookableProduct alan adları sözleşmeyle camelCase.

/**
 * Kurulum durumu ve sihirbaz verisi.
 */
final class SetupRest {

	/** Aday liste üst sınırı. */
	private const MAX_CANDIDATES = 200;

	/** Kancalar. */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/** Rotalar. */
	public static function routes(): void {
		register_rest_route(
			RestController::REST_NAMESPACE,
			RestController::BASE . '/setup',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get' ),
				'permission_callback' => static fn(): bool => current_user_can( Menu::CAPABILITY ),
			)
		);
		register_rest_route(
			RestController::REST_NAMESPACE,
			RestController::BASE . '/setup/product',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'saveProduct' ),
				'permission_callback' => static fn(): bool => current_user_can( Menu::CAPABILITY ),
			)
		);
		register_rest_route(
			RestController::REST_NAMESPACE,
			RestController::BASE . '/setup/state',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'saveState' ),
				'permission_callback' => static fn(): bool => current_user_can( Menu::CAPABILITY ),
			)
		);
	}

	/**
	 * POST /setup/product.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function saveProduct( \WP_REST_Request $request ) {
		$body    = self::body( $request );
		$created = ! isset( $body['productId'] ) || ! is_scalar( $body['productId'] ) || (int) $body['productId'] <= 0;
		$result  = SetupProduct::apply( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( array( 'product' => self::summary( $result ) ), $created ? 201 : 200 );
	}

	/**
	 * POST /setup/state.
	 *
	 * @param \WP_REST_Request $request İstek.
	 */
	public static function saveState( \WP_REST_Request $request ): \WP_REST_Response {
		$body = self::body( $request );
		$opt  = get_option( SetupStatus::OPTION, array() );
		$opt  = is_array( $opt ) ? $opt : array();
		if ( array_key_exists( 'dismissed', $body ) ) {
			$opt['dismissed'] = SetupProduct::flag( $body['dismissed'] );
		}
		if ( array_key_exists( 'completed', $body ) ) {
			$opt['completed_at'] = SetupProduct::flag( $body['completed'] ) ? time() : null;
		}
		update_option( SetupStatus::OPTION, $opt, false );
		$state = SetupStatus::state();
		return new \WP_REST_Response(
			array(
				'complete'  => $state['completed'],
				'dismissed' => $state['dismissed'],
			)
		);
	}

	/**
	 * İstek gövdesi (JSON ya da form).
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return array<string,mixed>
	 */
	private static function body( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : $request->get_body_params();
	}

	/**
	 * Ürün özeti (GET /setup ve POST /setup/product ortak biçimi).
	 *
	 * @param \WC_Product $product Ürün.
	 * @return array<string,mixed>
	 */
	public static function summary( \WC_Product $product ): array {
		$id = $product->get_id();
		$b  = BookableProduct::from( $product );
		return array(
			'id'          => $id,
			'name'        => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'status'      => $product->get_status(),
			'durationMin' => $b ? $b->durationMin : Rules::load()->durationDefault,
			'minSlots'    => $b ? $b->minSlots : 1,
			'maxSlots'    => $b ? $b->maxSlots : 1,
			'pricing'     => $b ? $b->pricing : 'per_slot',
			'editUrl'     => (string) get_edit_post_link( $id, 'raw' ),
			'permalink'   => (string) get_permalink( $id ),
			'price'       => (string) $product->get_price(),
			'type'        => $product->get_type(),
		);
	}

	/**
	 * Randevular sayfası kurulum özeti: tamamlık, gizlenme, kontroller ve (saat satırının özeti için) haftalık saatler.
	 *
	 * @return array{complete:bool,dismissed:bool,checks:array<string,mixed>,schedule:array{days:list<mixed>}}
	 */
	public static function setupSummary(): array {
		$state  = SetupStatus::state();
		$checks = SetupStatus::checks();
		return array(
			'complete'  => SetupStatus::completeFrom( $checks ),
			'dismissed' => $state['dismissed'],
			'checks'    => $checks,
			'schedule'  => array( 'days' => array_values( WeeklySchedule::load() ) ),
		);
	}

	/**
	 * GET /setup.
	 */
	public static function get(): \WP_REST_Response {
		$state  = SetupStatus::state();
		$checks = SetupStatus::checks();
		return new \WP_REST_Response(
			array(
				'complete'   => SetupStatus::completeFrom( $checks ),
				'dismissed'  => $state['dismissed'],
				'checks'     => $checks,
				'products'   => self::products(),
				'candidates' => self::candidates(),
				'currency'   => array(
					'symbol'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
					'decimals'    => wc_get_price_decimals(),
					'decimalSep'  => wc_get_price_decimal_separator(),
					'thousandSep' => wc_get_price_thousand_separator(),
					'position'    => (string) get_option( 'woocommerce_currency_pos', 'left' ),
				),
				'rules'      => array( 'durationDefault' => Rules::load()->durationDefault ),
				'schedule'   => array( 'days' => array_values( WeeklySchedule::load() ) ),
			)
		);
	}

	/**
	 * Randevulu ürünler (her durumda).
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function products(): array {
		$out = array();
		foreach ( Menu::bookableProducts() as $row ) {
			$product = wc_get_product( $row['id'] );
			if ( ! $product ) {
				continue;
			}
			$out[] = self::summary( $product );
		}
		return $out;
	}

	/**
	 * Randevulu olmayan basit/değişken ürünler (en çok 200, ada göre).
	 *
	 * @return list<array{id:int,name:string,type:string,status:string,price:string}>
	 */
	private static function candidates(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => self::MAX_CANDIDATES,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- yönetici ekranı, en çok 200 ürün.
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => array( 'simple', 'variable' ),
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- yönetici ekranı, en çok 200 ürün.
					'relation' => 'OR',
					array(
						'key'     => '_slotiva_enabled',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_slotiva_enabled',
						'value'   => 'yes',
						'compare' => '!=',
					),
				),
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product ) {
				continue;
			}
			$out[] = array(
				'id'     => (int) $id,
				'name'   => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
				'type'   => $product->get_type(),
				'status' => $product->get_status(),
				'price'  => (string) $product->get_price(),
			);
		}
		return $out;
	}
}
