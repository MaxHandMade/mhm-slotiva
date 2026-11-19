<?php
/**
 * Kişisel veriyi boşaltma çekirdeği (KVKK/GDPR): eraser, WC sipariş anonimleştirme ve saklama işi aynı yolu kullanır.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Privacy;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Notify\Reminders;
use MHM\Slotiva\Reservation\ReservationRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- imzalar sözleşmeyle camelCase; eklentinin kendi rezervasyon/olay tabloları (kişisel veri anonimleştirme), WordPress API'si yok, her yazma tek seferlik.

/**
 * Rezervasyon satırı KALIR (tarih, ürün, durum: istatistik ve takvim); yalnız kişiyi belirleyen alanlar boşalır:
 * ad/e-posta/telefon, oturum ve IP özeti, ve `slotiva_events` içindeki kişisel alanlar.
 */
final class Anonymiser {

	/** Olay verisinde kişisel sayılan anahtarlar (küçük harf; `customer`, `customer_*`, `billing_*` ayrıca). */
	private const PII_KEYS = array( 'name', 'email', 'phone', 'ip', 'user_agent', 'admin_name', 'actor_name', 'display_name', 'note', 'message' );

	/** Anonimleştirme işaret olayının türü (`ReservationRepository::isAnonymised`; işareti depo yazar). */
	public const MARKER = ReservationRepository::ANONYMISED;

	/** Değerleri iğneyle (ad/e-posta/telefon) EŞLEŞTİRİLMEYEN işlevsel anahtarlar ("Can" adı "cancelled" değerini silmesin). */
	private const FUNCTIONAL_KEYS = array( 'from', 'to', 'reason', 'notify', 'order_id', 'sequence', 'until', 'hours', 'counted', 'type', 'status' );

	/** Tek sorguda işlenen en çok kimlik. */
	private const CHUNK = 200;

	/**
	 * Rezervasyonların kişisel alanlarını ve olaylarındaki kişisel verileri boşaltır. Tekrar çağrılması zararsızdır.
	 *
	 * @param list<int> $ids         Rezervasyon kimlikleri.
	 * @param int|null  $subjectUserId Veri sahibinin WP kullanıcı kimliği: olaylarda YALNIZ bu `actor` değeri `system` yapılır (yönetici denetim izi kalır).
	 * @return bool Hepsi yazıldı mı (false = bir sorgu başarısız; çağıran satırı "tutuldu" sayar).
	 */
	public static function reservations( array $ids, ?int $subjectUserId = null ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$ok = true;
		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), self::CHUNK ) as $chunk ) {
			$ok = self::chunk( $chunk, $subjectUserId ) && $ok;
		}
		return $ok;
	}

	/**
	 * Bir parça.
	 *
	 * @param list<int> $ids         Kimlikler.
	 * @param int|null  $subjectUserId Veri sahibinin kullanıcı kimliği.
	 */
	private static function chunk( array $ids, ?int $subjectUserId ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$evt = Schema::table( 'events' );

		// Eşleştirme iğneleri boşaltmadan ÖNCE okunur (olay verisinde yazı olarak geçen ad/e-posta/telefon).
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT customer_name, customer_email, customer_phone FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $res ), $ids ) ), ARRAY_A );
		$needles = array();
		foreach ( (array) $rows as $row ) {
			foreach ( array( 'customer_name', 'customer_email', 'customer_phone' ) as $col ) {
				$v = trim( (string) $row[ $col ] );
				if ( mb_strlen( $v ) >= 3 ) {
					$needles[ mb_strtolower( $v ) ] = true;
				}
			}
		}
		$needles = array_map( 'strval', array_keys( $needles ) );

		// Sıra önemli: önce olaylar, satır (ve işareti) EN SON. Satır eşleşme anahtarı ve iğne kaynağıdır; olay yazımı
		// başarısız olursa satır dolu kalır, eraser/saklama işi yeniden bulur ve yeniden dener.
		$events = $wpdb->get_results( $wpdb->prepare( 'SELECT id, actor, data FROM %i WHERE reservation_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $evt ), $ids ) ), ARRAY_A );
		foreach ( (array) $events as $e ) {
			$change = array();
			$raw    = (string) $e['data'];
			$data   = json_decode( $raw, true );
			$clean  = is_array( $data ) ? (string) wp_json_encode( self::scrub( $data, $needles ) ) : '{}';
			if ( $clean !== $raw ) {
				$change['data'] = $clean;
			}
			if ( null !== $subjectUserId && (string) $subjectUserId === (string) $e['actor'] ) {
				$change['actor'] = 'system';
			}
			if ( $change && false === $wpdb->update( $evt, $change, array( 'id' => (int) $e['id'] ), null, array( '%d' ) ) ) {
				return false;
			}
		}

		// Satır yazımı depo yolundan (satır kilidi + işlem; "rezervasyona depo dışında yazılmaz" değişmezi, final review F12).
		// İşaret olayı (müşteri e-postaları anonimleşmiş satırda fatura e-postasına düşmeden gitmez) AYNI işlemde yazılır:
		// boşaltma başarısızsa işaret de geri alınır, işaretli satır kişisel veri taşıyamaz (pre-ZIP gate G5).
		try {
			ReservationRepository::scrubPersonal( $ids );
		} catch ( \Throwable $e ) {
			return false;
		}
		foreach ( $ids as $id ) {
			Reminders::unschedule( $id ); // Anonimleşen randevuya hatırlatma gitmez.
		}
		$ok = true;
		return $ok;
	}

	/**
	 * Olay verisinden kişisel alanları ayıklar (özyinelemeli): kişisel anahtarlar silinir; içinde bilinen ad/e-posta/telefon
	 * geçen yazı değerleri silinir. `notify`, `reason`, `from`/`to`, `order_id` gibi işlevsel alanlar kalır.
	 *
	 * @param array<mixed> $data    Veri.
	 * @param list<string> $needles Küçük harfli iğneler.
	 * @return array<mixed>
	 */
	public static function scrub( array $data, array $needles = array() ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		foreach ( $data as $key => $value ) {
			$k = is_string( $key ) ? strtolower( $key ) : '';
			if ( '' !== $k && ( 'customer' === $k || in_array( $k, self::PII_KEYS, true ) || str_starts_with( $k, 'customer_' ) || str_starts_with( $k, 'billing_' ) ) ) {
				unset( $data[ $key ] );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::scrub( $value, $needles );
			} elseif ( is_string( $value ) && ! in_array( $k, self::FUNCTIONAL_KEYS, true ) && self::mentions( $value, $needles ) ) {
				unset( $data[ $key ] );
			}
		}
		return $data;
	}

	/**
	 * Yazı iğnelerden birini içeriyor mu.
	 *
	 * @param string       $value   Değer.
	 * @param list<string> $needles Küçük harfli iğneler.
	 */
	private static function mentions( string $value, array $needles ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$low = mb_strtolower( $value );
		foreach ( $needles as $n ) {
			if ( str_contains( $low, $n ) ) {
				return true;
			}
		}
		return false;
	}
}
