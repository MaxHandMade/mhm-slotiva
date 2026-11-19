<?php
/**
 * Veritabanı şeması: dört Slotiva tablosu (hepsi InnoDB, zamanlar UTC DATETIME).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Db;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi tablolarının kurulumu ve yapı denetimi (SHOW TABLE STATUS/INDEX/COLUMNS); WordPress API'si yok, önbellek denetimi anlamsızlaştırır.

/**
 * Tabloları `dbDelta` ile kurar; sürüm farkında yeniden çalıştırılır ve kurulum sonrası doğrular.
 */
final class Schema {

	/** Şema sürümü (`slotiva_db_version` seçeneği). Sütun/indeks değişince artırılır; '3' tabloyu değiştirmez (yalnız 1.1.0 yükseltme temizliğini tetikler); '4' reservations.capacity (koltuk sayısı anlık görüntüsü, vars. 1) ekler. */
	public const VERSION = '4';

	/** Çakışma anahtarının ADI: `slotiva_occupancy` üzerinde (spec §15.5; kurulum sonrası doğrulanır). */
	public const UNIQUE_KEY = 'slotiva_unit';

	/** Aynı anahtarın v1'deki eski adı (v2'ye geçişte kaldırılır). */
	private const LEGACY_UNIQUE_KEY = 'unit_seat';

	/** Geçerli tablo kısa adları. */
	public const SHORT_NAMES = array( 'reservations', 'occupancy', 'exceptions', 'events' );

	/**
	 * Alt sistem kaydı. Plugin::boot() zaten `plugins_loaded` içinde çalışır; sürüm farkı burada hemen denetlenir.
	 */
	public static function register(): void {
		self::maybe_install();
	}

	/**
	 * Kayıtlı sürüm farklıysa kurar.
	 */
	public static function maybe_install(): void {
		if ( get_option( 'slotiva_db_version' ) !== self::VERSION ) {
			self::install();
			// Action Scheduler `init` önceliği 1'de hazır olur; artık geri çağrısı olmayan işler orada, bir kez süpürülür.
			add_action( 'init', array( \MHM\Slotiva\Lifecycle\OrphanJobs::class, 'sweep' ), 20 );
		}
	}

	/**
	 * Tam tablo adı: `{prefix}slotiva_<kısa ad>`.
	 *
	 * @param string $short reservations|occupancy|exceptions|events.
	 * @throws \InvalidArgumentException Bilinmeyen kısa ad.
	 */
	public static function table( string $short ): string {
		global $wpdb;
		if ( ! in_array( $short, self::SHORT_NAMES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown Slotiva table: ' . esc_html( $short ) );
		}
		return $wpdb->prefix . 'slotiva_' . $short;
	}

	/**
	 * Tabloları kurar/yükseltir; tekrar çağrılması zararsızdır (idempotent). Kurulum sonrası `verify()` geçmezse sürüm
	 * YAZILMAZ (sonraki yüklemede yeniden denenir) ve sorun + `$wpdb->last_error` günlüğe yazılır.
	 *
	 * @return bool Şema doğrulandı ve sürüm yazıldı mı.
	 */
	public static function install(): bool {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$res     = self::table( 'reservations' );
		$occ     = self::table( 'occupancy' );
		$exc     = self::table( 'exceptions' );
		$evt     = self::table( 'events' );

		// dbDelta biçim kuralları: her sütun ayrı satır, "PRIMARY KEY  (id)" iki boşlukla, KEY adı zorunlu.
		$sql = array(
			"CREATE TABLE {$res} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  order_id BIGINT UNSIGNED NULL DEFAULT NULL,
  order_item_id BIGINT UNSIGNED NULL DEFAULT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'held',
  payment_state VARCHAR(20) NOT NULL DEFAULT 'pending',
  hold_expires_at DATETIME NULL DEFAULT NULL,
  customer_name VARCHAR(190) NOT NULL DEFAULT '',
  customer_email VARCHAR(190) NOT NULL DEFAULT '',
  customer_phone VARCHAR(60) NOT NULL DEFAULT '',
  location_text VARCHAR(255) NOT NULL DEFAULT '',
  reschedule_count INT NOT NULL DEFAULT 0,
  token CHAR(32) NOT NULL,
  sequence INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  product_name VARCHAR(255) NOT NULL DEFAULT '',
  session_hash CHAR(32) NULL DEFAULT NULL,
  ip_hash CHAR(32) NULL DEFAULT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY order_id (order_id),
  KEY starts_at (starts_at),
  KEY status (status),
  KEY session_hash (session_hash),
  KEY ip_hash (ip_hash)
) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$occ} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  resource_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  unit_start DATETIME NOT NULL,
  seat SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  reservation_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slotiva_unit (resource_id,unit_start,seat),
  KEY reservation_id (reservation_id)
) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$exc} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  date DATE NOT NULL,
  from_time TIME NULL DEFAULT NULL,
  to_time TIME NULL DEFAULT NULL,
  type VARCHAR(10) NOT NULL DEFAULT 'closed',
  note VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY date (date)
) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$evt} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  actor VARCHAR(40) NOT NULL DEFAULT 'system',
  data LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY reservation_id (reservation_id)
) ENGINE=InnoDB {$charset};",
		);

		$errors = array();
		dbDelta( $sql );
		if ( '' !== $wpdb->last_error ) {
			$errors[] = (string) $wpdb->last_error;
		}
		self::ensure_innodb( $errors );
		self::drop_legacy_unique_key( $errors );

		$problem = self::verify();
		if ( null !== $problem ) {
			if ( '' !== $wpdb->last_error ) {
				$errors[] = (string) $wpdb->last_error;
			}
			error_log( '[mhm-slotiva] schema v' . self::VERSION . ' verification failed: ' . $problem . ' | db errors: ' . implode( ' | ', array_unique( $errors ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- kurulum hatası sunucu günlüğüne; sürüm yazılmaz.
			return false;
		}
		update_option( 'slotiva_db_version', self::VERSION );
		return true;
	}

	/**
	 * Kurulu şemayı doğrular (spec §15.5): dört tablo InnoDB; occupancy'de ADLANDIRILMIŞ benzersiz
	 * `slotiva_unit (resource_id, unit_start, seat)`; anahtar sütunları NOT NULL; v2 sütun ve indeksleri var.
	 *
	 * @return string|null null = sağlam; aksi hâlde ilk sorunun tanımı.
	 */
	public static function verify(): ?string {
		global $wpdb;
		foreach ( self::SHORT_NAMES as $short ) {
			$table  = self::table( $short );
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! $status ) {
				return "{$table} missing";
			}
			if ( 'InnoDB' !== $status->Engine ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL SHOW TABLE STATUS sütunu.
				return "{$table} is not InnoDB";
			}
		}

		$occ  = self::table( 'occupancy' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $occ, self::UNIQUE_KEY ), ARRAY_A );
		$cols = array();
		foreach ( (array) $rows as $r ) {
			if ( '0' !== (string) $r['Non_unique'] ) {
				return self::UNIQUE_KEY . ' is not unique';
			}
			$cols[ (int) $r['Seq_in_index'] ] = (string) $r['Column_name'];
		}
		ksort( $cols );
		if ( array( 'resource_id', 'unit_start', 'seat' ) !== array_values( $cols ) ) {
			return 'UNIQUE KEY ' . self::UNIQUE_KEY . ' (resource_id, unit_start, seat) missing on ' . $occ;
		}

		$required = array(
			'occupancy'    => array(
				'not_null' => array( 'resource_id', 'unit_start', 'seat', 'reservation_id' ),
				'nullable' => array(),
				'keys'     => array(),
			),
			'reservations' => array(
				'not_null' => array( 'resource_id', 'starts_at', 'ends_at', 'status', 'token', 'sequence', 'product_name', 'capacity' ),
				'nullable' => array( 'session_hash', 'ip_hash' ),
				'keys'     => array( 'session_hash', 'ip_hash' ),
			),
		);
		foreach ( $required as $short => $spec ) {
			$table   = self::table( $short );
			$columns = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ) as $c ) {
				$columns[ (string) $c['Field'] ] = (string) $c['Null'];
			}
			foreach ( $spec['not_null'] as $name ) {
				if ( 'NO' !== ( $columns[ $name ] ?? '' ) ) {
					return "{$table}.{$name} missing or nullable";
				}
			}
			foreach ( $spec['nullable'] as $name ) {
				if ( ! isset( $columns[ $name ] ) ) {
					return "{$table}.{$name} missing";
				}
			}
			if ( $spec['keys'] ) {
				$keys = array_column( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A ), 'Key_name' );
				foreach ( $spec['keys'] as $key ) {
					if ( ! in_array( $key, $keys, true ) ) {
						return "{$table} index {$key} missing";
					}
				}
			}
		}
		return null;
	}

	/**
	 * Geçiş v1 → v2: dbDelta dizinleri yeniden ADLANDIRMAZ, eksik `slotiva_unit`'i ekler. Yeni ad VARSA (benzersizlik hiç
	 * boşa düşmeden) eski `unit_seat` kaldırılır.
	 *
	 * @param array<int,string> $errors Biriken veritabanı hataları.
	 */
	private static function drop_legacy_unique_key( array &$errors ): void {
		global $wpdb;
		$occ = self::table( 'occupancy' );
		$has = static fn( string $key ): bool => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $occ, $key ) );
		if ( $has( self::LEGACY_UNIQUE_KEY ) && $has( self::UNIQUE_KEY ) ) {
			if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $occ, self::LEGACY_UNIQUE_KEY ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- v1→v2 geçişi: kendi tablomuzdan eski benzersiz dizini kaldırır; WordPress'te DDL API'si yok.
				$errors[] = (string) $wpdb->last_error;
			}
		}
	}

	/**
	 * Motoru InnoDB olmayan tabloyu dönüştürür. Yalnız gerektiğinde: ALTER örtük COMMIT tetikler.
	 *
	 * @param array<int,string> $errors Biriken veritabanı hataları.
	 */
	private static function ensure_innodb( array &$errors ): void {
		global $wpdb;
		foreach ( self::SHORT_NAMES as $short ) {
			$table  = self::table( $short );
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $status && 'InnoDB' !== $status->Engine ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL SHOW TABLE STATUS sütunu.
				if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- kendi tablomuzu InnoDB'ye çevirir (satır kilidi ve işlem gerekir); WordPress'te DDL API'si yok.
					$errors[] = (string) $wpdb->last_error;
				}
			}
		}
	}
}
