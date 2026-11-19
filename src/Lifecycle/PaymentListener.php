<?php
/**
 * Ödeme algılama (spec §6.4, §15.1, §15.2, §15.3).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Lifecycle;

use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\EventLog;
use MHM\Slotiva\Reservation\LockBusyException;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Support\Fresh;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar sözleşmeyle camelCase.

/**
 * Ana dinleyici `woocommerce_order_status_changed` (WC 11.2.1 `class-wc-order.php:478`): kurulu PayTR başarıda
 * `payment_complete()` ÇAĞIRMAZ, yalnız `update_status($options['paytr_order_status'])` yapar. `woocommerce_payment_complete`
 * (`class-wc-order.php:186`) ikincil yol; ikisi aynı idempotent işleyiciye (`onPaid`) gider.
 *
 * Karar her seferinde siparişin TAZE okunmuş durumundan ve satırların kilit altında yeniden okunmuş durumundan verilir
 * (depo `expect_status`): aynı bildirimin iki kez gelmesi tek onay üretir.
 */
final class PaymentListener {

	/** Kilit meşgul/veritabanı hatasında siparişi yeniden değerlendiren iş (`['order_id' => …, 'attempt' => n]`). */
	public const SYNC_HOOK = 'slotiva_order_sync';

	/** Yeniden deneme gecikmesi (sn). */
	private const RETRY_DELAY = 60;

	/** En fazla yeniden deneme; sonra yönetici notu düşülür ve zincir durur. */
	public const MAX_ATTEMPTS = 10;

	/**
	 * Koşan `slotiva_order_sync` işinin deneme numarası (0 = kanca/ilk karar). `retryLater` bir sonrakini kurar.
	 *
	 * @var int
	 */
	private static int $attempt = 0;

	/** `on-hold`a geçişte havale süresinin bağlandığı kaynak durumlar. */
	private const TRANSFER_FROM = array( 'pending', 'failed', 'checkout-draft' );

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( self::class, 'onStatusChanged' ), 10, 3 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'onPaid' ) );
		add_action( 'woocommerce_order_fully_refunded', array( self::class, 'onFullyRefunded' ) );
		add_action( 'woocommerce_before_trash_order', array( self::class, 'onOrderRemoved' ) );
		add_action( 'woocommerce_before_delete_order', array( self::class, 'onOrderRemoved' ) );
		add_action( 'wp_trash_post', array( self::class, 'onPostRemoved' ) );
		add_action( 'before_delete_post', array( self::class, 'onPostRemoved' ) );
		add_action( self::SYNC_HOOK, array( self::class, 'sync' ), 10, 2 );
	}

	/**
	 * Durum değişimi: ödenmiş durum → onay; `cancelled` → bırak; `pending|failed → on-hold` → havale süresi.
	 * `failed` tutmaya dokunmaz (tutma süresi dolana kadar dilim bu siparişindir, spec senaryo 5).
	 *
	 * @param int|mixed    $orderId Sipariş.
	 * @param string|mixed $from    Eski durum.
	 * @param string|mixed $to      Yeni durum.
	 */
	public static function onStatusChanged( $orderId, $from, $to ): void {
		$orderId = (int) $orderId;
		if ( in_array( $to, wc_get_is_paid_statuses(), true ) ) {
			self::onPaid( $orderId );
		} elseif ( 'cancelled' === $to ) {
			self::onCancelled( $orderId );
		} elseif ( 'on-hold' === $to && in_array( $from, self::TRANSFER_FROM, true ) ) {
			self::onTransfer( $orderId );
		}
	}

	/**
	 * Ödeme geldi (idempotent). Yöntem `cod` ise hiçbir şey yapılmaz (kapıda ödeme "ödendi" değildir).
	 * 1. `held` satırlar → `confirmed/paid`.
	 * 2. `expired` satır varsa (etkin satır olsa da; çok dilimli siparişte kısmi tembel süre dolumu): sipariş geneli
	 *    yeniden alma → `confirmed/paid`; dolu → `conflict` + `slotiva_conflict` (geç ödeme, spec senaryo 8). Başka
	 *    randevunun üstüne asla yazılmaz.
	 * 3. Etkin ve `expired` satır yok, yalnız `superseded` varsa (aynı oturumun yeni siparişi aldı): yeniden alınmaz →
	 *    `conflict` (§15.3). Etkin satır varken `superseded` satırlar yeniden gönderimin eskileridir, yok sayılır.
	 *
	 * @param int|mixed $orderId Sipariş.
	 */
	public static function onPaid( $orderId ): void {
		$orderId = (int) $orderId;
		if ( ! self::isPaidOrder( $orderId ) ) {
			return;
		}
		try {
			// Kilit altında tutmayan bir yeniden okuma kararı bayatlarsa (satır arada değişti) bir kez daha karar verilir.
			for ( $attempt = 0; $attempt < 2; $attempt++ ) {
				if ( self::settlePaid( $orderId ) ) {
					return;
				}
			}
		} catch ( LockBusyException | \RuntimeException $e ) {
			self::retryLater( $orderId, $e );
		} catch ( \LogicException $e ) {
			self::log( $orderId, $e );
		}
	}

	/**
	 * Sipariş iptal edildi: etkin satırlar `cancelled`, birimler silinir. Son durumdaki (`expired` dahil) satırlar ezilmez.
	 *
	 * @param int $orderId Sipariş.
	 */
	public static function onCancelled( int $orderId ): void {
		if ( ! ReservationRepository::forOrder( $orderId, true ) ) {
			return;
		}
		try {
			ReservationRepository::releaseForOrder( $orderId, 'cancelled' );
		} catch ( LockBusyException | \RuntimeException $e ) {
			self::retryLater( $orderId, $e );
		}
	}

	/**
	 * Tam iade (`woocommerce_order_fully_refunded`, `wc-order-functions.php:726`): etkin ya da çakışmalı satırlar TEK
	 * işlemde `cancelled/refunded`. Kısmi iade randevuya dokunmaz (kanca bağlanmaz; yönetici karar verir).
	 *
	 * @param int|mixed $orderId Sipariş.
	 */
	public static function onFullyRefunded( $orderId ): void {
		$orderId = (int) $orderId;
		try {
			// Tek işlem (final review F6): olaylar hepsi yazıldıktan sonra gelir, müşteri iptal e-postası sipariş başına bir.
			ReservationRepository::cancelForOrder( $orderId, 'refunded', array( 'held', 'confirmed', 'conflict' ), 'refunded' );
		} catch ( LockBusyException | \RuntimeException $e ) {
			self::retryLater( $orderId, $e );
		}
	}

	/**
	 * Sipariş çöpe atıldı ya da kalıcı silindi (final review F9): etkin satırlar depo yolundan `cancelled` (reason
	 * `order_deleted`, müşteriye e-posta yok); saat boşalır. İdempotent (etkin satır yoksa hiçbir şey).
	 *
	 * Kancalar: `woocommerce_before_trash_order` / `woocommerce_before_delete_order` (HPOS ve eski depolama, `$order->delete()`),
	 * eski depolamanın yazı listesi `wp_trash_post` / `before_delete_post` (WC sipariş kancası tetiklenmez). Çöpten geri alma
	 * satırı canlandırmaz (bilinçli): saat bu arada başkasına verilmiş olabilir; yönetici gerekirse yeni randevu ekler.
	 *
	 * @param int|mixed $orderId Sipariş (ya da yazı) kimliği.
	 */
	public static function onOrderRemoved( $orderId ): void {
		$orderId = (int) $orderId;
		if ( $orderId <= 0 || ! ReservationRepository::forOrder( $orderId, true ) ) {
			return;
		}
		try {
			ReservationRepository::cancelForOrder( $orderId, 'order_deleted', ReservationRepository::ACTIVE, null, false );
		} catch ( \Throwable $e ) {
			// Pre-ZIP gate G4: hiçbir istisna (LogicException, \Error dahil) sipariş silmeyi durdurmaz.
			self::log( $orderId, $e ); // Sipariş gidiyor: yeniden deneme işi siparişi bulamaz; satır yönetici listesinde kalır.
		}
	}

	/**
	 * Eski depolama yazı listesi (`wp_trash_post`, `before_delete_post`): yalnız sipariş yazı türlerinde.
	 *
	 * @param int|mixed $postId Yazı.
	 */
	public static function onPostRemoved( $postId ): void {
		$postId = (int) $postId;
		if ( $postId > 0 && in_array( get_post_type( $postId ), wc_get_order_types(), true ) ) {
			self::onOrderRemoved( $postId );
		}
	}

	/**
	 * Havale süresi ödeme yöntemine değil `pending → on-hold` geçişine bağlanır (bacs, çek, özel EFT): tutma
	 * `şimdi + bankHoldHours`, `payment_state=awaiting_transfer`; işler yeniden kurulur.
	 *
	 * @param int $orderId Sipariş.
	 */
	public static function onTransfer( int $orderId ): void {
		$held = array_filter( ReservationRepository::forOrder( $orderId, true ), static fn( Reservation $r ): bool => 'held' === $r->status );
		if ( ! $held ) {
			return;
		}
		try {
			ReservationRepository::refreshHold( $orderId, Clock::now()->modify( '+' . Rules::load()->bankHoldHours . ' hours' ) );
			foreach ( $held as $r ) {
				if ( 'awaiting_transfer' !== $r->paymentState ) {
					ReservationRepository::setStatus(
						$r->id,
						'held',
						'awaiting_transfer',
						array(
							'expect_status' => array( 'held' ),
							'reason'        => 'transfer',
						)
					);
				}
			}
		} catch ( LockBusyException | \RuntimeException $e ) {
			self::log( $orderId, $e ); // Tutma kart süresiyle sürer; yönetici süreyi uzatabilir.
		}
		HoldScheduler::rescheduleOrder( $orderId );
	}

	/**
	 * Yeniden deneme işi: siparişin GÜNCEL durumuna göre aynı işleyiciler.
	 *
	 * @param int|mixed $orderId Sipariş.
	 * @param int|mixed $attempt Bu işin deneme numarası (1..MAX_ATTEMPTS).
	 */
	public static function sync( $orderId, $attempt = 1 ): void {
		$orderId = (int) $orderId;
		$order   = Fresh::order( $orderId );
		if ( ! $order ) {
			return;
		}
		$previous      = self::$attempt;
		self::$attempt = max( 1, (int) $attempt );
		try {
			if ( self::isPaidOrder( $orderId ) ) {
				self::onPaid( $orderId );
			} elseif ( $order->has_status( 'cancelled' ) ) {
				self::onCancelled( $orderId );
			} elseif ( $order->has_status( 'refunded' ) ) {
				self::onFullyRefunded( $orderId );
			}
		} finally {
			self::$attempt = $previous;
		}
	}

	/**
	 * Sipariş (taze okunmuş) ödenmiş durumda ve yöntemi `cod` değil mi.
	 *
	 * @param int $orderId Sipariş.
	 */
	public static function isPaidOrder( int $orderId ): bool {
		$order = Fresh::order( $orderId );
		return $order && 'cod' !== $order->get_payment_method() && in_array( $order->get_status(), wc_get_is_paid_statuses(), true );
	}

	/**
	 * Slotiva siparişi mi: rezervasyonu, saklı talebi ya da randevulu kalemi var (409 sonrası rezervasyonsuz ara sipariş dahil).
	 *
	 * @param \WC_Order $order Sipariş.
	 */
	public static function isSlotivaOrder( \WC_Order $order ): bool {
		if ( $order->get_meta( CheckoutFlow::META_REQUESTED ) || ReservationRepository::forOrder( $order->get_id() ) ) {
			return true;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product instanceof \WC_Product && BookableProduct::from( $product ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tek karar turu. true = karar uygulandı ya da yapılacak bir şey yok; false = satır arada değişti, yeniden oku.
	 *
	 * @param int $orderId Sipariş.
	 * @throws LockBusyException Sipariş kilidi alınamadı.
	 * @throws \LogicException Satır yeniden almaya uygun değil (arada iptal edildi).
	 */
	private static function settlePaid( int $orderId ): bool {
		$rows = ReservationRepository::forOrder( $orderId );
		if ( ! $rows ) {
			return true;
		}
		$by = static fn( string ...$statuses ): array => array_values( array_filter( $rows, static fn( Reservation $r ): bool => in_array( $r->status, $statuses, true ) ) );

		$stable = true;
		foreach ( $by( 'held' ) as $r ) {
			$stable = ReservationRepository::setStatus(
				$r->id,
				'confirmed',
				'paid',
				array(
					'expect_status' => array( 'held' ),
					'reason'        => 'paid',
				)
			) && $stable;
		}

		// Siparişin HER `expired` satırı ödenmiş dilimdir (etkin satır olsa da): çok dilimli siparişte tembel süre dolumu
		// satır başınadır, bir kısmı düşmüş olabilir. Yeniden gönderilen eski dilimler `expired` değil `superseded` olur
		// (`replaceForOrder`), onlar burada yok sayılır.
		$expired = $by( 'expired' );
		if ( $expired ) {
			try {
				$done = ReservationRepository::reclaim(
					$expired[0]->id,
					array(
						'expect_status' => array( 'expired' ),
						'payment_state' => 'paid',
						'reason'        => 'late_paid',
					)
				);
			} catch ( SlotTakenException $e ) {
				self::markConflict( $orderId, $expired, 'expired', 'late_paid_taken' );
				return $stable;
			}
			if ( $done ) {
				self::note( $orderId, __( 'MHM Slotiva: payment arrived after the booking hold expired. The time slot was still free and has been booked again.', 'mhm-slotiva' ) );
			}
			return $done && $stable;
		}
		if ( $by( 'held', 'confirmed' ) ) {
			return $stable; // Yeniden gönderimin `superseded` eski satırları yok sayılır.
		}

		// Yalnız `superseded` satır (aynı oturumun yeni siparişi aldı): yeniden alınmaz → çakışma (§15.3).
		$superseded = $by( 'superseded' );
		$sup_ids    = $superseded ? self::markConflict( $orderId, $superseded, 'superseded', 'superseded_paid', false ) : array();

		// Yönetici ÖDEMEDEN ÖNCE iptal ettiği randevunun siparişi sonradan ödendi (Task 13 I1): saat yeniden ALINMAZ,
		// sessizce de yutulmaz → not + çakışma e-postası. Satır başına bir kez. Ödenmiş randevunun yönetici iptali
		// sonraki "ödendi" olaylarında (completed, payment_complete yinelemesi, sync) çakışma SAYILMAZ (Task 13 N1).
		$admin_cancelled = array_values( array_filter( $by( 'cancelled' ), static fn( Reservation $r ): bool => 'paid' !== $r->paymentState && self::cancelledByAdminWhileUnpaid( $r->id ) ) );
		$adm_ids         = $admin_cancelled ? self::flagAdminCancelled( $orderId, $admin_cancelled ) : array();

		// Aynı siparişte iki tür birden: tek birleşik çakışma (tek e-posta).
		if ( $sup_ids && $adm_ids ) {
			$ids = array_merge( $sup_ids, $adm_ids );
			sort( $ids );
			self::fireConflict( $orderId, $ids, 'superseded_admin_cancelled_paid' );
		} elseif ( $sup_ids ) {
			self::fireConflict( $orderId, $sup_ids, 'superseded_paid' );
		} elseif ( $adm_ids ) {
			self::fireConflict( $orderId, $adm_ids, 'admin_cancelled_paid' );
		}
		return true;
	}

	/**
	 * Son `cancelled` geçişi yöneticinin (`reason=admin`, Admin\RestController) ödenmemiş tutmayı (`from=held`) iptali mi?
	 *
	 * @param int $id Rezervasyon.
	 */
	private static function cancelledByAdminWhileUnpaid( int $id ): bool {
		$last = null;
		foreach ( EventLog::for( $id ) as $e ) {
			if ( 'status' === $e['type'] && 'cancelled' === ( $e['data']['to'] ?? null ) ) {
				$last = $e['data'];
			}
		}
		return null !== $last && 'admin' === ( $last['reason'] ?? null ) && 'held' === ( $last['from'] ?? null );
	}

	/**
	 * Yönetici iptali + ödeme: satırlar `cancelled` kalır (birim yok); her satır için bir kez işaret (`recordOnce`),
	 * en az biri yeni işaretlendiyse not. Kancayı çağıran (`settlePaid`) tetikler.
	 *
	 * @param int               $orderId Sipariş.
	 * @param list<Reservation> $rows    Yönetici iptali satırlar.
	 * @return list<int> Yeni işaretlenenler.
	 */
	private static function flagAdminCancelled( int $orderId, array $rows ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$ids = array();
		foreach ( $rows as $r ) {
			$marked = ReservationRepository::recordOnce( $r->id, 'admin_cancelled_paid', array( 'order_id' => $orderId ), array( 'expect_status' => array( 'cancelled' ) ), array() );
			if ( null !== $marked ) {
				$ids[] = $r->id;
			}
		}
		if ( $ids ) {
			self::note( $orderId, __( 'MHM Slotiva: payment arrived for a booking that was cancelled by an administrator before the payment arrived. The time slot was not booked again. Refund the order or set a new time manually.', 'mhm-slotiva' ) );
		}
		return $ids;
	}

	/**
	 * Satırları `conflict/paid` yapar; en az biri gerçekten geçtiyse (yinelenen bildirimde değil) not + `slotiva_conflict`.
	 *
	 * @param int               $orderId Sipariş.
	 * @param list<Reservation> $rows    Satırlar.
	 * @param string            $expect  Beklenen durum (kilit altında yeniden doğrulanır).
	 * @param string            $reason  late_paid_taken | superseded_paid.
	 * @param bool              $fire    `slotiva_conflict` burada tetiklensin mi (false = çağıran birleştirir).
	 * @return list<int> Çakışmaya geçenler.
	 */
	private static function markConflict( int $orderId, array $rows, string $expect, string $reason, bool $fire = true ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$ids = array();
		foreach ( $rows as $r ) {
			$changed = ReservationRepository::setStatus(
				$r->id,
				'conflict',
				'paid',
				array(
					'expect_status' => array( $expect ),
					'reason'        => $reason,
				)
			);
			if ( $changed ) {
				$ids[] = $r->id;
			}
		}
		if ( ! $ids ) {
			return array();
		}
		self::note(
			$orderId,
			'superseded_paid' === $reason
				? __( 'MHM Slotiva: payment arrived for a booking that had been replaced by a newer order. The time slot was not booked again. Refund or reschedule manually.', 'mhm-slotiva' )
				: __( 'MHM Slotiva: payment arrived after the booking hold expired and the time slot is taken by another booking. Refund or reschedule manually.', 'mhm-slotiva' )
		);
		if ( $fire ) {
			self::fireConflict( $orderId, $ids, $reason );
		}
		return $ids;
	}

	/**
	 * Yöneticiye çakışma kancası.
	 *
	 * @param int       $orderId Sipariş.
	 * @param list<int> $ids     Rezervasyonlar.
	 * @param string    $reason  Neden.
	 */
	private static function fireConflict( int $orderId, array $ids, string $reason ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		/**
		 * Ödenmiş sipariş saatini alamadı (yönetici çakışma e-postası, Task 11).
		 *
		 * @param int       $orderId Sipariş.
		 * @param list<int> $ids     Çakışmaya geçen rezervasyonlar.
		 * @param string    $reason  late_paid_taken | superseded_paid | admin_cancelled_paid | superseded_admin_cancelled_paid.
		 */
		do_action( 'slotiva_conflict', $orderId, $ids, $reason );
	}

	/**
	 * Sipariş notu (yöneticiye).
	 *
	 * @param int    $orderId Sipariş.
	 * @param string $text    Metin.
	 */
	private static function note( int $orderId, string $text ): void {
		$order = Fresh::order( $orderId );
		if ( $order ) {
			$order->add_order_note( $text );
		}
	}

	/**
	 * Karar verilemedi (kilit meşgul / veritabanı hatası): sipariş bir dakika sonra yeniden değerlendirilir. Tutma bu
	 * arada dolarsa süre dolumu işi ödenmiş siparişi düşürmez, onayı dener (`HoldScheduler::expire`).
	 *
	 * Yineleme denetimi yalnız BEKLEYEN işlere bakar: `as_has_scheduled_action` koşan (RUNNING) işi de sayar, bu yüzden
	 * yeniden deneme işinin içinden kurulamazdı (zincir ilk tekrarda kopardı). Deneme sayısı `MAX_ATTEMPTS` ile sınırlı;
	 * sınırda yönetici notu düşülür.
	 *
	 * @param int        $orderId Sipariş.
	 * @param \Throwable $e       Neden.
	 */
	private static function retryLater( int $orderId, \Throwable $e ): void {
		self::log( $orderId, $e );
		if ( ! function_exists( 'as_schedule_single_action' ) || self::pendingSync( $orderId ) ) {
			return;
		}
		$next = self::$attempt + 1;
		if ( $next > self::MAX_ATTEMPTS ) {
			self::note( $orderId, __( 'MHM Slotiva: the booking could not be updated after repeated attempts because the order was busy. Check the booking of this order manually.', 'mhm-slotiva' ) );
			return;
		}
		as_schedule_single_action(
			time() + self::RETRY_DELAY,
			self::SYNC_HOOK,
			array(
				'order_id' => $orderId,
				'attempt'  => $next,
			),
			HoldScheduler::GROUP
		);
	}

	/**
	 * Sipariş için BEKLEYEN (koşan değil) bir yeniden deneme işi var mı.
	 *
	 * @param int $orderId Sipariş.
	 */
	private static function pendingSync( int $orderId ): bool {
		$actions = as_get_scheduled_actions(
			array(
				'hook'                  => self::SYNC_HOOK,
				'group'                 => HoldScheduler::GROUP,
				'status'                => \ActionScheduler_Store::STATUS_PENDING,
				'args'                  => array( 'order_id' => $orderId ),
				'partial_args_matching' => 'like',
				'per_page'              => -1,
			)
		);
		foreach ( $actions as $action ) {
			if ( (int) ( $action->get_args()['order_id'] ?? 0 ) === $orderId ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WC günlüğü.
	 *
	 * @param int        $orderId Sipariş.
	 * @param \Throwable $e       Hata.
	 */
	private static function log( int $orderId, \Throwable $e ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( sprintf( 'slotiva order #%d: %s', $orderId, $e->getMessage() ), array( 'source' => 'mhm-slotiva' ) );
		}
	}
}
