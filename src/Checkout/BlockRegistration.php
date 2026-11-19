<?php
/**
 * Checkout block integration: loads the `slotiva/slot-picker` inner block script on the block Checkout.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce calls `initialize()` when the Checkout block renders; the script then registers the picker with
 * `registerCheckoutBlock()` as a locked (forced) inner block, so existing Checkout pages show it without edits.
 */
final class BlockRegistration implements IntegrationInterface {

	public const HANDLE = 'slotiva-slot-picker';

	/** Script and style paths relative to the plugin root (wp-scripts output). */
	private const SCRIPT = 'build/checkout/index.js';
	private const ASSET  = 'build/checkout/index.asset.php';
	private const STYLE  = 'build/checkout/style-index.css';

	/**
	 * Plugin root the build paths resolve against (filesystem).
	 *
	 * @var string
	 */
	private string $dir;

	/**
	 * Creates the integration.
	 *
	 * @param string|null $dir Plugin root (default SLOTIVA_DIR); tests pass a directory without a build.
	 */
	public function __construct( ?string $dir = null ) {
		$this->dir = untrailingslashit( $dir ?? SLOTIVA_DIR );
	}

	/**
	 * Hooks the integration into the Checkout block registry.
	 */
	public static function register(): void {
		add_action(
			'woocommerce_blocks_checkout_block_registration',
			static function ( $registry ): void {
				if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
					$registry->register( new self() );
				}
			}
		);
		// Only fired while the Checkout block renders (Blocks\BlockTypes\Checkout::enqueue_assets).
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( self::class, 'enqueue_style' ) );
	}

	/**
	 * Enqueues the registered stylesheet on Checkout block pages only.
	 */
	public static function enqueue_style(): void {
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}
	}

	/**
	 * Integration name; WooCommerce exposes `get_script_data()` to the script as `getSetting( '<name>_data' )`.
	 */
	public function get_name() {
		return self::HANDLE;
	}

	/**
	 * Registers the script (dependencies from the build's asset file), its translations and the stylesheet.
	 * WooCommerce calls this on every request (block type registration), so nothing is enqueued here.
	 *
	 * @return void
	 */
	public function initialize() {
		$asset_file = $this->dir . '/' . self::ASSET;
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		$deps  = is_array( $asset ) && isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver   = is_array( $asset ) && isset( $asset['version'] ) ? (string) $asset['version'] : SLOTIVA_VERSION;

		// The picker reads the validation store (wc-blocks-data-store) and the notices store (wp-notices) by name.
		$deps = array_values( array_unique( array_merge( $deps, array( 'wc-blocks-data-store', 'wp-notices' ) ) ) );

		wp_register_script( self::HANDLE, SLOTIVA_URL . self::SCRIPT, $deps, $ver, true );
		wp_set_script_translations( self::HANDLE, 'mhm-slotiva', $this->dir . '/languages' );

		if ( is_readable( $this->dir . '/' . self::STYLE ) ) {
			wp_register_style( self::HANDLE, SLOTIVA_URL . self::STYLE, array(), $ver );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Front-end script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return wp_script_is( self::HANDLE, 'registered' ) ? array( self::HANDLE ) : array();
	}

	/**
	 * The picker has no editor UI in v1; the page content is never changed.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Data for the script.
	 *
	 * @return array{contactUrl:string}
	 */
	public function get_script_data() {
		return array(
			'contactUrl' => self::contact_url(),
		);
	}

	/**
	 * Contact link for the "no free times" state: the store's public sender address by default.
	 */
	public static function contact_url(): string {
		$email   = sanitize_email( (string) get_option( 'woocommerce_email_from_address', '' ) );
		$default = '' !== $email ? 'mailto:' . $email : '';
		/**
		 * Filters the contact link shown when the booking horizon has no free times.
		 *
		 * @param string $url Default: mailto: link to the WooCommerce sender address, or empty.
		 */
		$url = (string) apply_filters( 'slotiva_contact_url', $default );
		return '' === $url ? '' : esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
	}
}
