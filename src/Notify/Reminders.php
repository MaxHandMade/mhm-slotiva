<?php
/**
 * Hatırlatma işleri (spec §7, §15.7).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Notify;

use MHM\Slotiva\Lifecycle\HoldScheduler;
use MHM\Slotiva\Reservation\Reservation;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Support\Fresh;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar sözleşmeyle camelCase.

/**
 * `confirmed` satır başına tek Action Scheduler işi `slotiva_reminder` (grup `slotiva`), `starts_at − reminderHours` anında.
 * Argümanlar §15.7 biçiminde `{reservation_id, sequence}` (Task 10 süre dolumu işiyle aynı kalıp). İşleyici satır kilidi
 * altında durum = `confirmed` ve `sequence` = beklenen mi diye yeniden doğrular ve "gönderildi" işaretini (`reminder_sent`
 * olayı, veri `{sequence}`) aynı işlemde yazar; uymazsa ya da işaret varsa hiçbir şey yapmaz. Randevuya N saatten az kala
 * onaylanan satıra iş kurulmaz.
 */
final class Reminders {

	public const HOOK = 'slotiva_reminder';

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ), 10, 2 );
		add_action( 'slotiva_reservation_status', array( self::class, 'onStatus' ), 10, 3 );
		add_action( 'slotiva_reservation_rescheduled', array( self::class, 'onRescheduled' ) );
	}

	/**
	 * `confirmed`e geçiş → kur; `confirmed`den çıkış → kaldır.
	 *
	 * @param int|mixed    $id  Rezervasyon.
	 * @param string|mixed $old Eski durum.
	 * @param string|mixed $to  Yeni durum.
	 */
	public static function onStatus( $id, $old, $to ): void {
		if ( 'confirmed' === $to ) {
			$r = ReservationRepository::find( (int) $id );
			if ( $r ) {
				self::schedule( $r );
			}
		} elseif ( 'confirmed' === $old ) {
			self::unschedule( (int) $id );
		}
	}

	/**
	 * Saat değişti (sequence arttı): eski iş kaldırılır, yeni sequence/zamanla yeniden kurulur.
	 *
	 * @param int|mixed $id Rezervasyon.
	 */
	public static function onRescheduled( $id ): void {
		self::unschedule( (int) $id );
		$r = ReservationRepository::find( (int) $id );
		if ( $r ) {
			self::schedule( $r );
		}
	}

	/**
	 * İşleyici.
	 *
	 * @param int|mixed $reservationId Rezervasyon.
	 * @param int|mixed $sequence      Beklenen sequence.
	 */
	public static function run( $reservationId, $sequence ): void {
		$id = (int) $reservationId;
		$r  = ReservationRepository::find( $id );
		if ( null === $r || 'confirmed' !== $r->status || $r->sequence !== (int) $sequence || ! Rules::load()->reminderOn || $r->startsAt <= Clock::now()
			|| ! ReservationRepository::customerNotify( $id ) ) {
			return;
		}
		try {
			$locked = ReservationRepository::recordOnce(
				$id,
				'reminder_sent',
				array( 'sequence' => (int) $sequence ),
				array(
					'expect_status'   => array( 'confirmed' ),
					'expect_sequence' => (int) $sequence,
				)
			);
			if ( null === $locked ) {
				return;
			}
			Emails::send( 'Slotiva_Customer_Reminder', array( $locked ), null === $locked->orderId ? null : Fresh::order( $locked->orderId ) );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( sprintf( 'slotiva reminder #%d: %s', $id, $e->getMessage() ), array( 'source' => 'mhm-slotiva' ) );
			}
		}
	}

	/**
	 * `confirmed` satırın işini kurar (aynı argümanlı bekleyen iş varsa yinelemez). Hatırlatma anı geçmişse kurulmaz.
	 *
	 * @param Reservation $r Satır.
	 */
	public static function schedule( Reservation $r ): void {
		$rules = Rules::load();
		if ( 'confirmed' !== $r->status || ! $rules->reminderOn || ! self::ready() || ! ReservationRepository::customerNotify( $r->id ) ) {
			return;
		}
		$at = $r->startsAt->getTimestamp() - $rules->reminderHours * HOUR_IN_SECONDS;
		if ( $at <= Clock::now()->getTimestamp() ) {
			return; // §15.7: randevuya N saatten az kala onay → hatırlatma yok.
		}
		$args = array(
			'reservation_id' => $r->id,
			'sequence'       => $r->sequence,
		);
		foreach ( self::pending( $r->id ) as $action ) {
			if ( $action->get_args() === $args ) {
				return;
			}
		}
		as_schedule_single_action( $at, self::HOOK, $args, HoldScheduler::GROUP );
	}

	/**
	 * Satırın bekleyen tüm hatırlatma işlerini kaldırır.
	 *
	 * @param int $id Rezervasyon.
	 */
	public static function unschedule( int $id ): void {
		if ( ! self::ready() ) {
			return;
		}
		foreach ( array_keys( self::pending( $id ) ) as $actionId ) {
			\ActionScheduler::store()->cancel_action( (string) $actionId );
		}
	}

	/**
	 * Satırın BEKLEYEN hatırlatma işleri (kimlik tam eşleşir; kısmi eşleme yalnız aday listesi).
	 *
	 * @param int $id Rezervasyon.
	 * @return array<int|string,\ActionScheduler_Action>
	 */
	private static function pending( int $id ): array {
		$out     = array();
		$actions = as_get_scheduled_actions(
			array(
				'hook'                  => self::HOOK,
				'group'                 => HoldScheduler::GROUP,
				'status'                => \ActionScheduler_Store::STATUS_PENDING,
				'args'                  => array( 'reservation_id' => $id ),
				'partial_args_matching' => 'like',
				'per_page'              => -1,
			)
		);
		foreach ( $actions as $actionId => $action ) {
			if ( (int) ( $action->get_args()['reservation_id'] ?? 0 ) === $id ) {
				$out[ $actionId ] = $action;
			}
		}
		return $out;
	}

	/**
	 * Action Scheduler hazır mı.
	 */
	private static function ready(): bool {
		return function_exists( 'as_schedule_single_action' ) && class_exists( \ActionScheduler::class ) && \ActionScheduler::is_initialized();
	}
}
