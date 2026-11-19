<?php
/**
 * Rezervasyon satırının salt okunur değer nesnesi (spec §5).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- alan adları sözleşmeyle camelCase (Rules ile aynı kalıp).

/**
 * Bir randevu = bir dilim. Zamanlar UTC.
 */
final class Reservation {

	/**
	 * Yalnız `fromRow()` kurar.
	 *
	 * @param int                     $id              Kimlik.
	 * @param int                     $resourceId      Kaynak (v1 hep 1).
	 * @param int                     $productId       Ürün.
	 * @param int                     $variationId     Varyasyon (0 = yok).
	 * @param int|null                $orderId         Sipariş (null = elle eklenen).
	 * @param int|null                $orderItemId     Sipariş kalemi.
	 * @param \DateTimeImmutable      $startsAt        Başlangıç (UTC).
	 * @param \DateTimeImmutable      $endsAt          Bitiş (UTC, ara hariç).
	 * @param string                  $status          held|confirmed|completed|no_show|cancelled|expired|conflict|superseded.
	 * @param string                  $paymentState    pending|paid|on_site|awaiting_transfer|refunded|manual.
	 * @param \DateTimeImmutable|null $holdExpiresAt   Tutma bitişi (null = tutulmuyor).
	 * @param string                  $customerName    Anlık kopya.
	 * @param string                  $customerEmail   Anlık kopya.
	 * @param string                  $customerPhone   Anlık kopya.
	 * @param string                  $locationText    Anlık kopya.
	 * @param int                     $rescheduleCount Sayılan saat değişikliği.
	 * @param string                  $token           32 hex (müşteri bağlantısı, `.ics` UID).
	 * @param int                     $sequence        `.ics` sürümü.
	 * @param \DateTimeImmutable      $createdAt       Oluşturma (UTC).
	 * @param \DateTimeImmutable      $updatedAt       Güncelleme (UTC).
	 * @param string                  $productName     Ürün adının anlık kopyası (ürün silinse de gösterilir; şema v2).
	 * @param string|null             $sessionHash     WC oturum özeti (32 hex; null = oturumsuz, ör. elle).
	 * @param string|null             $ipHash          IP özeti (32 hex; kota için).
	 * @param int                     $capacity        Kapasite anlık görüntüsü (K4; alındığı andaki etkin kapasite, vars. 1).
	 */
	private function __construct(
		public readonly int $id,
		public readonly int $resourceId,
		public readonly int $productId,
		public readonly int $variationId,
		public readonly ?int $orderId,
		public readonly ?int $orderItemId,
		public readonly \DateTimeImmutable $startsAt,
		public readonly \DateTimeImmutable $endsAt,
		public readonly string $status,
		public readonly string $paymentState,
		public readonly ?\DateTimeImmutable $holdExpiresAt,
		public readonly string $customerName,
		public readonly string $customerEmail,
		public readonly string $customerPhone,
		public readonly string $locationText,
		public readonly int $rescheduleCount,
		public readonly string $token,
		public readonly int $sequence,
		public readonly \DateTimeImmutable $createdAt,
		public readonly \DateTimeImmutable $updatedAt,
		public readonly string $productName = '',
		public readonly ?string $sessionHash = null,
		public readonly ?string $ipHash = null,
		public readonly int $capacity = 1
	) {}

	/**
	 * Veritabanı satırından (ARRAY_A) kurar.
	 *
	 * @param array<string,mixed> $row Satır.
	 */
	public static function fromRow( array $row ): self {
		return new self(
			(int) $row['id'],
			(int) $row['resource_id'],
			(int) $row['product_id'],
			(int) $row['variation_id'],
			null === $row['order_id'] ? null : (int) $row['order_id'],
			null === $row['order_item_id'] ? null : (int) $row['order_item_id'],
			self::dt( (string) $row['starts_at'] ),
			self::dt( (string) $row['ends_at'] ),
			(string) $row['status'],
			(string) $row['payment_state'],
			null === $row['hold_expires_at'] ? null : self::dt( (string) $row['hold_expires_at'] ),
			(string) $row['customer_name'],
			(string) $row['customer_email'],
			(string) $row['customer_phone'],
			(string) $row['location_text'],
			(int) $row['reschedule_count'],
			(string) $row['token'],
			(int) $row['sequence'],
			self::dt( (string) $row['created_at'] ),
			self::dt( (string) $row['updated_at'] ),
			(string) ( $row['product_name'] ?? '' ),
			isset( $row['session_hash'] ) ? (string) $row['session_hash'] : null,
			isset( $row['ip_hash'] ) ? (string) $row['ip_hash'] : null,
			max( 1, (int) ( $row['capacity'] ?? 1 ) )
		);
	}

	/**
	 * Etkin mi (birim tutuyor): held|confirmed.
	 */
	public function isActive(): bool {
		return in_array( $this->status, ReservationRepository::ACTIVE, true );
	}

	/**
	 * UTC DATETIME dizgisini nesneye çevirir.
	 *
	 * @param string $s 'Y-m-d H:i:s'.
	 */
	private static function dt( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}
}
