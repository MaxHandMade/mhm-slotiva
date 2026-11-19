<?php
/**
 * Bildirim tetikleri (spec §7, §15.3, §15.11): depo olayları → WC e-postaları.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify;

use MHM\Slotiva\Checkout\CheckoutFlow;
use MHM\Slotiva\Notify\Email\BookingEmail;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Support\Fresh;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar sözleşmeyle camelCase.

/**
 * Olaylar yalnız COMMIT sonrası gelir (Task 6 deposu). Kararlar olaydaki değere değil satırın GÜNCEL okumasına göre verilir.
 *
 * - Onay + yönetici "yeni randevu": siparişin tüm etkin satırları `confirmed` olunca sipariş başına bir kez, toplu (bayrak sipariş
 *   metası `_slotiva_mailed_confirmed` = gönderilmiş rezervasyon kimlikleri, sipariş posta kilidi altında). Siparişsiz (elle)
 *   randevuda rezervasyon başına bir kez (`mailed_confirmed` olayı); müşteriye yalnız `notify`.
 * - Müşteri iptal: `→ cancelled` ya da havale son tarihi (`→ expired`, `bank_expired`; havale satırının tembel süre dolumu da).
 *   Siparişin aynı anda aynı duruma geçmiş satırları tek e-postada (`mailed_cancelled` olayı satır başına). Kart tutma dolumu ve
 *   `superseded` e-posta üretmez.
 * - Saat değişimi + `notify`: müşteriye yeni `.ics` (artmış SEQUENCE).
 * - `slotiva_conflict`: yöneticiye çakışma.
 */
final class Emails {

	public const META_MAILED = '_slotiva_mailed_confirmed';

	/** WC e-posta anahtarları → sınıflar. */
	public const CLASSES = array(
		'Slotiva_Customer_Confirmed'   => Email\CustomerConfirmed::class,
		'Slotiva_Customer_Rescheduled' => Email\CustomerRescheduled::class,
		'Slotiva_Customer_Cancelled'   => Email\CustomerCancelled::class,
		'Slotiva_Customer_Reminder'    => Email\CustomerReminder::class,
		'Slotiva_Admin_New'            => Email\AdminNew::class,
		'Slotiva_Admin_Conflict'       => Email\AdminConflict::class,
	);

	/** Sipariş posta kilidi bekleme süresi (sn). */
	private const LOCK_TIMEOUT = 10;

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_classes', array( self::class, 'classes' ) );
		add_action( 'slotiva_reservation_status', array( self::class, 'onStatus' ), 20, 4 );
		add_action( 'slotiva_reservation_rescheduled', array( self::class, 'onRescheduled' ), 20, 3 );
		add_action( 'slotiva_conflict', array( self::class, 'onConflict' ), 10, 3 );
	}

	/**
	 * `woocommerce_email_classes`.
	 *
	 * @param array<string,mixed>|mixed $emails WC e-postaları.
	 * @return array<string,mixed>
	 */
	public static function classes( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		foreach ( self::CLASSES as $key => $class ) {
			$emails[ $key ] = new $class();
		}
		return $emails;
	}

	/**
	 * Durum geçişi (COMMIT sonrası).
	 *
	 * @param int|mixed                 $id  Rezervasyon.
	 * @param string|mixed              $old Eski durum.
	 * @param string|mixed              $to  Yeni durum.
	 * @param array<string,mixed>|mixed $ctx notify, reason, order_id.
	 */
	public static function onStatus( $id, $old, $to, $ctx = array() ): void {
		$ctx    = is_array( $ctx ) ? $ctx : array();
		$notify = (bool) ( $ctx['notify'] ?? true );
		$reason = (string) ( $ctx['reason'] ?? '' );
		$r      = ReservationRepository::find( (int) $id );
		if ( null === $r || $r->status !== $to ) {
			return; // Arada yine değişti: o geçişin olayı kendi kararını verir.
		}
		try {
			if ( 'confirmed' === $to && 'admin_reschedule' === $reason ) {
				// Çakışmalı randevuya yönetici yeni saat verdi (final review F3): müşteriye yeni saati "saat değişti"
				// e-postası (onRescheduled, `notify`'a uyar) götürür; onay/yeni randevu e-postası ayrıca gitmez, yalnız
				// "gönderildi" sayılır (sonraki toplu onay bu satırı yeniden göndermesin).
				self::markConfirmedMailed( $r );
			} elseif ( 'confirmed' === $to ) {
				self::confirmed( $r, $notify );
			} elseif ( $notify && ( 'cancelled' === $to || ( 'expired' === $to && self::isBankDeadline( $r, $reason ) ) ) ) {
				self::cancelled( $r );
			}
		} catch ( \Throwable $e ) {
			self::log( 'status', $r->id, $e ); // E-posta hatası ödeme/durum akışını asla düşürmez.
		}
	}

	/**
	 * Saat değişti (COMMIT sonrası).
	 *
	 * @param int|mixed  $id       Rezervasyon.
	 * @param mixed      $oldStart Eski başlangıç.
	 * @param bool|mixed $notify   Müşteriye bildirilsin mi.
	 */
	public static function onRescheduled( $id, $oldStart = null, $notify = true ): void {
		unset( $oldStart );
		$r = ReservationRepository::find( (int) $id );
		// Yalnız onaylı satır: tutulan (ödenmemiş) satıra REQUEST `.ics` gitmez; tutma sessizce dolarsa takvimde hayalet
		// randevu kalırdı. Yeni saat onay e-postasında güncel SEQUENCE ile gelir.
		if ( ! $notify || null === $r || 'confirmed' !== $r->status || ! ReservationRepository::customerNotify( $r->id ) ) {
			return;
		}
		self::safeSend( 'Slotiva_Customer_Rescheduled', array( $r ), self::orderOf( $r ) );
	}

	/**
	 * Ödenmiş sipariş saatini alamadı (Task 10 `slotiva_conflict`).
	 *
	 * @param int|mixed       $orderId Sipariş.
	 * @param list<int>|mixed $ids     Çakışmaya geçen rezervasyonlar.
	 * @param string|mixed    $reason  late_paid_taken | superseded_paid.
	 */
	public static function onConflict( $orderId, $ids, $reason = '' ): void {
		$rows = array();
		foreach ( (array) $ids as $id ) {
			$r = ReservationRepository::find( (int) $id );
			if ( $r ) {
				$rows[] = $r;
			}
		}
		self::safeSend( 'Slotiva_Admin_Conflict', $rows, Fresh::order( (int) $orderId ), (string) $reason );
	}

	/**
	 * E-postayı WC postacısından alıp tetikler.
	 *
	 * @param string            $key    `CLASSES` anahtarı.
	 * @param list<Reservation> $rows   Rezervasyonlar.
	 * @param \WC_Order|null    $order  Sipariş.
	 * @param string            $reason Bağlam.
	 * @return bool Gönderildi mi.
	 */
	public static function send( string $key, array $rows, ?\WC_Order $order = null, string $reason = '' ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		if ( ! $rows || ! function_exists( 'WC' ) ) {
			return false;
		}
		$email = WC()->mailer()->get_emails()[ $key ] ?? null;
		return $email instanceof BookingEmail && $email->trigger( $rows, $order, $reason );
	}

	/**
	 * `send()`; hata günlüğe yazılır, sonraki gönderimi engellemez (müşteri ve yönetici e-postaları birbirinden bağımsız).
	 *
	 * @param string            $key    `CLASSES` anahtarı.
	 * @param list<Reservation> $rows   Rezervasyonlar.
	 * @param \WC_Order|null    $order  Sipariş.
	 * @param string            $reason Bağlam.
	 */
	private static function safeSend( string $key, array $rows, ?\WC_Order $order = null, string $reason = '' ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		try {
			self::send( $key, $rows, $order, $reason );
		} catch ( \Throwable $e ) {
			self::log( $key, $rows ? $rows[0]->id : 0, $e );
		}
	}

	/**
	 * Onay: elle eklenende satır başına, siparişte toplu bir kez.
	 *
	 * @param Reservation $r      Onaylanan satır.
	 * @param bool        $notify Müşteriye (elle eklenen için).
	 */
	private static function confirmed( Reservation $r, bool $notify ): void {
		if ( null === $r->orderId ) {
			// Döngü başına: süresi dolup yeniden alınan (reclaim → sequence++) elle randevu yeniden onay alır.
			$locked = ReservationRepository::recordOnce( $r->id, 'mailed_confirmed', array(), array( 'expect_status' => array( 'confirmed' ) ), null, false, true );
			if ( null === $locked ) {
				return;
			}
			if ( $notify ) {
				self::safeSend( 'Slotiva_Customer_Confirmed', array( $locked ) );
			}
			self::safeSend( 'Slotiva_Admin_New', array( $locked ) );
			return;
		}
		$orderId = $r->orderId;
		$rows    = self::underOrderLock(
			$orderId,
			static function () use ( $orderId ): array {
				$active = ReservationRepository::forOrder( $orderId, true );
				if ( ! $active ) {
					return array();
				}
				foreach ( $active as $row ) {
					if ( 'confirmed' !== $row->status ) {
						return array(); // Siparişin bir dilimi hâlâ tutuluyor: son onayda toplu gider.
					}
				}
				$order = Fresh::order( $orderId );
				if ( ! $order ) {
					return array();
				}
				$raw    = $order->get_meta( self::META_MAILED );
				$mailed = is_array( $raw ) ? array_map( 'intval', $raw ) : array();
				$new    = array_values( array_filter( $active, static fn( Reservation $row ): bool => ! in_array( $row->id, $mailed, true ) ) );
				if ( $new ) {
					$order->update_meta_data( self::META_MAILED, array_values( array_unique( array_merge( $mailed, array_map( static fn( Reservation $row ): int => $row->id, $new ) ) ) ) );
					$order->save_meta_data();
				}
				return $new;
			}
		);
		if ( ! $rows ) {
			return;
		}
		$order = Fresh::order( $orderId );
		self::safeSend( 'Slotiva_Customer_Confirmed', $rows, $order );
		self::safeSend( 'Slotiva_Admin_New', $rows, $order );
	}

	/**
	 * Onay e-postası göndermeden "gönderildi" işareti (elle eklenende `mailed_confirmed` olayı, siparişte META_MAILED).
	 *
	 * @param Reservation $r Onaylanan satır.
	 */
	private static function markConfirmedMailed( Reservation $r ): void {
		if ( null === $r->orderId ) {
			ReservationRepository::recordOnce( $r->id, 'mailed_confirmed', array(), array( 'expect_status' => array( 'confirmed' ) ), null, false, true );
			return;
		}
		$orderId = $r->orderId;
		$id      = $r->id;
		self::underOrderLock(
			$orderId,
			static function () use ( $orderId, $id ): array {
				$order = Fresh::order( $orderId );
				if ( ! $order ) {
					return array();
				}
				$raw    = $order->get_meta( self::META_MAILED );
				$mailed = is_array( $raw ) ? array_map( 'intval', $raw ) : array();
				if ( ! in_array( $id, $mailed, true ) ) {
					$mailed[] = $id;
					$order->update_meta_data( self::META_MAILED, $mailed );
					$order->save_meta_data();
				}
				return array();
			}
		);
	}

	/**
	 * Müşteri iptal: siparişin aynı duruma geçmiş, henüz bildirilmemiş satırları tek e-postada.
	 *
	 * @param Reservation $r Geçen satır.
	 */
	private static function cancelled( Reservation $r ): void {
		if ( ! ReservationRepository::customerNotify( $r->id ) ) {
			return; // Elle eklenirken "e-posta gönderme" seçildi: müşteri bu randevudan hiç haberdar edilmedi.
		}
		$candidates = array( $r );
		if ( null !== $r->orderId ) {
			foreach ( ReservationRepository::forOrder( $r->orderId ) as $row ) {
				if ( $row->id !== $r->id && $row->status === $r->status && ( 'cancelled' === $row->status || 'awaiting_transfer' === $row->paymentState ) ) {
					$candidates[] = $row;
				}
			}
		}
		$rows = array();
		foreach ( $candidates as $row ) {
			// İptal `.ics`'i yeni bir sürümdür (§15.11, I1): `cancelled` geçişi sequence'i iptal işleminde zaten artırdı
			// (bildirimden bağımsız, Codex final 4); havale son tarihi (`expired`) ise işaretle aynı işlemde artırır. İşaret
			// döngü başına: CANCEL → reclaim (sequence++) → REQUEST → yeniden iptal kendi CANCEL'ını alır; aynı döngüde
			// yineleme bastırılır.
			$locked = ReservationRepository::recordOnce( $row->id, 'mailed_cancelled', array(), array( 'expect_status' => array( $r->status ) ), null, 'cancelled' !== $r->status, true );
			if ( $locked ) {
				$rows[] = $locked;
			}
		}
		self::safeSend( 'Slotiva_Customer_Cancelled', $rows, self::orderOf( $r ), 'expired' === $r->status ? 'bank_expired' : 'cancelled' );
	}

	/**
	 * Havale son tarihi mi: `bank_expired` ya da havale/çek siparişinin `awaiting_transfer` satırı tembel süre dolumuyla düştü
	 * (aynı iş olayı; iş henüz koşmadan başka bir alma birimi istedi). Kart tutması (`hold_expired`, kart `on-hold`) sessizdir.
	 *
	 * @param Reservation $r      Satır.
	 * @param string      $reason Olay nedeni.
	 */
	private static function isBankDeadline( Reservation $r, string $reason ): bool {
		if ( 'bank_expired' === $reason ) {
			return true;
		}
		if ( 'lazy_expired' !== $reason || 'awaiting_transfer' !== $r->paymentState || null === $r->orderId ) {
			return false;
		}
		$order = Fresh::order( $r->orderId );
		return $order && in_array( (string) $order->get_payment_method(), CheckoutFlow::transferMethods(), true );
	}

	/**
	 * Satırın siparişi (taze).
	 *
	 * @param Reservation $r Satır.
	 */
	private static function orderOf( Reservation $r ): ?\WC_Order {
		return null === $r->orderId ? null : Fresh::order( $r->orderId );
	}

	/**
	 * Sipariş posta kilidi (`GET_LOCK`) altında çalıştırır: eşzamanlı iki onay olayı aynı bayrağı birlikte okuyamaz.
	 * Kilit alınamazsa hiçbir şey yapılmaz (kilidi tutan istek aynı kararı güncel veriyle verir) ve günlüğe yazılır.
	 *
	 * @param int                          $orderId Sipariş.
	 * @param callable():list<Reservation> $work İş.
	 * @return list<Reservation>
	 */
	private static function underOrderLock( int $orderId, callable $work ): array {
		global $wpdb;
		$name = $wpdb->prefix . 'slotiva_mail_' . $orderId;
		$name = strlen( $name ) <= 64 ? $name : 'slotiva_' . md5( $name );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL adlı kilidi (GET_LOCK): veri sorgusu değil, önbelleklenemez; WordPress'te karşılığı yok.
		if ( '1' !== (string) $got ) {
			self::log( 'lock', $orderId, new \RuntimeException( 'slotiva_mail_lock_busy' ) );
			return array();
		}
		try {
			return $work();
		} finally {
			$wpdb->query( $wpdb->prepare( 'DO RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL adlı kilidinin bırakılması (RELEASE_LOCK): veri sorgusu değil, önbelleklenemez.
		}
	}

	/**
	 * WC günlüğü.
	 *
	 * @param string     $what Ne.
	 * @param int        $id   Kimlik.
	 * @param \Throwable $e    Hata.
	 */
	private static function log( string $what, int $id, \Throwable $e ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( sprintf( 'slotiva notify %s #%d: %s', $what, $id, $e->getMessage() ), array( 'source' => 'mhm-slotiva' ) );
		}
	}
}
