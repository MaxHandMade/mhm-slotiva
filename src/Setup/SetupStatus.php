<?php
/**
 * Kurulum durumu (1.0.2): sitenin randevu almaya hazır olup olmadığını ölçer; sihirbaz ve kurulum listesi buradan beslenir.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Setup;

use MHM\Slotiva\Schedule\WeeklySchedule;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- yöntem adları sözleşmeyle camelCase.

/**
 * Tamamlık = hours + product + checkout; timezone/payment bilgi amaçlıdır.
 */
final class SetupStatus {

	/** Seçenek: `{completed_at:int|null, dismissed:bool}`. */
	public const OPTION = 'slotiva_setup';

	/** Tamamlığı belirleyen kontroller. */
	private const REQUIRED = array( 'hours', 'checkout', 'product' );

	/**
	 * Tüm kontroller (sıra sabit).
	 *
	 * @return array<string,array{state:string,detail:string,action?:string}>
	 */
	public static function checks(): array {
		return array(
			'hours'    => self::hours(),
			'checkout' => self::checkout(),
			'product'  => self::product(),
			'timezone' => self::timezone(),
			'payment'  => self::payment(),
		);
	}

	/**
	 * Zorunlu üç kontrol `ok` mu. Yalnız onları hesaplar (etkinleştirme sonrası `admin_init` yolunda da çalışır).
	 */
	public static function isComplete(): bool {
		return self::completeFrom(
			array(
				'hours'    => self::hours(),
				'checkout' => self::checkout(),
				'product'  => self::product(),
			)
		);
	}

	/**
	 * Hesaplanmış kontrollerden tamamlık (özet kontrolleri bir kez hesaplasın diye).
	 *
	 * @param array<string,array{state:string}> $checks `checks()` ya da en az zorunlu anahtarlar.
	 */
	public static function completeFrom( array $checks ): bool {
		foreach ( self::REQUIRED as $key ) {
			if ( 'ok' !== ( $checks[ $key ]['state'] ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Kayıtlı sihirbaz durumu.
	 *
	 * @return array{completed:bool,dismissed:bool}
	 */
	public static function state(): array {
		$opt = get_option( self::OPTION, array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array(
			'completed' => ! empty( $opt['completed_at'] ),
			'dismissed' => ! empty( $opt['dismissed'] ),
		);
	}

	/**
	 * Ödeme sayfası (ya da blok temanın ödeme şablonu) Checkout bloğunu içeriyor mu. GatewayNotice ile ortak ölçüt.
	 */
	public static function checkoutUsesBlock(): bool {
		if ( class_exists( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class ) ) {
			return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
		}
		$page = wc_get_page_id( 'checkout' );
		return $page > 0 && has_block( 'woocommerce/checkout', $page );
	}

	/**
	 * Ödeme sayfasını düzeltme adresi: sayfa varsa blok düzenleyici, yoksa WooCommerce Gelişmiş ayarları.
	 */
	public static function checkoutAction(): string {
		$page = wc_get_page_id( 'checkout' );
		if ( $page > 0 ) {
			$link = get_edit_post_link( $page, 'raw' );
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		return admin_url( 'admin.php?page=wc-settings&tab=advanced' );
	}

	/**
	 * Durum kaydı.
	 *
	 * @return array{state:string,detail:string}
	 */
	private static function hours(): array {
		foreach ( WeeklySchedule::load() as $ranges ) {
			if ( $ranges ) {
				return array(
					'state'  => 'ok',
					'detail' => __( 'Working hours are set.', 'mhm-slotiva' ),
				);
			}
		}
		return array(
			'state'  => 'todo',
			'detail' => __( 'No working hours yet.', 'mhm-slotiva' ),
		);
	}

	/**
	 * Durum kaydı.
	 *
	 * @return array{state:string,detail:string,action?:string}
	 */
	private static function checkout(): array {
		if ( wc_get_page_id( 'checkout' ) > 0 && self::checkoutUsesBlock() ) {
			return array(
				'state'  => 'ok',
				'detail' => __( 'The checkout page uses the Checkout block.', 'mhm-slotiva' ),
			);
		}
		return array(
			'state'  => 'todo',
			'detail' => __( 'The checkout page does not use the Checkout block.', 'mhm-slotiva' ),
			'action' => self::checkoutAction(),
		);
	}

	/**
	 * Durum kaydı.
	 *
	 * @return array{state:string,detail:string}
	 */
	private static function product(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- tek satırlık yönetici sorgusu.
					array(
						'key'   => '_slotiva_enabled',
						'value' => 'yes',
					),
				),
			)
		);
		if ( $ids ) {
			return array(
				'state'  => 'ok',
				'detail' => __( 'A bookable product is published.', 'mhm-slotiva' ),
			);
		}
		return array(
			'state'  => 'todo',
			'detail' => __( 'No published bookable product yet.', 'mhm-slotiva' ),
		);
	}

	/**
	 * Durum kaydı.
	 *
	 * @return array{state:string,detail:string}
	 */
	private static function timezone(): array {
		$tz = wp_timezone_string();
		if ( str_contains( $tz, '/' ) ) {
			$offset = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'P' );
			return array(
				'state'  => 'ok',
				/* translators: 1: time zone name, e.g. Europe/Istanbul; 2: current UTC offset, e.g. +03:00. */
				'detail' => sprintf( __( '%1$s (UTC%2$s) — customers see the hours in this time zone.', 'mhm-slotiva' ), $tz, $offset ),
			);
		}
		return array(
			'state'  => 'warn',
			/* translators: %s: fixed UTC offset, e.g. +03:00. */
			'detail' => sprintf( __( 'The site uses a fixed UTC offset (%s), which does not follow daylight saving changes.', 'mhm-slotiva' ), $tz ),
		);
	}

	/**
	 * Durum kaydı.
	 *
	 * @return array{state:string,detail:string}
	 */
	private static function payment(): array {
		// Yönetim bağlamında WC oturumu yok: get_available_payment_gateways() kullanılmaz (WC: "not in the WP admin or REST API
		// contexts"); açık (enabled) yöntemler sayılır. Üçüncü taraf bir geçit hata verirse sayfa düşmez, kontrol uyarı olur.
		$names = array();
		try {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				if ( 'yes' === $gateway->enabled ) {
					$names[] = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );
				}
			}
		} catch ( \Throwable $e ) {
			$names = array();
		}
		if ( $names ) {
			$count = count( $names );
			return array(
				'state'  => 'ok',
				'detail' => sprintf(
					/* translators: 1: number of enabled payment methods; 2: their names, comma separated. */
					_n( '%1$d method enabled: %2$s.', '%1$d methods enabled: %2$s.', $count, 'mhm-slotiva' ),
					$count,
					implode( ', ', array_filter( $names ) )
				),
			);
		}
		return array(
			'state'  => 'warn',
			'detail' => __( 'Turn one on in WooCommerce → Settings → Payments.', 'mhm-slotiva' ),
		);
	}
}
