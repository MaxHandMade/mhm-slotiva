<?php
/**
 * Saklama süresi: geçmiş randevuların kişisel alanlarını günlük işle anonimleştirir (spec §Kişisel veri).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Privacy;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- yöntem adları sözleşmeyle camelCase; eklentinin kendi rezervasyon tablosu (saklama süresi işi, cron), tek seferlik toplu okuma, önbellek anlamsız.

/**
 * `ends_at < şimdi - retentionMonths` ve kişisel alanı hâlâ dolu satırlar. Anonimleşen satır eşleşmeden düştüğü için her
 * tur kalanın İLK sayfasını alır (satır atlanmaz). Bir koşuda en çok `BATCHES` tur; kalan varsa kısa gecikmeli tek iş.
 */
final class Retention {

	public const HOOK  = 'slotiva_retention';
	public const GROUP = 'slotiva';

	/** Tur sınırına varılınca kalanı sürdüren tek seferlik iş. */
	public const HOOK_MORE = 'slotiva_retention_more';

	/** `init` başına sorgu yememek için kurulum denetimi en çok bu sıklıkla yapılır (saniye). */
	private const ENSURE_EVERY = 3600;

	/** Seçenek: son kurulum denetimi (otomatik yüklenir: ek sorgu yok). */
	private const ENSURED_OPTION = 'slotiva_retention_checked';

	private const BATCH_SIZE = 100;
	private const BATCHES    = 20;
	private const FMT        = 'Y-m-d H:i:s';

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'ensureRecurring' ) );
		add_action( self::HOOK, array( self::class, 'handle' ) );
		add_action( self::HOOK_MORE, array( self::class, 'handle' ) );
	}

	/**
	 * Günlük işi bir kez kurar; denetim saatte en çok bir kez sorgu yapar.
	 */
	public static function ensureRecurring(): void {
		if ( ! self::ready() || time() - (int) get_option( self::ENSURED_OPTION, 0 ) < self::ENSURE_EVERY ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
		update_option( self::ENSURED_OPTION, time() );
	}

	/**
	 * Action Scheduler girişi (dönüş değeri yok).
	 */
	public static function handle(): void {
		self::run();
	}

	/**
	 * İş: eşik geçmiş ve kişisel alanı dolu satırları anonimleştirir.
	 *
	 * @return int Anonimleştirilen satır sayısı.
	 */
	public static function run(): int {
		global $wpdb;
		$res       = Schema::table( 'reservations' );
		$months    = max( 1, min( 120, Rules::load()->retentionMonths ) );
		$threshold = Clock::now()->modify( '-' . $months . ' months' )->format( self::FMT );
		$total     = 0;

		for ( $i = 0; $i < self::BATCHES; $i++ ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE ends_at < %s AND (customer_name <> '' OR customer_email <> '' OR customer_phone <> '' OR session_hash IS NOT NULL OR ip_hash IS NOT NULL) ORDER BY id ASC LIMIT %d", $res, $threshold, self::BATCH_SIZE ) ) );
			if ( ! $ids ) {
				return $total;
			}
			if ( ! Anonymiser::reservations( $ids ) ) {
				return $total; // Yazım hatasında döngüye girmeden bırak; sonraki günlük koşu yeniden dener.
			}
			$total += count( $ids );
			if ( count( $ids ) < self::BATCH_SIZE ) {
				return $total;
			}
		}
		// Tur sınırına varıldı, kalan olabilir: kısa gecikmeyle devam. Yalnız BEKLEYEN devam işine bakılır:
		// `as_has_scheduled_action` koşan (RUNNING) işi de sayar; devam işi kendi içinden zinciri sürdüremezdi.
		if ( self::ready() && ! self::pendingMore() ) {
			as_schedule_single_action( time() + 300, self::HOOK_MORE, array(), self::GROUP );
		}
		return $total;
	}

	/**
	 * Bekleyen (koşmayan) bir devam işi var mı.
	 */
	private static function pendingMore(): bool {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK_MORE,
				'args'     => array(),
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		return ! empty( $ids );
	}

	/**
	 * Action Scheduler hazır mı.
	 */
	private static function ready(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && class_exists( \ActionScheduler::class ) && \ActionScheduler::is_initialized();
	}
}
