<?php
/**
 * Etkinleştirme yönlendirmesi (1.0.2): eklenti etkinleştirilince, kurulum tamam değilse yetkili kullanıcı bir kez
 * kurulum sihirbazına götürülür. Güncelleme etkinleştirme değildir (kanca çalışmaz); toplu etkinleştirme, AJAX/REST/CLI
 * ve ağ yönetimi yönlendirilmez; form gönderimi (GET dışı), admin-post.php, iframe ve WP-Cron istekleri de yönlendirmez
 * (bayrak sonraki uygun sayfaya kalır).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Setup;

use MHM\Slotiva\Admin\Menu;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- yöntem adları sözleşmeyle camelCase.

/**
 * Bir kerelik yönlendirme.
 */
final class ActivationRedirect {

	/** Seçenek: etkinleştirmede 1 yazılır, yönlendirmede silinir. */
	public const OPTION = 'slotiva_setup_redirect';

	/**
	 * Yalnız test: sabit ezmesi (REST_REQUEST, WP_CLI …). Sabitler sürecin ömrünce tanımlı kalır; testleri kirletmesin diye.
	 *
	 * @var array<string,bool>|null
	 */
	private static ?array $test_constants = null;

	/** Kancalar: yönlendirme `admin_init`'te. */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybeRedirect' ) );
	}

	/**
	 * Etkinleştirme kancası: yönlendirmeyi etkinleştiren kullanıcı için kurar. WP-CLI, ağ genelinde ve oturumsuz
	 * etkinleştirmede kurulmaz (bayrak sahipsiz kalıp günler sonra başka bir yöneticiyi yönlendirmesin).
	 *
	 * @param bool $network_wide Ağ genelinde etkinleştirme mi.
	 */
	public static function arm( bool $network_wide = false ): void {
		$user = get_current_user_id();
		if ( $network_wide || self::constant( 'WP_CLI' ) || $user <= 0 ) {
			// Kurulamayan etkinleştirme eski bayrağı da temizler; yoksa önceki etkinleştirmenin bayrağı sonra yönlendirir.
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $user );
	}

	/**
	 * `admin_init`: kurulduysa ve koşullar uygunsa sihirbaza yönlendirir (bir kez).
	 */
	public static function maybeRedirect(): void {
		if ( ! get_option( self::OPTION ) ) {
			return;
		}
		// Bu bağlamlarda sayfa yüklemesi insan gezintisi değildir; kurulum bayrağı bir sonraki yönetim sayfasına kalır.
		if ( wp_doing_ajax() || wp_doing_cron() || self::constant( 'REST_REQUEST' ) || self::constant( 'WP_CLI' ) || self::constant( 'IFRAME_REQUEST' ) || is_network_admin() ) {
			return;
		}
		// Form gönderimi (POST) ve admin-post.php yönlendirilirse gönderim düşer; bayrak sonraki GET sayfasına kalır.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method || 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- çekirdeğin plugins.php?activate-multi göstergesi, salt okunur: yalnız yönlendirmeyi atlar (kendi tek seferlik bayrağımızı siler), başka hiçbir şeyi yetkilendirmez.
		if ( isset( $_GET['activate-multi'] ) ) {
			delete_option( self::OPTION );
			return;
		}
		// Bayrak etkinleştiren kullanıcınındır; başka kullanıcı yönlenmez, bayrak sahibini bekler.
		if ( (int) get_option( self::OPTION ) !== get_current_user_id() || ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}
		delete_option( self::OPTION );
		if ( SetupStatus::isComplete() ) {
			return;
		}
		// `wp_redirect` süzgeci konumu false yaparsa (test) başlık gönderilmez ve çıkılmaz.
		if ( wp_safe_redirect( Menu::setupUrl() ) ) {
			exit;
		}
	}

	/**
	 * Sabit okuyucu; test ezmesi yalnız `SLOTIVA_TESTING` iken etkilidir.
	 *
	 * @param string $name Sabit adı.
	 */
	private static function constant( string $name ): bool {
		if ( null !== self::$test_constants && self::testing() && array_key_exists( $name, self::$test_constants ) ) {
			return self::$test_constants[ $name ];
		}
		return defined( $name ) && (bool) constant( $name );
	}

	/**
	 * Test: sabit ezmesi (null = kaldır). Üretimde işlem yapmaz.
	 *
	 * @param array<string,bool>|null $constants Sabit adı → değer.
	 */
	public static function overrideConstantsForTesting( ?array $constants ): void {
		if ( self::testing() ) {
			self::$test_constants = $constants;
		}
	}

	/** Test kapıları açık mı (`SLOTIVA_TESTING` sabiti tanımlı ve true ise). */
	private static function testing(): bool {
		return defined( 'SLOTIVA_TESTING' ) && true === constant( 'SLOTIVA_TESTING' );
	}
}
