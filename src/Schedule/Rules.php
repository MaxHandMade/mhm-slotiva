<?php
/**
 * Genel kurallar (`slotiva_rules` seçeneği — tek kaynak; `uninstall.php` de bunu okur).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Schedule;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- alan adları sözleşmeyle camelCase (spec §5).

/**
 * Değişmez kural nesnesi; `Rules::load()` ile okunur, `Rules::save()` ile (doğrulanarak) yazılır.
 */
final class Rules {

	public const OPTION = 'slotiva_rules';

	/** Süre/ara adımı (dakika). */
	public const STEP = 15;

	/** Randevu süresinin üst sınırı (dakika); kurulum sihirbazı (Service.js) ve ürün sekmesi aynı değeri kullanır. */
	public const DURATION_MAX = 480;

	/**
	 * Yalnız `load()`/`save()` kurar.
	 *
	 * @param int    $durationDefault       Varsayılan dilim süresi (dk, 15'in katı).
	 * @param int    $leadHours             En erken rezervasyon önceliği (saat).
	 * @param int    $horizonDays           Ufuk (gün, 1–62).
	 * @param int    $bufferMin             Randevular arası tampon (dk, 15'in katı veya 0).
	 * @param int    $bankHoldHours         Havale için tutma süresi (saat).
	 * @param int    $cardHoldMin           Kart için tutma süresi (dk, en az 15).
	 * @param bool   $reminderOn            Hatırlatma açık mı.
	 * @param int    $reminderHours         Hatırlatma kaç saat önce.
	 * @param string $policyText            Genel iptal/erteleme politikası metni.
	 * @param int    $retentionMonths       Kişisel veri saklama süresi (ay).
	 * @param bool   $deleteDataOnUninstall Silinirken veri de silinsin mi.
	 */
	private function __construct(
		public readonly int $durationDefault,
		public readonly int $leadHours,
		public readonly int $horizonDays,
		public readonly int $bufferMin,
		public readonly int $bankHoldHours,
		public readonly int $cardHoldMin,
		public readonly bool $reminderOn,
		public readonly int $reminderHours,
		public readonly string $policyText,
		public readonly int $retentionMonths,
		public readonly bool $deleteDataOnUninstall
	) {}

	/**
	 * Varsayılanlar (anahtar = alan adı).
	 *
	 * @return array<string,int|bool|string>
	 */
	private static function defaults(): array {
		return array(
			'durationDefault'       => 30,
			'leadHours'             => 24,
			'horizonDays'           => 14,
			'bufferMin'             => 0,
			'bankHoldHours'         => 24,
			'cardHoldMin'           => 45,
			'reminderOn'            => true,
			'reminderHours'         => 24,
			'policyText'            => '',
			'retentionMonths'       => 24,
			'deleteDataOnUninstall' => false,
		);
	}

	/**
	 * Seçenekten okur; eksik/bozuk alanlar varsayılana düşer.
	 */
	public static function load(): self {
		$stored = get_option( self::OPTION, array() );
		$data   = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		return self::from_array( $data );
	}

	/**
	 * Alan dizisinden nesne kurar.
	 *
	 * @param array<string,mixed> $d Tam alan dizisi.
	 */
	private static function from_array( array $d ): self {
		return new self(
			(int) $d['durationDefault'],
			(int) $d['leadHours'],
			(int) $d['horizonDays'],
			(int) $d['bufferMin'],
			(int) $d['bankHoldHours'],
			(int) $d['cardHoldMin'],
			(bool) $d['reminderOn'],
			(int) $d['reminderHours'],
			(string) $d['policyText'],
			(int) $d['retentionMonths'],
			(bool) $d['deleteDataOnUninstall']
		);
	}

	/**
	 * Verilen alanları mevcut kurallara işleyip doğrular ve kaydeder.
	 *
	 * @param array<string,mixed> $in Değiştirilecek alanlar (camelCase); verilmeyenler korunur.
	 * @return self|\WP_Error
	 */
	public static function save( array $in ) {
		$cur             = self::defaults();
		$have            = get_option( self::OPTION, array() );
		$cur             = array_merge( $cur, is_array( $have ) ? $have : array() );
		$stored_duration = (int) $cur['durationDefault'];

		foreach ( self::defaults() as $key => $default ) {
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}
			$cur[ $key ] = is_bool( $default )
				? filter_var( $in[ $key ], FILTER_VALIDATE_BOOLEAN )
				: ( is_int( $default ) ? self::to_int( $in[ $key ] ) : sanitize_textarea_field( (string) $in[ $key ] ) );
		}

		// Üst sınır yalnız süre bu istekte DEĞİŞİYORSA denetlenir: Çalışma saatleri ekranı tüm anahtarları (süre dahil) gönderir,
		// depoda kalmış eski büyük değer aynı değerle geri gelince başka kayıtları engellemez.
		if ( array_key_exists( 'durationDefault', $in ) && $cur['durationDefault'] !== $stored_duration && $cur['durationDefault'] > self::DURATION_MAX ) {
			return new \WP_Error(
				'slotiva_invalid_value',
				sprintf(
					/* translators: %d: maximum appointment length in minutes. */
					__( 'The appointment length must be at most %d minutes.', 'mhm-slotiva' ),
					self::DURATION_MAX
				)
			);
		}

		$error = self::validate( $cur );
		if ( $error ) {
			return $error;
		}

		update_option( self::OPTION, $cur, false );
		do_action( 'slotiva_schedule_changed' );
		return self::from_array( $cur );
	}

	/**
	 * Sayısal olmayan girdiyi geçersiz (PHP_INT_MIN) yapar; böylece sınır denetimi reddeder.
	 *
	 * @param mixed $v Ham değer.
	 */
	private static function to_int( $v ): int {
		return is_numeric( $v ) ? (int) $v : PHP_INT_MIN;
	}

	/**
	 * Sınırları denetler.
	 *
	 * @param array<string,mixed> $d Tam alan dizisi.
	 * @return \WP_Error|null
	 */
	private static function validate( array $d ): ?\WP_Error {
		foreach ( array( 'durationDefault', 'bufferMin' ) as $key ) {
			if ( $d[ $key ] < 0 || 0 !== $d[ $key ] % self::STEP ) {
				return new \WP_Error(
					'slotiva_invalid_step',
					sprintf(
					/* translators: 1: field name (appointment length or buffer), 2: step in minutes. */
						__( '%1$s must be a multiple of %2$d minutes.', 'mhm-slotiva' ),
						'durationDefault' === $key ? __( 'The appointment length', 'mhm-slotiva' ) : __( 'The buffer', 'mhm-slotiva' ),
						self::STEP
					)
				);
			}
		}
		if ( $d['durationDefault'] < self::STEP ) {
			return new \WP_Error( 'slotiva_invalid_step', __( 'The appointment length must be at least 15 minutes.', 'mhm-slotiva' ) );
		}
		if ( $d['horizonDays'] < 1 || $d['horizonDays'] > 62 ) {
			return new \WP_Error( 'slotiva_invalid_value', __( 'The latest booking day must be between 1 and 62 days ahead.', 'mhm-slotiva' ) );
		}
		if ( $d['cardHoldMin'] < 15 ) {
			return new \WP_Error( 'slotiva_invalid_value', __( 'The card payment hold must be at least 15 minutes.', 'mhm-slotiva' ) );
		}
		if ( $d['leadHours'] < 0 || $d['bankHoldHours'] < 1 || $d['reminderHours'] < 1 || $d['retentionMonths'] < 1 || $d['retentionMonths'] > 120 ) {
			return new \WP_Error( 'slotiva_invalid_value', __( 'Hour and month values are out of range (data retention: 1 to 120 months).', 'mhm-slotiva' ) );
		}
		return null;
	}
}
