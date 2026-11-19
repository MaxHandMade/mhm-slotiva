<?php
/**
 * Yükseltme temizliği: `slotiva` grubunda geri çağrısı kalmamış bekleyen işleri kaldırır.
 *
 * Eski bir sürümün yerine ZIP ile yüklenen sitede (devre dışı bırakmadan) o sürümün yinelenen işleri grupta kalır;
 * geri çağrısı olmayan iş beş kez başarısız olup Araçlar → Zamanlanmış eylemler'de iz bırakırdı. Burada Slotiva'nın
 * KENDİ zamanladığı kanca listesi dışındaki her bekleyen iş kaldırılır; başka gruplara dokunulmaz.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Lifecycle;

use MHM\Slotiva\Notify\Reminders;
use MHM\Slotiva\Privacy\Retention;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- yöntem adları sözleşmeyle camelCase.

/**
 * Şema sürümü değişince (Schema::maybe_install) bir kez `init` içinde çalışır.
 */
final class OrphanJobs {

	/**
	 * Slotiva'nın `as_schedule_*` ile zamanladığı kancalar (kaynaktaki sabitlerden türetilir).
	 *
	 * @return string[]
	 */
	public static function ownHooks(): array {
		return array(
			HoldScheduler::EXPIRE_HOOK,
			HoldScheduler::SWEEP_HOOK,
			Retention::HOOK,
			Retention::HOOK_MORE,
			Reminders::HOOK,
			PaymentListener::SYNC_HOOK,
		);
	}

	/**
	 * Kaldırılacak işleri bulur ve kaldırır.
	 */
	public static function sweep(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \ActionScheduler::class ) || ! \ActionScheduler::is_initialized() ) {
			return;
		}
		$own     = self::ownHooks();
		$actions = as_get_scheduled_actions(
			array(
				'group'    => HoldScheduler::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);
		$orphans = array();
		foreach ( $actions as $action ) {
			$hook = $action->get_hook();
			if ( ! in_array( $hook, $own, true ) ) {
				$orphans[ $hook ] = true;
			}
		}
		foreach ( array_keys( $orphans ) as $hook ) {
			// null = herhangi bir argüman (yetim işler argümanlı olabilir); array() yalnız argümansızları eşlerdi (AS functions.php:267-279).
			as_unschedule_all_actions( $hook, null, HoldScheduler::GROUP );
		}
	}
}
