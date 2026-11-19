<?php
/**
 * Herkese açık müsaitlik REST ucu: GET /slotiva/v1/availability.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Availability;

use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\Capacity;
use MHM\Slotiva\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- BookableProduct alan adları sözleşmeyle camelCase.

/**
 * Yanıt yalnız beyaz listedeki alanları taşır (kök: timezone, tz_label, days, slots; gün: date, open, free; dilim: start, label).
 * Dilime ek alan yalnız `slotiva_availability_slot` süzgecinden gelir (K8). Rezervasyon ya da müşteri kimliği hiçbir koşulda
 * yanıta girmez.
 */
final class RestController {

	public const REST_NAMESPACE = 'slotiva/v1';
	public const ROUTE          = '/availability';

	/** Sürüm sayacı seçeneği; birim tablosu ürünler arasında ortak olduğu için tek genel sayaç. */
	public const VERSION_OPTION = 'slotiva_avail_ver';

	/** IP başına dakikada en çok istek. */
	private const RATE_LIMIT = 60;

	/** Yanıt önbellek ömrü (sn). */
	private const CACHE_TTL = 60;

	/**
	 * Uç ve önbellek geçersiz kılma kancalarını kaydeder.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'slotiva_reservation_status', array( self::class, 'bump_version' ), 10, 4 );
		add_action( 'slotiva_reservation_rescheduled', array( self::class, 'bump_version' ), 10, 3 );
		add_action( 'slotiva_schedule_changed', array( self::class, 'bump_version' ), 10, 0 );
		// K9: kapasite Lite'ta yalnız süzgeçten gelir (ürün sekmesi alanı yok); kapasiteyi değiştiren taraf bu eylemi çağırır.
		add_action( 'slotiva_flush_availability', array( self::class, 'bump_version' ), 10, 0 );
	}

	/**
	 * İç dilimleri yanıt biçimine çevirir: Lite yalnız `start` + `label` koyar (K8; kapasite 1'de 1.0.3 ile bayt bayt aynı);
	 * ek alanlar yalnız `slotiva_availability_slot` süzgecinden gelir. Süzgeç dizi dönmezse dilim değişmeden kalır.
	 *
	 * @param list<array{start:string,label:string,seatsLeft:int}> $slots     İç dilimler (`SlotCalculator::seatSlots` / `adminSlots`).
	 * @param int                                                  $capacity  Ürünün şimdiki kapasitesi.
	 * @param int                                                  $product_id Ebeveyn ürün.
	 * @return list<array<string,mixed>>
	 */
	public static function slot_payload( array $slots, int $capacity, int $product_id ): array {
		$out = array();
		foreach ( $slots as $s ) {
			$slot     = array(
				'start' => $s['start'],
				'label' => $s['label'],
			);
			$filtered = apply_filters( 'slotiva_availability_slot', $slot, $s['seatsLeft'], $capacity, $product_id );
			$out[]    = is_array( $filtered ) ? $filtered : $slot;
		}
		return $out;
	}

	/**
	 * Rotayı kaydeder.
	 */
	public static function routes(): void {
		$date = array(
			'type'              => 'string',
			'validate_callback' => array( self::class, 'validate_date' ),
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'handle' ),
				// Herkese açık: yanıt yalnız tarih/sayı/saat taşır; müşteri ya da rezervasyon kimliği (PII) YOKTUR.
				'permission_callback' => '__return_true',
				'args'                => array(
					'product'   => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'variation' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'from'      => $date,
					'to'        => $date,
					'day'       => $date,
				),
			)
		);
	}

	/**
	 * Y-m-d biçimi ve gerçek takvim günü.
	 *
	 * @param mixed $value Değer.
	 */
	public static function validate_date( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Genel sürüm sayacını artırır (önbellek anahtarlarını eskitir). Olay bağımsız değişkenleri yok sayılır.
	 *
	 * @param mixed ...$args Olay bağımsız değişkenleri.
	 */
	public static function bump_version( ...$args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- kanca imzası: birden çok eyleme bağlı, bağımsız değişkenleri gerekmez.
		update_option( self::VERSION_OPTION, self::version() + 1, true );
	}

	/**
	 * İstek işleyici.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( \WP_REST_Request $request ) {
		// 1) Hız sınırı, önbellek aramasından ÖNCE.
		if ( self::hit() > self::RATE_LIMIT ) {
			return new \WP_Error( 'slotiva_rate_limited', __( 'Too many requests. Please try again in a moment.', 'mhm-slotiva' ), array( 'status' => 429 ) );
		}

		// 2) Ürün: randevulu ve yayında olmalı.
		$product = self::resolve_product( (int) $request['product'], (int) $request['variation'] );
		if ( null === $product ) {
			return new \WP_Error( 'slotiva_not_found', __( 'Product not found.', 'mhm-slotiva' ), array( 'status' => 404 ) );
		}

		// 3) Tarihler.
		$tz    = wp_timezone();
		$now   = Clock::now()->setTimezone( $tz );
		$today = $now->format( 'Y-m-d' );
		$day   = (string) ( $request['day'] ?? '' );
		$from  = (string) ( $request['from'] ?? '' );
		$to    = (string) ( $request['to'] ?? '' );
		if ( '' !== $day ) {
			$from = '' === $from ? $day : $from;
			$to   = '' === $to ? $day : $to;
		}
		$from = '' === $from ? $today : $from;
		if ( '' === $to ) {
			$to = $now->modify( '+' . $product->horizonDays . ' days' )->format( 'Y-m-d' );
		}
		foreach ( array( $from, $to, $day ) as $d ) {
			if ( '' !== $d && $d < $today ) {
				return new \WP_Error( 'slotiva_past_date', __( 'Past dates cannot be requested.', 'mhm-slotiva' ), array( 'status' => 400 ) );
			}
		}
		if ( $to < $from ) {
			return new \WP_Error( 'slotiva_bad_range', __( 'The end date cannot be before the start date.', 'mhm-slotiva' ), array( 'status' => 400 ) );
		}

		// Anahtar uzayı sınırlı kalsın: yalnız kırpılmış etkin değerler kullanılır, ufuk ötesi hiç önbelleğe girmez.
		$last = $now->modify( '+' . $product->horizonDays . ' days' )->format( 'Y-m-d' );
		if ( $from > $last ) {
			return new \WP_REST_Response( self::empty_body() );
		}
		$to = min( $to, $last, ( new \DateTimeImmutable( $from, $tz ) )->modify( '+61 days' )->format( 'Y-m-d' ) );
		if ( '' !== $day && $day > $last ) {
			$day = '';
		}

		// 4) Önbellek.
		$key    = sprintf( 'slotiva_avail_%d_%d-%s-%s-%s_%d', $product->productId, $product->variationId, $from, $to, $day, self::version() );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return new \WP_REST_Response( $cached );
		}

		// Kapasite istek başına bir kez (ebeveyn ürün; varyasyon aynı ürün, K3).
		$capacity = Capacity::forProduct( $product->productId );
		$body     = array(
			'timezone' => wp_timezone_string(),
			'tz_label' => self::tz_label(),
			'days'     => SlotCalculator::days( $product, $from, $to, $capacity ),
			'slots'    => '' === $day ? array() : self::slot_payload( SlotCalculator::seatSlots( $product, $day, $capacity ), $capacity, $product->productId ),
		);
		set_transient( $key, $body, self::CACHE_TTL );
		return new \WP_REST_Response( $body );
	}

	/**
	 * Randevulu ve yayında ürünü bulur; aksi null.
	 *
	 * @param int $product_id   Ürün.
	 * @param int $variation_id Varyasyon (0 = yok).
	 */
	private static function resolve_product( int $product_id, int $variation_id ): ?BookableProduct {
		$parent = wc_get_product( $product_id );
		if ( ! $parent || $parent->is_type( 'variation' ) || 'publish' !== get_post_status( $product_id ) || '' !== (string) get_post_field( 'post_password', $product_id ) ) {
			return null;
		}
		if ( 0 === $variation_id ) {
			return BookableProduct::from( $parent );
		}
		$variation = wc_get_product( $variation_id );
		if ( ! $variation || $variation->get_parent_id() !== $product_id || 'publish' !== get_post_status( $variation_id ) ) {
			return null;
		}
		return BookableProduct::from( $variation );
	}

	/**
	 * Sabit pencere sayacını bir artırır ve yeni değeri döndürür.
	 * Anahtar: slotiva_avail_{md5(REMOTE_ADDR)}_{floor(time()/60)}. X-Forwarded-For okunmaz (sahtelenebilir).
	 */
	private static function hit(): int {
		$addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'slotiva_avail_' . md5( $addr ) . '_' . (int) floor( Clock::now()->getTimestamp() / 60 );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'slotiva_rate', 2 * MINUTE_IN_SECONDS );
			$n = wp_cache_incr( $key, 1, 'slotiva_rate' );
			return false === $n ? 1 : (int) $n;
		}
		$n = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, 2 * MINUTE_IN_SECONDS );
		return $n;
	}

	/**
	 * Ufuk ötesi istek için boş yanıt (önbelleğe girmez).
	 *
	 * @return array{timezone:string,tz_label:string,days:array<never>,slots:array<never>}
	 */
	private static function empty_body(): array {
		return array(
			'timezone' => wp_timezone_string(),
			'tz_label' => self::tz_label(),
			'days'     => array(),
			'slots'    => array(),
		);
	}

	/** Önbellek anahtarı sürümü. */
	private static function version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/** Ör. "Europe/Istanbul (UTC+03:00)". */
	public static function tz_label(): string {
		$offset = wp_timezone()->getOffset( Clock::now() );
		$sign   = $offset < 0 ? '-' : '+';
		$abs    = abs( $offset );
		return sprintf( '%s (UTC%s%02d:%02d)', wp_timezone_string(), $sign, intdiv( $abs, 3600 ), intdiv( $abs % 3600, 60 ) );
	}
}
