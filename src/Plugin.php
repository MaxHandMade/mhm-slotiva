<?php
/**
 * Eklenti önyükleyicisi.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva;

defined( 'ABSPATH' ) || exit;

/**
 * Alt sistemleri tek noktadan kaydeder.
 */
final class Plugin {

	/**
	 * Her alt sistem `register(): void` statik yöntemiyle buradan eklenir.
	 */
	public static function boot(): void {
		I18n\LanguagePackPreference::register();
		Db\Schema::register();
		Product\ProductTab::register();
		Availability\RestController::register();
		Checkout\StoreApiExtension::register();
		Checkout\CheckoutFlow::register();
		Checkout\BlockRegistration::register();
		Lifecycle\PaymentListener::register();
		Lifecycle\HoldScheduler::register();
		Lifecycle\OrderPayGuard::register();
		Lifecycle\GatewayNotice::register();
		Setup\SetupRest::register();
		Setup\ActivationRedirect::register();
		Notify\Reminders::register();
		Notify\Emails::register();
		Front\OrderDisplay::register();
		Admin\RestController::register();
		Admin\Menu::register();
		Privacy\Exporter::register();
		Privacy\Eraser::register();
		Privacy\Retention::register();
		// Sonraki alt sistemler buraya eklenir.
	}
}
