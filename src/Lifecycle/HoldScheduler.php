<?php
/**
 * Tutma süresi işleri ve süpürücü (spec §6.4, §15.2, §15.7).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Lifecycle;

use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Reservation\LockBusyException;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Support\Fresh;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar sözleşmeyle camelCase.

/**
 * Her `held` satır için tek Action Scheduler işi (`slotiva_hold_expire`), argümanları §15.7 gereği
 * `reservation_id` + beklenen `sequence` + beklenen `hold_expires_at`. İşleyici satır kilidi altında (depo `expect_*`)
 * hepsini yeniden doğrular; uymazsa hiçbir şey yapmaz. Doğruluk işe bağlı değildir: süresi geçmiş tutma okuma anında
 * zaten boş sayılır (Task 6b tembel süre dolumu); iş ve süpürücü temizliktir.
 *
 * Kart siparişi ASLA otomatik iptal edilmez (§15.2): yalnız saat bırakılır, sipariş `pending/failed` kalır, PayTR geri
 * çağrısı her zaman işleyebilir. WC'nin ödenmemiş sipariş temizliği Slotiva siparişleri için kapatılır. Otomatik iptal
 * yalnız açık son tarihli havale/çek (`on-hold`) siparişinde.
 */
final class HoldScheduler {

	public const GROUP        = 'slotiva';
	public const EXPIRE_HOOK  = 'slotiva_hold_expire';
	public const SWEEP_HOOK   = 'slotiva_sweep';
	public const SWEEP_EVERY  = 300;
	private const FMT         = 'Y-m-d H:i:s';
	private const SWEEP_BATCH = 100;

	/** `init` başına Action Scheduler sorgusu yememek için kurulum denetimi en çok bu sıklıkla yapılır (saniye). */
	private const ENSURE_EVERY = 3600;

	/** Seçenek: son kurulum denetimi (otomatik yüklenir: ek sorgu yok; Retention ile aynı kalıp). */
	private const ENSURED_OPTION = 'slotiva_sweep_checked';

	/** Tembel süre dolumuyla düşmüş havale satırları bu kadar gün geriye taranır (süpürücü sınırı). */
	private const BANK_LOOKBACK_DAYS = 7;

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'ensureRecurring' ) );
		add_action( self::EXPIRE_HOOK, array( self::class, 'expire' ), 10, 3 );
		add_action( self::SWEEP_HOOK, array( self::class, 'sweep' ) );
		add_action( 'slotiva_reservation_status', array( self::class, 'onStatus' ), 10, 3 );
		add_action( 'slotiva_hold_refreshed', array( self::class, 'onHoldRefreshed' ) );
		add_action( 'slotiva_reservation_rescheduled', array( self::class, 'onRescheduled' ) );
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::class, 'keepUnpaidOrder' ), 10, 2 );
	}

	/**
	 * 5 dakikalık süpürücüyü bir kez kurar (`init`); denetim saatte en çok bir kez sorgu yapar (final review F8).
	 */
	public static function ensureRecurring(): void {
		if ( ! self::ready() || time() - (int) get_option( self::ENSURED_OPTION, 0 ) < self::ENSURE_EVERY ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::SWEEP_HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + self::SWEEP_EVERY, self::SWEEP_EVERY, self::SWEEP_HOOK, array(), self::GROUP );
		}
		update_option( self::ENSURED_OPTION, time() );
	}

	/**
	 * Süre dolumu işi. Satır hâlâ `held`, sequence ve bitiş beklenenle aynı ve bitiş geçmişse `expired` (birimler silinir).
	 * Havale/çek (`slotiva_transfer_methods`) siparişinin `awaiting_transfer` satırı `bank_expired` nedeniyle düşer; sipariş
	 * `on-hold`, etkin satırı kalmamış ve ödeme izi yoksa iptal edilir (e-postayı Task 11 gönderir). Diğer ağ geçitleri
	 * (`on-hold` kullanan kart: PayTR `wc-on-hold`, yalnız yetkilendirme, sahtecilik incelemesi) İPTAL EDİLMEZ (§15.2):
	 * yalnız dilim bırakılır ve not düşülür.
	 *
	 * @param int|mixed    $reservationId Rezervasyon.
	 * @param int|mixed    $sequence      Beklenen sequence.
	 * @param string|mixed $holdExpiresAt Beklenen bitiş (UTC, Y-m-d H:i:s).
	 */
	public static function expire( $reservationId, $sequence, $holdExpiresAt ): void {
		$id   = (int) $reservationId;
		$want = \DateTimeImmutable::createFromFormat( self::FMT, (string) $holdExpiresAt, new \DateTimeZone( 'UTC' ) );
		$r    = ReservationRepository::find( $id );
		if ( ! $want || null === $r || 'held' !== $r->status || $r->sequence !== (int) $sequence
			|| null === $r->holdExpiresAt || $r->holdExpiresAt->format( self::FMT ) !== $want->format( self::FMT )
			|| $r->holdExpiresAt > Clock::now() ) {
			return;
		}
		// Ödeme kaydı var ama dinleyici kaçırmışsa (ör. kilit meşguldü) tutma düşürülmez: önce onay denenir.
		if ( null !== $r->orderId && PaymentListener::isPaidOrder( $r->orderId ) ) {
			PaymentListener::onPaid( $r->orderId );
			return;
		}
		$awaiting = 'awaiting_transfer' === $r->paymentState;
		$bank     = $awaiting && null !== $r->orderId && self::isTransferOrder( $r->orderId );
		try {
			$done = ReservationRepository::release(
				$id,
				'expired',
				array(
					'reason'                 => $bank ? 'bank_expired' : 'hold_expired',
					'expect_status'          => array( 'held' ),
					'expect_sequence'        => (int) $sequence,
					'expect_hold_expires_at' => $want,
				)
			);
		} catch ( \Throwable $e ) {
			self::log( 'expire', $id, $e );
			return; // Süpürücü yeniden dener.
		}
		if ( ! $done || null === $r->orderId ) {
			return;
		}
		if ( $bank ) {
			self::cancelBankOrder( $r->orderId );
		} elseif ( $awaiting ) {
			$order = Fresh::order( $r->orderId );
			if ( $order && $order->has_status( 'on-hold' ) ) {
				$order->add_order_note( __( 'MHM Slotiva: the booking hold ended before the payment was confirmed. The time slot has been released; the order was not cancelled because its payment method is not a bank transfer. If the payment is completed, the slot is booked again when free, otherwise it is marked as a conflict.', 'mhm-slotiva' ) );
			}
		}
	}

	/**
	 * Süpürücü (5 dk): işi kaçmış süresi geçmiş `held` satırlar + tembel süre dolumuyla düşmüş havale satırlarının
	 * `on-hold` siparişleri. Kart `pending/failed` siparişlerine DOKUNMAZ (§15.2).
	 */
	public static function sweep(): void {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$now = Clock::now();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'held' AND hold_expires_at IS NOT NULL AND hold_expires_at <= %s ORDER BY hold_expires_at ASC, id ASC LIMIT %d", $res, $now->format( self::FMT ), self::SWEEP_BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi tablosu (rezervasyonlar); süpürücü (cron) taze durumu okumalı: önbellek süresi dolmuş tutmayı kaçırır.
		foreach ( (array) $rows as $row ) {
			$r = Reservation::fromRow( (array) $row );
			self::expire( $r->id, $r->sequence, (string) $r->holdExpiresAt?->format( self::FMT ) );
		}

		$since  = $now->modify( '-' . self::BANK_LOOKBACK_DAYS . ' days' )->format( self::FMT );
		$orders = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM %i WHERE status = 'expired' AND payment_state = 'awaiting_transfer' AND order_id IS NOT NULL AND updated_at >= %s ORDER BY order_id DESC LIMIT %d", $res, $since, self::SWEEP_BATCH ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi tablosu (rezervasyonlar); süpürücü (cron) taze durumu okumalı: önbellek süresi dolmuş tutmayı kaçırır.
		foreach ( (array) $orders as $orderId ) {
			self::cancelBankOrder( (int) $orderId );
		}
	}

	/**
	 * Yönetici "süreyi uzat" (havale yapılmış ama onaylanmamış olabilir): siparişin süresi geçmemiş tutmalarını uzatır ve
	 * işleri yeniden kurar. Süresi geçmiş tutma canlandırılmaz.
	 *
	 * @param int                $orderId Sipariş.
	 * @param \DateTimeImmutable $until   Yeni bitiş.
	 * @return int Uzatılan satır sayısı.
	 * @throws LockBusyException Sipariş kilidi alınamadı (çağıran 409 `slotiva_busy`'ye çevirir).
	 */
	public static function extend( int $orderId, \DateTimeImmutable $until ): int {
		$n = ReservationRepository::refreshHold( $orderId, $until );
		self::rescheduleOrder( $orderId );
		return $n;
	}

	/**
	 * Siparişin satırlarının işlerini güncel (sequence, bitiş) argümanlarla yeniden kurar; `held` olmayanlarınkini siler.
	 *
	 * @param int $orderId Sipariş.
	 */
	public static function rescheduleOrder( int $orderId ): void {
		foreach ( ReservationRepository::forOrder( $orderId ) as $r ) {
			self::unschedule( $r->id );
			self::schedule( $r );
		}
	}

	/**
	 * `slotiva_reservation_status` (COMMIT sonrası): yeni `held` → iş kur; `held`den çıkış → iş kaldır.
	 *
	 * @param int|mixed    $id  Rezervasyon.
	 * @param string|mixed $old Eski durum ('' = yeni satır).
	 * @param string|mixed $to  Yeni durum.
	 */
	public static function onStatus( $id, $old, $to ): void {
		if ( 'held' === $to ) {
			$r = ReservationRepository::find( (int) $id );
			if ( $r ) {
				self::unschedule( $r->id );
				self::schedule( $r );
			}
		} elseif ( 'held' === $old ) {
			self::unschedule( (int) $id );
		}
	}

	/**
	 * `slotiva_hold_refreshed( $orderId, $expiresAt, $ids )` (Task 8 yeniden kullanım): işler yeni bitişe taşınır.
	 *
	 * @param int|mixed $orderId Sipariş.
	 */
	public static function onHoldRefreshed( $orderId ): void {
		self::rescheduleOrder( (int) $orderId );
	}

	/**
	 * Saat değişti (sequence arttı): tutulan satırın işi yeni sequence ile yeniden kurulur.
	 *
	 * @param int|mixed $id Rezervasyon.
	 */
	public static function onRescheduled( $id ): void {
		$r = ReservationRepository::find( (int) $id );
		if ( $r ) {
			self::unschedule( $r->id );
			self::schedule( $r );
		}
	}

	/**
	 * WC ödenmemiş sipariş temizliği (`wc_cancel_unpaid_orders`, yalnız stok takibi açıkken koşar) Slotiva siparişini
	 * iptal etmez: iptal edilmiş siparişe gelen PayTR ödemesi sessizce kaybolurdu (§15.2).
	 *
	 * @param bool|mixed $cancel Önceki karar.
	 * @param mixed      $order  Sipariş.
	 * @return bool|mixed
	 */
	public static function keepUnpaidOrder( $cancel, $order ) {
		return $order instanceof \WC_Order && PaymentListener::isSlotivaOrder( $order ) ? false : $cancel;
	}

	/**
	 * `held` satırın işini kurar (aynı argümanlı bekleyen iş varsa yinelemez).
	 *
	 * @param Reservation $r Satır.
	 */
	private static function schedule( Reservation $r ): void {
		if ( 'held' !== $r->status || null === $r->holdExpiresAt || ! self::ready() ) {
			return;
		}
		$args = self::args( $r );
		if ( as_has_scheduled_action( self::EXPIRE_HOOK, $args, self::GROUP ) ) {
			return;
		}
		as_schedule_single_action( $r->holdExpiresAt->getTimestamp(), self::EXPIRE_HOOK, $args, self::GROUP );
	}

	/**
	 * Satırın bekleyen tüm süre dolumu işlerini kaldırır (sequence/bitiş ne olursa olsun; kimlik tam eşleşir).
	 *
	 * @param int $id Rezervasyon.
	 */
	private static function unschedule( int $id ): void {
		if ( ! self::ready() ) {
			return;
		}
		// Kısmi eşleme (`"reservation_id":1` 11'i de bulur) yalnız aday listesidir; kimlik ayrıca tam karşılaştırılır.
		$actions = as_get_scheduled_actions(
			array(
				'hook'                  => self::EXPIRE_HOOK,
				'group'                 => self::GROUP,
				'status'                => \ActionScheduler_Store::STATUS_PENDING,
				'args'                  => array( 'reservation_id' => $id ),
				'partial_args_matching' => 'like',
				'per_page'              => -1,
			)
		);
		foreach ( $actions as $actionId => $action ) {
			$args = $action->get_args();
			if ( (int) ( $args['reservation_id'] ?? 0 ) === $id ) {
				\ActionScheduler::store()->cancel_action( (string) $actionId );
			}
		}
	}

	/**
	 * İş argümanları (§15.7).
	 *
	 * @param Reservation $r Satır.
	 * @return array{reservation_id:int,sequence:int,hold_expires_at:string}
	 */
	private static function args( Reservation $r ): array {
		return array(
			'reservation_id'  => $r->id,
			'sequence'        => $r->sequence,
			'hold_expires_at' => (string) $r->holdExpiresAt?->format( self::FMT ),
		);
	}

	/**
	 * Havale son tarihi: yalnız havale/çek yöntemli, ödeme izi taşımayan (`date_paid` yok, işlem kimliği boş) `on-hold`
	 * sipariş etkin satırı kalmadıysa iptal edilir (not ile). Diğer her durumda dokunulmaz (§15.2).
	 *
	 * @param int $orderId Sipariş.
	 */
	private static function cancelBankOrder( int $orderId ): void {
		$order = Fresh::order( $orderId );
		if ( ! $order || ! $order->has_status( 'on-hold' ) || ! in_array( (string) $order->get_payment_method(), CheckoutFlow::transferMethods(), true )
			|| $order->get_date_paid() || '' !== (string) $order->get_transaction_id() || ReservationRepository::forOrder( $orderId, true ) ) {
			return;
		}
		$order->update_status( 'cancelled', __( 'MHM Slotiva: the bank transfer was not received before the booking deadline. The time slot has been released and the order cancelled.', 'mhm-slotiva' ) );
	}

	/**
	 * Siparişin ödeme yöntemi havale/çek mi (`slotiva_transfer_methods`).
	 *
	 * @param int $orderId Sipariş.
	 */
	private static function isTransferOrder( int $orderId ): bool {
		$order = Fresh::order( $orderId );
		return $order && in_array( (string) $order->get_payment_method(), CheckoutFlow::transferMethods(), true );
	}

	/**
	 * Action Scheduler hazır mı.
	 */
	private static function ready(): bool {
		return function_exists( 'as_schedule_single_action' ) && class_exists( \ActionScheduler::class ) && \ActionScheduler::is_initialized();
	}

	/**
	 * İş hatasını WC günlüğüne yazar.
	 *
	 * @param string     $what Ne.
	 * @param int        $id   Kimlik.
	 * @param \Throwable $e    Hata.
	 */
	private static function log( string $what, int $id, \Throwable $e ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( sprintf( 'slotiva %s #%d: %s', $what, $id, $e->getMessage() ), array( 'source' => 'mhm-slotiva' ) );
		}
	}
}
