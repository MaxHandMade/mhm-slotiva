<?php
/**
 * WP "Kişisel veriyi sil" kaydı ve WC sipariş anonimleştirme kancası.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Privacy;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Reservation\ReservationRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- yöntem adları sözleşmeyle camelCase; eklentinin kendi rezervasyon tablosu (WP kişisel veri silici), sayfalı tek seferlik okuma, önbellek anlamsız.

/**
 * Boşaltma, e-posta eşleşmesini bozar; bu yüzden `$page` YOK SAYILIR ve her çağrı kalan eşleşmelerin İLK 100'ünü alır
 * (OFFSET kullanılsaydı sonraki sayfa satır atlardı). `done` = bu çağrı sayfayı doldurmadı (kalan yok).
 */
final class Eraser {

	public const PAGE = 100;

	/**
	 * Kancalar.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add' ) );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( self::class, 'onOrder' ) );
	}

	/**
	 * Silicinin kaydı.
	 *
	 * @param array<string,mixed>|mixed $erasers Kayıtlar.
	 * @return array<string,mixed>
	 */
	public static function add( $erasers ): array {
		$erasers                = (array) $erasers;
		$erasers['mhm-slotiva'] = array(
			'eraser_friendly_name' => __( 'MHM Slotiva appointments', 'mhm-slotiva' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * WP sözleşmesi: `items_removed`, `items_retained`, `messages`, `done`.
	 *
	 * @param string $email E-posta.
	 * @param int    $page  Sayfa (yok sayılır: kalanın ilk sayfası).
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		unset( $page );
		$res = Schema::table( 'reservations' );
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE customer_email = %s ORDER BY id ASC LIMIT %d', $res, $email, self::PAGE ) ) );

		if ( ! $ids ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		$user = get_user_by( 'email', $email );
		if ( ! Anonymiser::reservations( $ids, $user instanceof \WP_User ? $user->ID : null ) ) {
			// Başarısız yazımda döngüye girmemek için biter; WP iletiyi gösterir.
			return array(
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => array( __( 'MHM Slotiva: some appointment records could not be anonymised. Please try again.', 'mhm-slotiva' ) ),
				'done'           => true,
			);
		}
		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PAGE,
		);
	}

	/**
	 * `woocommerce_privacy_remove_order_personal_data( $order )`: siparişin rezervasyonlarındaki anlık kopyalar boşalır.
	 *
	 * @param mixed $order Sipariş.
	 */
	public static function onOrder( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$ids = array_map( static fn( $r ): int => $r->id, ReservationRepository::forOrder( $order->get_id() ) );
		if ( $ids ) {
			Anonymiser::reservations( $ids, $order->get_customer_id() > 0 ? $order->get_customer_id() : null );
		}
	}
}
