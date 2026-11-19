<?php
/**
 * Yönetim REST API: `slotiva/v1/admin/*` (spec §8, §15.10). Her uç `manage_woocommerce` ister; çerez kimliği REST nonce'u
 * (`X-WP-Nonce`) ile. CSV indirme bağlantısı başlık gönderemediği için `export.csv` ayrıca `_wpnonce` sorgu parametresini
 * `wp_verify_nonce( …, 'wp_rest' )` ile doğrular ve ham CSV'yi `rest_pre_serve_request` ile basar.
 *
 * Günlüğe hiçbir şey yazılmaz (kişisel veri yok).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Admin;

use MHM\Slotiva\Availability\RestController as PublicAvailability;
use MHM\Slotiva\Availability\SlotCalculator;
use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Lifecycle\HoldScheduler;
use MHM\Slotiva\Notify\Format;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\Capacity;
use MHM\Slotiva\Reservation\EventLog;
use MHM\Slotiva\Reservation\HoldQuotaException;
use MHM\Slotiva\Reservation\LockBusyException;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Schedule\ExceptionRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Support\Fresh;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Reservation/BookableProduct alanları ve yöntem adları sözleşmeyle camelCase.

/**
 * Uçlar, şemalar ve randevu DTO'su.
 *
 * AppointmentDTO = {id, starts_at, ends_at, local_date, local_time, product:{id,name}, customer:{name,phone,email}, status,
 * payment_state, order:{id,url}|null, location}. Ürün adı rezervasyondaki anlık kopyadan (ürün silinse de).
 */
final class RestController {

	public const REST_NAMESPACE = 'slotiva/v1';
	public const BASE           = '/admin';
	public const CAPABILITY     = 'manage_woocommerce';

	/** `rest_pre_serve_request` ile ham basılan rota. */
	public const EXPORT_ROUTE = '/slotiva/v1/admin/export.csv';

	/** Liste sayfası üst sınırı (OFFSET taşmasın). */
	public const MAX_PAGE = 100000;

	/** Havale tutması en çok bu kadar saat uzatılabilir (tek istekte). */
	public const MAX_EXTEND_HOURS = 168;

	/** Kural alanları ve REST türleri. */
	private const RULE_TYPES = array(
		'durationDefault'       => 'integer',
		'leadHours'             => 'integer',
		'horizonDays'           => 'integer',
		'bufferMin'             => 'integer',
		'bankHoldHours'         => 'integer',
		'cardHoldMin'           => 'integer',
		'reminderOn'            => 'boolean',
		'reminderHours'         => 'integer',
		'policyText'            => 'string',
		'retentionMonths'       => 'integer',
		'deleteDataOnUninstall' => 'boolean',
	);

	private const UTC_FMT = 'Y-m-d\TH:i:s\Z';

	/**
	 * Kancaları kaydeder. Yönetim menüsü burada DEĞİL (Task 14–15).
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_csv' ), 10, 4 );
	}

	/**
	 * Rotaları kaydeder.
	 */
	public static function routes(): void {
		$ns   = self::REST_NAMESPACE;
		$perm = array( self::class, 'can_manage' );
		$id   = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$date = array(
			'type'              => 'string',
			'validate_callback' => array( self::class, 'validate_date' ),
		);
		$time = array(
			'type'              => 'string',
			'validate_callback' => array( self::class, 'validate_utc' ),
		);

		register_rest_route(
			$ns,
			self::BASE . '/appointments',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_appointments' ),
					'permission_callback' => $perm,
					'args'                => array(
						'tab'      => array(
							'type'    => 'string',
							'enum'    => array( 'today', 'upcoming', 'past', 'cancelled' ),
							'default' => 'upcoming',
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_PAGE,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'order'    => array(
							'type' => 'string',
							'enum' => array( 'asc', 'desc' ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_appointment' ),
					'permission_callback' => $perm,
					'args'                => array(
						'product_id'   => array(
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						),
						'variation_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
						'slot'         => $time + array( 'required' => true ),
						'customer'     => array(
							'type'                 => 'object',
							'required'             => true,
							'additionalProperties' => false,
							'properties'           => array(
								'name'  => array(
									'type'      => 'string',
									'required'  => true,
									'minLength' => 1,
									'maxLength' => 190,
								),
								'phone' => array(
									'type'      => 'string',
									'maxLength' => 60,
								),
								'email' => array(
									'type'      => 'string',
									'format'    => 'email',
									'maxLength' => 190,
								),
							),
						),
						'notify'       => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/appointments/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_appointment' ),
				'permission_callback' => $perm,
				'args'                => $id,
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/appointments/(?P<id>\d+)/reschedule',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'reschedule' ),
				'permission_callback' => $perm,
				'args'                => $id + array(
					'start'           => $time + array( 'required' => true ),
					'count_change'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'notify'          => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'expect_sequence' => array(
						'type'     => 'integer',
						'minimum'  => 0,
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/appointments/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'set_status' ),
				'permission_callback' => $perm,
				'args'                => $id + array(
					'status' => array(
						'type'     => 'string',
						'enum'     => array( 'completed', 'no_show', 'cancelled' ),
						'required' => true,
					),
					'notify' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/appointments/(?P<id>\d+)/extend-hold',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'extend_hold' ),
				'permission_callback' => $perm,
				'args'                => $id + array(
					'hours' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'maximum'  => self::MAX_EXTEND_HOURS,
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/availability',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'availability' ),
				'permission_callback' => $perm,
				'args'                => array(
					// Yalnız `exclude_reservation` yoksa zorunlu (handler denetler); saat değişiminde süre randevudan gelir.
					'product'             => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'variation'           => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'day'                 => $date + array( 'required' => true ),
					'exclude_reservation' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/schedule',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_schedule' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_schedule' ),
					'permission_callback' => $perm,
					'args'                => array(
						'days' => array(
							'type'     => 'array',
							'required' => true,
							'minItems' => 7,
							'maxItems' => 7,
							'items'    => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'properties'           => array(
										'from' => array(
											'type'     => 'string',
											'required' => true,
										),
										'to'   => array(
											'type'     => 'string',
											'required' => true,
										),
									),
								),
							),
						),
					),
				),
			)
		);

		$rule_args = array();
		foreach ( self::RULE_TYPES as $key => $type ) {
			$rule_args[ $key ] = array( 'type' => $type );
		}
		$rule_args['policyText']['maxLength'] = 5000;
		register_rest_route(
			$ns,
			self::BASE . '/rules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_rules' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'put_rules' ),
					'permission_callback' => $perm,
					'args'                => $rule_args,
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/exceptions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_exceptions' ),
					'permission_callback' => $perm,
					'args'                => array(
						'from' => $date,
						'to'   => $date,
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'add_exception' ),
					'permission_callback' => $perm,
					'args'                => array(
						'date'      => $date + array( 'required' => true ),
						'type'      => array(
							'type'     => 'string',
							'enum'     => array( 'open', 'closed' ),
							'required' => true,
						),
						'from_time' => array(
							'type'    => 'string',
							'pattern' => '^\d{1,2}:\d{2}$',
						),
						'to_time'   => array(
							'type'    => 'string',
							'pattern' => '^\d{1,2}:\d{2}$',
						),
						'note'      => array(
							'type'      => 'string',
							'maxLength' => 255,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/exceptions/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_exception' ),
				'permission_callback' => $perm,
				'args'                => $id,
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/export\.csv',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'export' ),
				'permission_callback' => array( self::class, 'can_export' ),
				'args'                => array(
					'_wpnonce' => array( 'type' => 'string' ),
					'from'     => $date,
					'to'       => $date,
				),
			)
		);
	}

	// ------------------------------------------------------------------------------------------------ yetki, doğrulama

	/**
	 * Her uç: `manage_woocommerce`. Çerez kimliğinde nonce'u çekirdek denetler (nonce yoksa kullanıcı 0 → 401).
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * CSV: yetki + `_wpnonce` sorgu parametresi (`wp_rest` eylemi). Uygulama parolasıyla da nonce şarttır.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return bool|\WP_Error
	 */
	public static function can_export( \WP_REST_Request $request ) {
		if ( ! self::can_manage() ) {
			return false;
		}
		$nonce = $request->get_query_params()['_wpnonce'] ?? '';
		if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'slotiva_invalid_nonce', __( 'The download link has expired. Reload the page and try again.', 'mhm-slotiva' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Y-m-d ve gerçek takvim günü.
	 *
	 * @param mixed $value Değer.
	 */
	public static function validate_date( $value ): bool {
		return PublicAvailability::validate_date( $value );
	}

	/**
	 * Saat dilimi taşıyan RFC 3339 zaman (`Z` ya da ±hh:mm).
	 *
	 * @param mixed $value Değer.
	 */
	public static function validate_utc( $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value )
			&& false !== rest_parse_date( $value );
	}

	// ------------------------------------------------------------------------------------------------ randevular

	/**
	 * GET /appointments → {items, total, stats}.
	 *
	 * @param \WP_REST_Request $request İstek.
	 */
	public static function list_appointments( \WP_REST_Request $request ): \WP_REST_Response {
		$f = array(
			'tab'      => (string) $request['tab'],
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		);
		if ( null !== $request['order'] ) {
			$f['order'] = (string) $request['order'];
		}
		$q = ReservationRepository::query( $f );
		return new \WP_REST_Response(
			array(
				'items' => array_map( array( self::class, 'dto' ), $q['items'] ),
				'total' => $q['total'],
				'stats' => ReservationRepository::stats(),
			)
		);
	}

	/**
	 * GET /appointments/{id} → DTO + events + minutes_to_start + reschedule_count (+ sequence, hold_expires_at).
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_appointment( \WP_REST_Request $request ) {
		$r = ReservationRepository::find( (int) $request['id'] );
		return null === $r ? self::not_found() : new \WP_REST_Response( self::detail( $r ) );
	}

	/**
	 * POST /appointments: elle randevu (sipariş yok, `payment_state=manual`, onaylı).
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_appointment( \WP_REST_Request $request ) {
		$resolved = self::resolve_product( (int) $request['product_id'], (int) $request['variation_id'] );
		if ( null === $resolved ) {
			return self::not_found();
		}
		[ $wc, $bp ] = $resolved;
		$start       = self::utc( (string) $request['slot'] );
		if ( ! SlotCalculator::isAdminBookable( $bp->durationMin, $start ) ) {
			return self::invalid_slot();
		}
		$customer = (array) $request['customer'];
		$name     = sanitize_text_field( (string) ( $customer['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'slotiva_invalid_value', __( 'Customer name is required.', 'mhm-slotiva' ), array( 'status' => 400 ) );
		}
		try {
			$ids = ReservationRepository::claim(
				array(
					'product_id'    => $bp->productId,
					'product_name'  => $wc->get_name(),
					'variation_id'  => $bp->variationId,
					'order_id'      => null,
					'slots'         => array( $start ),
					'duration'      => $bp->durationMin,
					'buffer'        => Rules::load()->bufferMin,
					'status'        => 'confirmed',
					'payment_state' => 'manual',
					'customer'      => array(
						'name'  => $name,
						'phone' => sanitize_text_field( (string) ( $customer['phone'] ?? '' ) ),
						'email' => sanitize_email( (string) ( $customer['email'] ?? '' ) ),
					),
					'location'      => $bp->location,
					'notify'        => (bool) $request['notify'],
				)
			);
		} catch ( SlotTakenException | LockBusyException | HoldQuotaException $e ) {
			return self::from_exception( $e );
		}
		$r = ReservationRepository::find( $ids[0] );
		if ( null === $r ) {
			return self::not_found();
		}
		return new \WP_REST_Response( self::dto( $r ), 201 );
	}

	/**
	 * POST /appointments/{id}/reschedule: yönetici ızgarası, kendi birimleri yok sayılarak.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function reschedule( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$r  = ReservationRepository::find( $id );
		if ( null === $r ) {
			return self::not_found();
		}
		if ( ! $r->isActive() && 'conflict' !== $r->status ) {
			return self::invalid_state(); // Çakışmalı (ödendi, saati yok) randevuya da yeni saat verilir (final review F3).
		}
		$start = self::utc( (string) $request['start'] );
		if ( ! SlotCalculator::isAdminBookable( self::minutes( $r ), $start ) ) {
			return self::invalid_slot();
		}
		try {
			$ok = ReservationRepository::reschedule( $id, $start, (bool) $request['count_change'], (bool) $request['notify'], (int) $request['expect_sequence'] );
		} catch ( SlotTakenException | LockBusyException $e ) {
			return self::from_exception( $e );
		} catch ( \LogicException $e ) {
			return self::invalid_state();
		}
		if ( ! $ok ) {
			return new \WP_Error( 'slotiva_stale', __( 'This appointment was changed in the meantime. Reload and try again.', 'mhm-slotiva' ), array( 'status' => 409 ) );
		}
		$fresh = ReservationRepository::find( $id );
		if ( null !== $fresh && null !== $fresh->orderId ) {
			// Siparişin kayıtlı talebi ve etiketi yeni saate (final review F1): order-pay saklı talebi okur (§15.4).
			CheckoutFlow::syncRequested( $fresh->orderId );
		}
		return null === $fresh ? self::not_found() : new \WP_REST_Response( self::detail( $fresh ) );
	}

	/**
	 * POST /appointments/{id}/status: completed|no_show (onaylı ya da birbirinin düzeltmesi), cancelled (held|confirmed;
	 * birimler serbest kalır; ya da birimsiz `conflict`, final review F3). Bağlı WC siparişine dokunulmaz.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function set_status( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$current = ReservationRepository::find( $id );
		if ( null === $current ) {
			return self::not_found();
		}
		$to  = (string) $request['status'];
		$ctx = array(
			'notify' => (bool) $request['notify'],
			'reason' => 'admin',
		);
		if ( in_array( $to, array( 'completed', 'no_show' ), true ) && $current->startsAt > Clock::now() ) {
			// Başlamamış randevu tamamlandı / gelmedi olamaz (final review M3); iptal ve diğer geçişler etkilenmez.
			return new \WP_Error( 'slotiva_invalid_state', __( 'An appointment can be marked completed or no-show only after it has started.', 'mhm-slotiva' ), array( 'status' => 409 ) );
		}
		try {
			if ( 'cancelled' === $to ) {
				$ok = ReservationRepository::release( $id, 'cancelled', $ctx + array( 'expect_status' => array_merge( ReservationRepository::ACTIVE, array( 'conflict' ) ) ) );
			} else {
				$from = array_values( array_diff( array( 'confirmed', 'completed', 'no_show' ), array( $to ) ) );
				$ok   = ReservationRepository::setStatus( $id, $to, null, $ctx + array( 'expect_status' => $from ) );
			}
		} catch ( LockBusyException $e ) {
			return self::from_exception( $e );
		} catch ( \LogicException $e ) {
			return self::invalid_state();
		}
		if ( ! $ok ) {
			return self::invalid_state();
		}
		if ( 'cancelled' === $to && null !== $current->orderId ) {
			// Çakışmalı randevu saat tutmaz: notu "saat boşaldı" demez (1.0.1).
			self::order_note(
				$current->orderId,
				sprintf(
					'conflict' === $current->status
						/* translators: 1: booking ID, 2: booking date and time, 3: administrator display name */
						? __( 'MHM Slotiva: booking #%1$d (%2$s) was cancelled by %3$s. It was in conflict and held no time slot, so no time was freed. The order itself was not changed; cancel or refund it in WooCommerce if needed.', 'mhm-slotiva' )
						/* translators: 1: booking ID, 2: booking date and time, 3: administrator display name */
						: __( 'MHM Slotiva: booking #%1$d (%2$s) was cancelled by %3$s. The time slot is free again. The order itself was not changed; cancel or refund it in WooCommerce if needed.', 'mhm-slotiva' ),
					$current->id,
					Format::when( $current ),
					self::actor_name()
				)
			);
		}
		$fresh = ReservationRepository::find( $id );
		return null === $fresh ? self::not_found() : new \WP_REST_Response( self::detail( $fresh ) );
	}

	/**
	 * POST /appointments/{id}/extend-hold: yalnız tutulan havale (held + awaiting_transfer). Yeni bitiş =
	 * max(şimdi, mevcut bitiş) + saat; siparişin süresi geçmemiş tüm tutmaları birlikte uzar.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function extend_hold( \WP_REST_Request $request ) {
		$r = ReservationRepository::find( (int) $request['id'] );
		if ( null === $r ) {
			return self::not_found();
		}
		$now = Clock::now();
		if ( 'held' !== $r->status || 'awaiting_transfer' !== $r->paymentState || null === $r->orderId || null === $r->holdExpiresAt || $r->holdExpiresAt <= $now ) {
			return self::not_extendable();
		}
		$until = max( $now, $r->holdExpiresAt )->modify( '+' . (int) $request['hours'] . ' hours' );
		try {
			$n = HoldScheduler::extend( $r->orderId, $until );
		} catch ( LockBusyException $e ) {
			return self::from_exception( $e );
		}
		if ( 0 === $n ) {
			return self::not_extendable();
		}
		$hours = (int) $request['hours'];
		$iso   = $until->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::UTC_FMT );
		foreach ( ReservationRepository::forOrder( $r->orderId ) as $row ) {
			if ( 'held' === $row->status && null !== $row->holdExpiresAt && $row->holdExpiresAt->getTimestamp() === $until->getTimestamp() ) {
				EventLog::add(
					$row->id,
					'hold_extended',
					array(
						'until' => $iso,
						'hours' => $hours,
					)
				);
			}
		}
		self::order_note(
			$r->orderId,
			sprintf(
				/* translators: 1: number of hours, 2: new deadline (local date and time), 3: administrator display name */
				__( 'MHM Slotiva: the bank-transfer payment deadline was extended by %1$d hours to %2$s by %3$s.', 'mhm-slotiva' ),
				$hours,
				wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $until->getTimestamp() ),
				self::actor_name()
			)
		);
		return new \WP_REST_Response(
			array(
				'extended'        => $n,
				'order_id'        => $r->orderId,
				'hold_expires_at' => $iso,
			)
		);
	}

	// ------------------------------------------------------------------------------------------------ müsaitlik

	/**
	 * GET /availability: yönetici ızgarası (15 dk, ön süre/ufuk yok); `exclude_reservation` o randevunun birimlerini dolu
	 * saymaz ve süreyi o randevudan alır. Grup ürününde yalnız yeri kalan kural 4 başlangıçları (K9; kabulle aynı karar).
	 * Dilim alanları müşteri ucuyla aynı: `start`, `label` + yalnız `slotiva_availability_slot` süzgecinden gelenler.
	 * Önbellek ve hız sınırı yok (yalnız yetkili).
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function availability( \WP_REST_Request $request ) {
		$exclude = (int) ( $request['exclude_reservation'] ?? 0 );
		if ( $exclude > 0 ) {
			// Saat değişimi: süre randevunun kendisinden; ürün silinmiş/çöpte olabilir, aranmaz.
			$r = ReservationRepository::find( $exclude );
			if ( null === $r ) {
				return self::not_found();
			}
			$duration = self::minutes( $r );
			// K4 taşıma: gelenin kapasitesi rezervasyonun anlık görüntüsü (yeniden damgalanmaz).
			$pid      = $r->productId;
			$current  = Capacity::forProduct( $pid );
			$capacity = $r->capacity;
		} else {
			if ( null === $request['product'] ) {
				return new \WP_Error(
					'rest_missing_callback_param',
					/* translators: %s: parameter name */
					sprintf( __( 'Missing parameter(s): %s', 'mhm-slotiva' ), 'product' ),
					array(
						'status' => 400,
						'params' => array( 'product' ),
					)
				);
			}
			$resolved = self::resolve_product( (int) $request['product'], (int) $request['variation'] );
			if ( null === $resolved ) {
				return self::not_found();
			}
			$duration = $resolved[1]->durationMin;
			$pid      = $resolved[1]->productId;
			$current  = Capacity::forProduct( $pid );
			$capacity = $current;
		}
		$day = (string) $request['day'];
		return new \WP_REST_Response(
			array(
				'timezone' => wp_timezone_string(),
				'tz_label' => PublicAvailability::tz_label(),
				'day'      => $day,
				'step'     => Rules::STEP,
				'slots'    => PublicAvailability::slot_payload( SlotCalculator::adminSlots( $duration, Rules::load()->bufferMin, $day, $exclude, $pid, $capacity, $current ), $current, $pid ),
			)
		);
	}

	// ------------------------------------------------------------------------------------------------ çalışma saatleri

	/**
	 * GET /schedule → {days: 7 × list<{from,to}>} (0 = Pazartesi).
	 */
	public static function get_schedule(): \WP_REST_Response {
		return new \WP_REST_Response( array( 'days' => array_values( WeeklySchedule::load() ) ) );
	}

	/**
	 * PUT /schedule: tüm düzeni değiştirir. Yeni saatlerin dışında kalan etkin randevular engellemez, `outside_hours`
	 * ile uyarılır (spec §8). `affected` = bu kimliklerin sayısı: gelecekteki `confirmed` **ve** `held` satırlar
	 * (tutulan satır ödeme gelince saat denetimi yapılmadan onaylanır; o da düzenin dışında kalan bir onaya döner).
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_schedule( \WP_REST_Request $request ) {
		$saved = WeeklySchedule::save( (array) $request['days'] );
		if ( is_wp_error( $saved ) ) {
			return self::bad_request( $saved );
		}
		$outside = self::outside_hours();
		return new \WP_REST_Response(
			array(
				'days'          => array_values( WeeklySchedule::load() ),
				'outside_hours' => $outside,
				'affected'      => count( $outside ),
			)
		);
	}

	/**
	 * GET /rules → kural alanları (camelCase).
	 */
	public static function get_rules(): \WP_REST_Response {
		return new \WP_REST_Response( self::rules_body( Rules::load() ) );
	}

	/**
	 * PUT /rules: verilen alanlar işlenir, verilmeyenler korunur.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function put_rules( \WP_REST_Request $request ) {
		$in = array();
		foreach ( array_keys( self::RULE_TYPES ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$in[ $key ] = $request[ $key ];
			}
		}
		// Kaldırmada TÜM randevu verisini silen bayrak yalnız `manage_options` ile değişir (final review F11): mağaza
		// yöneticisi kurabilseydi yöneticinin sonraki "sil"i veriyi yok ederdi. Ayar ekranı tüm alanları gönderir; aynı
		// değer engel değildir.
		$current = Rules::load()->deleteDataOnUninstall;
		if ( array_key_exists( 'deleteDataOnUninstall', $in ) && $current !== (bool) $in['deleteDataOnUninstall'] && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'slotiva_forbidden_field', __( 'Only an administrator can change whether booking data is deleted on uninstall.', 'mhm-slotiva' ), array( 'status' => 403 ) );
		}
		$rules = Rules::save( $in );
		if ( is_wp_error( $rules ) ) {
			return self::bad_request( $rules );
		}
		return new \WP_REST_Response( self::rules_body( $rules ) );
	}

	/**
	 * GET /exceptions?from=&to= (vars. bugün … +366 gün) → {items}.
	 *
	 * @param \WP_REST_Request $request İstek.
	 */
	public static function list_exceptions( \WP_REST_Request $request ): \WP_REST_Response {
		$today = Clock::now()->setTimezone( wp_timezone() );
		$from  = (string) ( $request['from'] ?? '' );
		$to    = (string) ( $request['to'] ?? '' );
		$from  = '' === $from ? $today->format( 'Y-m-d' ) : $from;
		$to    = '' === $to ? $today->modify( '+366 days' )->format( 'Y-m-d' ) : $to;
		return new \WP_REST_Response( array( 'items' => ExceptionRepository::between( $from, $to ) ) );
	}

	/**
	 * POST /exceptions → 201 {id, date, from_time, to_time, type, note, outside_hours}.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function add_exception( \WP_REST_Request $request ) {
		$date = (string) $request['date'];
		$id   = ExceptionRepository::add(
			array(
				'date'      => $date,
				'type'      => (string) $request['type'],
				'from_time' => (string) ( $request['from_time'] ?? '' ),
				'to_time'   => (string) ( $request['to_time'] ?? '' ),
				'note'      => (string) ( $request['note'] ?? '' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return self::bad_request( $id );
		}
		$row = array( 'id' => $id );
		foreach ( ExceptionRepository::between( $date, $date ) as $e ) {
			if ( $e['id'] === $id ) {
				$row = $e;
			}
		}
		return new \WP_REST_Response( $row + array( 'outside_hours' => self::outside_hours() ), 201 );
	}

	/**
	 * DELETE /exceptions/{id}.
	 *
	 * @param \WP_REST_Request $request İstek.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function delete_exception( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$deleted = ExceptionRepository::delete( $id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted; // Veritabanı hatası: 500 (satır bulunamadı değil).
		}
		if ( ! $deleted ) {
			return self::not_found();
		}
		return new \WP_REST_Response(
			array(
				'deleted'       => true,
				'id'            => $id,
				'outside_hours' => self::outside_hours(),
			)
		);
	}

	// ------------------------------------------------------------------------------------------------ CSV

	/**
	 * GET /export.csv: gövde ham CSV dizgisi; `serve_csv()` JSON kodlamasını atlayıp basar.
	 *
	 * @param \WP_REST_Request $request İstek.
	 */
	public static function export( \WP_REST_Request $request ): \WP_REST_Response {
		$csv = Csv::build(
			array(
				'from' => (string) ( $request['from'] ?? '' ),
				'to'   => (string) ( $request['to'] ?? '' ),
			)
		);
		$res = new \WP_REST_Response( $csv, 200 );
		$res->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$res->header( 'Content-Disposition', 'attachment; filename="slotiva-' . Clock::now()->setTimezone( wp_timezone() )->format( 'Y-m-d' ) . '.csv"' );
		$res->header( 'X-Content-Type-Options', 'nosniff' );
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( '' !== (string) $value ) {
				$res->header( $name, (string) $value );
			}
		}
		return $res;
	}

	/**
	 * `rest_pre_serve_request`: yalnız CSV rotasının başarılı yanıtını ham basar (başlıklar çekirdekçe zaten gönderildi).
	 * Hata yanıtları JSON kalır.
	 *
	 * @param bool                    $served  Daha önce sunuldu mu.
	 * @param \WP_HTTP_Response|mixed $result  Yanıt.
	 * @param \WP_REST_Request|mixed  $request İstek.
	 * @param \WP_REST_Server|mixed   $server  Sunucu.
	 */
	public static function serve_csv( $served, $result, $request, $server ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- kanca imzası.
		if ( $served ) {
			return (bool) $served;
		}
		// Çekirdek rotaları büyük/küçük harf duyarsız eşler (`@i`); `get_route()` ham yolu döndürür.
		if ( ! $request instanceof \WP_REST_Request || 0 !== strcasecmp( $request->get_route(), self::EXPORT_ROUTE ) ) {
			return false;
		}
		if ( ! $result instanceof \WP_HTTP_Response || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return false;
		}
		if ( 'HEAD' !== $request->get_method() ) {
			// Ham CSV gövdesi (text/csv + nosniff), HTML değil: çıktı akışına yazılır. Hücreler Csv::cell ile formül korumalı.
			echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw text/csv download (nosniff), not HTML; cells are formula-safe via Csv::cell.
		}
		return true;
	}

	// ------------------------------------------------------------------------------------------------ DTO

	/**
	 * AppointmentDTO.
	 *
	 * @param Reservation $r Rezervasyon.
	 * @return array<string,mixed>
	 */
	public static function dto( Reservation $r ): array {
		$utc   = new \DateTimeZone( 'UTC' );
		$local = $r->startsAt->setTimezone( wp_timezone() );
		$order = null;
		if ( null !== $r->orderId ) {
			$wc    = wc_get_order( $r->orderId );
			$order = array(
				'id'  => $r->orderId,
				'url' => $wc instanceof \WC_Order ? $wc->get_edit_order_url() : '',
			);
		}
		return array(
			'id'            => $r->id,
			'starts_at'     => $r->startsAt->setTimezone( $utc )->format( self::UTC_FMT ),
			'ends_at'       => $r->endsAt->setTimezone( $utc )->format( self::UTC_FMT ),
			'local_date'    => $local->format( 'Y-m-d' ),
			'local_time'    => $local->format( 'H:i' ),
			'product'       => array(
				'id'   => $r->productId,
				'name' => self::productName( $r ),
			),
			'customer'      => array(
				'name'  => $r->customerName,
				'phone' => $r->customerPhone,
				'email' => $r->customerEmail,
			),
			'status'        => $r->status,
			'payment_state' => $r->paymentState,
			'order'         => $order,
			'location'      => $r->locationText,
		);
	}

	/**
	 * Ürün adı: anlık kopya; boşsa (eski satır) canlı ürün; o da yoksa "Product #id".
	 *
	 * @param Reservation $r Rezervasyon.
	 */
	public static function productName( Reservation $r ): string {
		if ( '' !== $r->productName ) {
			return $r->productName;
		}
		$p = wc_get_product( $r->variationId > 0 ? $r->variationId : $r->productId );
		/* translators: %d: product ID */
		return $p instanceof \WC_Product ? $p->get_name() : sprintf( __( 'Product #%d', 'mhm-slotiva' ), $r->productId );
	}

	/**
	 * Ayrıntı yanıtı.
	 *
	 * @param Reservation $r Rezervasyon.
	 * @return array<string,mixed>
	 */
	private static function detail( Reservation $r ): array {
		$utc    = new \DateTimeZone( 'UTC' );
		$events = array();
		foreach ( EventLog::for( $r->id ) as $e ) {
			$at       = new \DateTimeImmutable( $e['created_at'], $utc );
			$events[] = array(
				'type'       => $e['type'],
				'actor'      => $e['actor'],
				'data'       => (object) $e['data'],
				'created_at' => $at->format( self::UTC_FMT ),
			);
		}
		return self::dto( $r ) + array(
			'events'           => $events,
			'minutes_to_start' => (int) floor( ( $r->startsAt->getTimestamp() - Clock::now()->getTimestamp() ) / 60 ),
			'reschedule_count' => $r->rescheduleCount,
			'sequence'         => $r->sequence,
			'hold_expires_at'  => null === $r->holdExpiresAt ? null : $r->holdExpiresAt->setTimezone( $utc )->format( self::UTC_FMT ),
		);
	}

	// ------------------------------------------------------------------------------------------------ yardımcılar

	/**
	 * Randevulu ürün (her yayın durumu; yönetici taslak ürüne de ekleyebilir).
	 *
	 * @param int $product_id   Ürün.
	 * @param int $variation_id Varyasyon (0 = yok).
	 * @return array{0:\WC_Product,1:BookableProduct}|null
	 */
	private static function resolve_product( int $product_id, int $variation_id ): ?array {
		$parent = wc_get_product( $product_id );
		if ( ! $parent instanceof \WC_Product || $parent->is_type( 'variation' ) || 'trash' === get_post_status( $product_id ) ) {
			return null;
		}
		$wc = $parent;
		if ( $variation_id > 0 ) {
			$wc = wc_get_product( $variation_id );
			if ( ! $wc instanceof \WC_Product || $wc->get_parent_id() !== $product_id ) {
				return null;
			}
		}
		$bp = BookableProduct::from( $wc );
		return null === $bp ? null : array( $wc, $bp );
	}

	/**
	 * Sipariş notu (yalnız yöneticiye görünür; müşteriye gitmez).
	 *
	 * @param int    $order_id Sipariş.
	 * @param string $text     Metin.
	 */
	private static function order_note( int $order_id, string $text ): void {
		$order = Fresh::order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note( $text );
		}
	}

	/** İşlemi yapan yöneticinin görünen adı. */
	private static function actor_name(): string {
		$user = wp_get_current_user();
		return $user->exists() ? $user->display_name : __( 'an administrator', 'mhm-slotiva' );
	}

	/**
	 * Etkin randevunun süresi (ara hariç, dk).
	 *
	 * @param Reservation $r Rezervasyon.
	 */
	private static function minutes( Reservation $r ): int {
		return (int) ( ( $r->endsAt->getTimestamp() - $r->startsAt->getTimestamp() ) / 60 );
	}

	/**
	 * Doğrulanmış RFC 3339 zamanı UTC nesneye.
	 *
	 * @param string $value Zaman.
	 */
	private static function utc( string $value ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( $value ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Şimdiden sonraki etkin randevulardan çalışma saatleri dışında kalanların kimlikleri. Tavan yok: satırlar sayfa sayfa
	 * (`slotiva_outside_hours_batch`, vars. 500) gezilir; haftalık düzen ve bugünden sonraki istisnalar bir kez okunur.
	 *
	 * @return list<int>
	 */
	private static function outside_hours(): array {
		$weekly  = WeeklySchedule::load();
		$today   = Clock::now()->setTimezone( wp_timezone() )->modify( '-1 day' )->format( 'Y-m-d' );
		$by_date = array();
		foreach ( ExceptionRepository::between( $today, '9999-12-31' ) as $e ) {
			$by_date[ $e['date'] ][] = $e;
		}
		/**
		 * Çalışma saati uyarısında bir sayfada okunan rezervasyon sayısı.
		 *
		 * @param int $batch Sayfa boyu.
		 */
		$batch = max( 1, (int) apply_filters( 'slotiva_outside_hours_batch', 500 ) );
		$out   = array();
		$after = null;
		$id    = 0;
		do {
			$page = ReservationRepository::upcomingActive( $batch, $after, $id );
			foreach ( $page as $r ) {
				if ( ! SlotCalculator::withinOpenHours( $r->startsAt, self::minutes( $r ), $by_date, $weekly ) ) {
					$out[] = $r->id;
				}
				$after = $r->startsAt;
				$id    = $r->id;
			}
			$full = count( $page ) === $batch;
		} while ( $full );
		return $out;
	}

	/**
	 * Kural yanıtı.
	 *
	 * @param Rules $rules Kurallar.
	 * @return array<string,mixed>
	 */
	private static function rules_body( Rules $rules ): array {
		$out = array();
		foreach ( array_keys( self::RULE_TYPES ) as $key ) {
			$out[ $key ] = $rules->{$key};
		}
		return $out;
	}

	/**
	 * Depo istisnasını REST hatasına çevirir.
	 *
	 * @param \RuntimeException $e İstisna.
	 */
	private static function from_exception( \RuntimeException $e ): \WP_Error {
		if ( $e instanceof SlotTakenException ) {
			return new \WP_Error(
				'slotiva_slot_taken',
				__( 'That time is no longer available.', 'mhm-slotiva' ),
				array(
					'status' => 409,
					'taken'  => array_map( static fn( \DateTimeImmutable $t ): string => $t->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::UTC_FMT ), $e->taken ),
				)
			);
		}
		if ( $e instanceof HoldQuotaException ) {
			return new \WP_Error( 'slotiva_hold_quota', __( 'Too many pending bookings.', 'mhm-slotiva' ), array( 'status' => 429 ) );
		}
		return new \WP_Error( 'slotiva_busy', __( 'This booking is being updated. Please try again in a moment.', 'mhm-slotiva' ), array( 'status' => 409 ) );
	}

	/**
	 * Doğrulama hatasına 400 ekler.
	 *
	 * @param \WP_Error $e Hata.
	 */
	private static function bad_request( \WP_Error $e ): \WP_Error {
		$data = $e->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return $e; // Sunucu hatası (ör. veritabanı, 500) doğrulama hatasına çevrilmez.
		}
		$e->add_data( array( 'status' => 400 ) );
		return $e;
	}

	/** 404. */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'slotiva_not_found', __( 'Not found.', 'mhm-slotiva' ), array( 'status' => 404 ) );
	}

	/** 400: yönetici ızgarası dışı. */
	private static function invalid_slot(): \WP_Error {
		return new \WP_Error( 'slotiva_invalid_slot', __( 'That time is outside working hours or not on the 15-minute grid.', 'mhm-slotiva' ), array( 'status' => 400 ) );
	}

	/** 409: durum geçişe uygun değil. */
	private static function invalid_state(): \WP_Error {
		return new \WP_Error( 'slotiva_invalid_state', __( 'This appointment cannot be changed in its current status.', 'mhm-slotiva' ), array( 'status' => 409 ) );
	}

	/** 409: uzatılamaz. */
	private static function not_extendable(): \WP_Error {
		return new \WP_Error( 'slotiva_not_extendable', __( 'Only an unexpired bank-transfer hold can be extended.', 'mhm-slotiva' ), array( 'status' => 409 ) );
	}
}
