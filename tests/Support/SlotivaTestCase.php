<?php
/**
 * Slotiva tablolarına dokunan HER testin türediği ortak taban sınıf.
 *
 * Neden gerekli: depo (Reservations) kendi `START TRANSACTION`'ını açar; bu, WP test kütüphanesinin test başında
 * açtığı işlemi örtük olarak COMMIT eder (abstract-testcase.php). Yani kütüphanenin "test sonunda ROLLBACK" güvencesi
 * bu testlerde yoktur; temizliği burada açıkça yaparız.
 *
 * tear_down TEMİZLER (set_up anlık görüntüsüne göre "sonradan eklenen"leri): dört Slotiva tablosu, Action Scheduler
 * `slotiva` grubu, yazı/ürün/sipariş (ID büyüğü), kullanıcılar, terimler (+ taksonomi, termmeta, ilişkiler), yorumlar,
 * tüm sonradan eklenen seçenekler ve transient'ler (yalnız `slotiva_*` seçenekleri ayrıca DEĞERİNE geri döndürülür).
 *
 * tear_down TEMİZLEMEZ: set_up'ta var olan satırlara yapılan UPDATE'ler (var olan kullanıcının rolü/usermeta'sı, var olan
 * yazının alanları, `slotiva_*` dışı var olan seçeneğin değeri), dosya sistemi ve nesne önbelleğinin ötesi kalıcı durum.
 * Böyle bir değişikliği yapan test kendi eski haline döndürmelidir.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Support\Clock;
use WP_UnitTestCase;

abstract class SlotivaTestCase extends WP_UnitTestCase {

	use WooInstallState;

	/**
	 * Test başındaki `slotiva_*` seçenekleri (ham, serileştirilmiş değerler).
	 *
	 * @var array<string,string>
	 */
	private array $options_snapshot = array();

	/**
	 * Test başındaki en büyük kimlikler (posts, users); sonrası testin oluşturduğudur.
	 *
	 * @var array<string,int>
	 */
	private array $max_ids = array();

	/**
	 * Test başındaki tüm seçenek adları (slotiva_* dışı dahil); fazlası tear_down'da silinir.
	 *
	 * @var string[]
	 */
	private array $option_names_before = array();

	public function set_up(): void {
		// Önce temizlik: önceki testin bıraktığı satır olmasın. TRUNCATE örtük COMMIT yapar; parent'tan önce olması
		// kütüphanenin işlemini henüz açılmadan etkisiz kılar.
		$this->truncate_tables();
		parent::set_up();

		global $wpdb;
		$this->options_snapshot = array();
		$rows                   = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'slotiva\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sabit sorgu; yalnız çekirdek tablo adı.
		foreach ( (array) $rows as $row ) {
			$this->options_snapshot[ $row->option_name ] = $row->option_value;
		}
		$this->max_ids             = array(
			'posts'    => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sabit sorgu.
			'users'    => (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sabit sorgu.
			'terms'    => (int) $wpdb->get_var( "SELECT MAX(term_id) FROM {$wpdb->terms}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sabit sorgu.
			'comments' => (int) $wpdb->get_var( "SELECT MAX(comment_ID) FROM {$wpdb->comments}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sabit sorgu.
		);
		$this->option_names_before = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sabit sorgu.
		Clock::freeze( null );
	}

	public function tear_down(): void {
		Clock::freeze( null );
		$this->truncate_tables();
		$this->delete_action_scheduler_jobs();
		$this->delete_created_content();
		$this->delete_created_terms_and_comments();
		$this->restore_options();
		// Kütüphane `autocommit=0` çalıştırır: TRUNCATE'ten sonra açılan örtük işlem parent::tear_down()'ın ROLLBACK'iyle
		// yukarıdaki silmeleri geri alırdı. Temizliği burada kalıcılaştırırız.
		$GLOBALS['wpdb']->query( 'COMMIT' );
		parent::tear_down();
	}

	/** Dört Slotiva tablosunu boşaltır (tablo yoksa sessizce geçer). */
	private function truncate_tables(): void {
		global $wpdb;
		foreach ( Schema::SHORT_NAMES as $short ) {
			$table = Schema::table( $short );
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- şema denetimi.
				$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- TRUNCATE hazırlanamaz; ad Schema::table() beyaz listesinden.
			}
		}
	}

	/** `slotiva` grubundaki Action Scheduler işlerini siler (yüklüyse). */
	private function delete_action_scheduler_jobs(): void {
		if ( ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized() ) {
			return;
		}
		$store = \ActionScheduler::store();
		$ids   = $store->query_actions(
			array(
				'group'    => 'slotiva',
				'per_page' => -1,
			)
		);
		foreach ( (array) $ids as $id ) {
			$store->delete_action( (string) $id );
		}
	}

	/** Test sırasında oluşturulan sipariş, ürün, yazı ve kullanıcıları siler (kimlik > set_up anlık görüntüsü). */
	private function delete_created_content(): void {
		global $wpdb;
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d", $this->max_ids['posts'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- temizlik.
		foreach ( (array) $post_ids as $id ) {
			// HPOS siparişi yer tutucu yazıyla birlikte WC üzerinden silinir; yoksa düz yazı olarak.
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $id ) : false;
			if ( $order ) {
				$order->delete( true );
			}
			wp_delete_post( (int) $id, true );
		}
		$user_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d", $this->max_ids['users'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- temizlik.
		if ( $user_ids ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $user_ids as $id ) {
				wp_delete_user( (int) $id );
			}
		}
	}

	/** Test sırasında eklenen terim (+ taksonomi, termmeta, ilişki) ve yorumları siler. */
	private function delete_created_terms_and_comments(): void {
		global $wpdb;
		$comment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID > %d", $this->max_ids['comments'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- temizlik.
		foreach ( (array) $comment_ids as $id ) {
			wp_delete_comment( (int) $id, true );
		}
		$tt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id > %d", $this->max_ids['terms'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- temizlik.
		foreach ( (array) $tt_ids as $tt_id ) {
			$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => (int) $tt_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- temizlik.
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_id > %d", $this->max_ids['terms'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- temizlik.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE term_id > %d", $this->max_ids['terms'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- temizlik.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->terms} WHERE term_id > %d", $this->max_ids['terms'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- temizlik.
		wp_cache_flush();
	}

	/** Sonradan eklenen tüm seçenek/transient'leri siler; `slotiva_*` seçeneklerini test öncesi değerine döndürür. */
	private function restore_options(): void {
		global $wpdb;
		$now = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- sabit sorgu.
		foreach ( array_diff( (array) $now, $this->option_names_before ) as $name ) {
			delete_option( $name );
		}
		foreach ( $this->options_snapshot as $name => $raw ) {
			update_option( $name, maybe_unserialize( $raw ) );
		}
		wp_cache_delete( 'alloptions', 'options' );
	}
}
