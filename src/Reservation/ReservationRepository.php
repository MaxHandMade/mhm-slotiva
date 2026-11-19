<?php
/**
 * Rezervasyon deposu: atomik alma, bırakma, yeniden zamanlama, sorgu. Rezervasyon olaylarının TEK kaynağı.
 *
 * Çakışmayı veritabanı çözer: `slotiva_occupancy` UNIQUE KEY slotiva_unit(resource_id, unit_start, seat). Her yazma tek
 * bir InnoDB işleminde yapılır; birimler artan `unit_start` sırasıyla eklenir (kilitlenme önlemi); 1062 → (süresi geçmiş
 * tutma ise tembel bırakma + tek yeniden ekleme, değilse) ROLLBACK + SlotTakenException; 1213/1205 → TÜM işlem bir kez
 * baştan, sonra çakışma. Olaylar yalnız COMMIT'ten SONRA tetiklenir; geri alınan işlem olay üretmez.
 *
 * Serileştirme (spec §15.1, §15.3, §15.8):
 * - YARATMA kararları (yeni sipariş, oturum supersede, kota) adlı MySQL kilitleriyle serileştirilir; sıra her zaman
 *   `{prefix}slotiva_sess_{hash}` → `{prefix}slotiva_ip_{hash}` → `{prefix}slotiva_order_{id}` (ad alanı sunucu geneli
 *   olduğundan tablo önekiyle; işlemden ÖNCE alınır, `finally` ile bırakılır). Kilit alınamaz/okunamazsa
 *   LockBusyException ("karar verilemedi").
 * - Adlı kilit altındaki ARAMALAR (siparişin/oturumun satırları, kota sayımı) DÜZ okumadır: ikincil indekste 0 satıra
 *   `FOR UPDATE` boşluk kilidi alır; boşluk kilitleri birbiriyle uyumlu olduğundan ilgisiz müşterilerin eklemeleri
 *   birbirini bekler ve kilitlenir (1213). Anlık görüntü adlı kilitten SONRA başladığı için düz okuma günceldir.
 * - GEÇİŞLER yalnız VAR OLDUĞU bilinen satırları birincil anahtarla `FOR UPDATE` kilitler (kayıt kilidi) ve durumu/
 *   `sequence`'i YENİDEN okur; durum artık uymuyorsa ya da çağıranın beklentisi (`expect_*`) tutmazsa hiçbir şey
 *   yazılmaz (false). 1213/1205 yeniden denemesi de tükenirse LockBusyException (asla "saat dolu" değil).
 *
 * İç içe işlem yok: WC 11.2.1'de `wc_transaction_query` ödeme yolunda kullanılmaz (yalnız Fulfillments veri deposu);
 * deponun kendi işlemi iç içe açılmaya kalkılırsa LogicException atılır. Bu sınıfta `exit` eden yol yoktur; kilitler
 * `finally`de bırakılır.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

use MHM\Slotiva\Availability\Units;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- yöntem, parametre ve Reservation alan ve parametre adları sözleşmeyle camelCase (plan Task 6).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- özel tablolar; önbellek doluluk doğruluğunu bozar.
// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- transact() üzerinden dolaylı atılan istisnalar da sözleşme olarak belgelenir.

/**
 * Statik depo (eklentinin diğer depolarıyla aynı kalıp).
 */
final class ReservationRepository {

	/** Birim tutan (etkin) durumlar. */
	public const ACTIVE = array( 'held', 'confirmed' );

	/** Son durumlar: `releaseForOrder` bunlara dokunmaz. */
	public const TERMINAL = array( 'cancelled', 'expired', 'completed', 'no_show', 'superseded', 'conflict' );

	/** Bu durumlara geçen satırın birimleri silinir (setStatus da bu değişmezi korur). */
	public const FREEING = array( 'cancelled', 'expired', 'superseded', 'conflict' );

	/** Tüm geçerli durumlar. */
	public const STATUSES = array( 'held', 'confirmed', 'completed', 'no_show', 'cancelled', 'expired', 'conflict', 'superseded' );

	/** `reclaim()` ile aynı saatte yeniden alınabilen durumlar. */
	public const RECLAIMABLE = array( 'expired', 'conflict' );

	/** Oturum/IP başına en çok etkin `held` sipariş (spec §15.8). */
	public const HOLD_QUOTA = 2;

	/** Anonimleştirme işaret olayının türü (`scrubPersonal` yazar, `isAnonymised` okur). */
	public const ANONYMISED = 'anonymised';

	/** `replaceForOrder` tarafından `superseded` yapılanlar (süresi dolmuş seçim sonradan yeniden alınmasın). */
	private const REPLACEABLE = array( 'held', 'confirmed', 'expired' );

	/** Oturumun DİĞER siparişlerinde supersede edilenler: ödenmemiş kart taslakları (ayrıca payment_state=pending). `confirmed` ve havale korunur. */
	private const SESSION_SUPERSEDABLE = array( 'held', 'expired' );

	/** Adlı kilit bekleme süresi (sn; `slotiva_lock_timeout` süzgeciyle 0–60). */
	private const LOCK_TIMEOUT = 10;

	/** MySQL ER_DUP_ENTRY. */
	private const ER_DUP = 1062;

	/** MySQL ER_LOCK_DEADLOCK. */
	private const ER_DEADLOCK = 1213;

	/** MySQL ER_LOCK_WAIT_TIMEOUT (kilitlenme gibi ele alınır: bir yeniden deneme, sonra çakışma). */
	private const ER_LOCK_WAIT = 1205;

	private const FMT = 'Y-m-d H:i:s';

	/**
	 * Açık işlemde biriken, COMMIT sonrası tetiklenecek olaylar: [kanca adı, bağımsız değişkenler].
	 *
	 * @var list<array{0:string,1:list<mixed>}>
	 */
	private static array $pending = array();

	/**
	 * Deponun işlemi açık mı (iç içe BEGIN engeli).
	 *
	 * @var bool
	 */
	private static bool $in_tx = false;

	/**
	 * Dilimleri tek işlemde, hepsi ya da hiçbiri olarak alır. `session_hash` verilmişse AYNI işlemde oturumun diğer
	 * siparişlerinin ödenmemiş satırları `superseded` olur; `held` alımda oturum ve IP kotası denetlenir.
	 *
	 * @param array<string,mixed> $req product_id, product_name, variation_id, order_id, order_item_id,
	 *                                 slots (list<\DateTimeImmutable>), duration, buffer, status (held|confirmed),
	 *                                 payment_state, hold_expires_at, customer {name,email,phone}, location, notify (vars. true),
	 *                                 session_hash, ip_hash (32 hex ya da null).
	 * @return list<int> Yeni rezervasyon kimlikleri, dilim başlangıcına göre artan.
	 * @throws SlotTakenException Bir birim dolu; hiçbir satır yazılmadı.
	 * @throws LockBusyException Oturum/IP/sipariş kilidi alınamadı (→ 409 slotiva_busy).
	 * @throws HoldQuotaException Kota aşıldı (→ 429 slotiva_hold_quota).
	 */
	public static function claim( array $req ): array {
		$req     = self::normalize( $req );
		$except  = (int) ( $req['order_id'] ?? 0 );
		$exclude = self::sessionRowIds( $req, $except );
		return self::transact(
			static function () use ( $req, $except ): array {
				self::sessionPhase( $req, $except );
				return self::insertClaim( $req );
			},
			static fn( string $unit ): array => self::takenSlots( $req, $unit, $exclude ),
			self::claimLocks( $req )
		);
	}

	/**
	 * Siparişin etkin (son durumda olmayan) satırlarını bırakır: birimler silinir, durum yazılır. Sipariş kilidi altında.
	 *
	 * @param int    $orderId Sipariş.
	 * @param string $status  Yeni durum.
	 * @return int Değişen satır sayısı.
	 * @throws LockBusyException Sipariş kilidi alınamadı.
	 */
	public static function releaseForOrder( int $orderId, string $status ): int {
		self::assertReleaseStatus( $status );
		return self::transact(
			static function () use ( $orderId, $status ): int {
				$rows = self::lockOrder( $orderId, self::TERMINAL, false );
				foreach ( $rows as $r ) {
					self::deleteUnits( $r->id );
					self::writeStatus( $r, $status, null, self::ctx( array( 'reason' => 'order' ), $r->orderId ) );
				}
				return count( $rows );
			},
			static fn(): array => array(),
			array( self::orderLock( $orderId ) )
		);
	}

	/**
	 * Sipariş geneli iptal, TEK işlemde ve sipariş kilidi altında: `$from` durumlarındaki satırlar `cancelled` olur
	 * (birimleri silinir; iptal `sequence`'i artırır, §15.11). Olaylar hepsi yazıldıktan sonra (COMMIT sonrası) gelir, bu
	 * yüzden müşteri iptal e-postası sipariş başına bir kez toplanır (final review F6). Yineleme zararsız (0).
	 *
	 * @param int          $orderId      Sipariş.
	 * @param string       $reason       Olay nedeni (ör. `refunded`, `order_deleted`).
	 * @param list<string> $from         İptal edilecek durumlar (varsayılan: etkin olanlar).
	 * @param string|null  $paymentState Yeni ödeme durumu (null = değişmez).
	 * @param bool         $notify       Müşteriye bildirilsin mi (olaya geçer).
	 * @return int İptal edilen satır sayısı.
	 * @throws LockBusyException Sipariş kilidi alınamadı.
	 */
	public static function cancelForOrder( int $orderId, string $reason, array $from = self::ACTIVE, ?string $paymentState = null, bool $notify = true ): int { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		return self::transact(
			static function () use ( $orderId, $reason, $from, $paymentState, $notify ): int {
				$rows = self::lockOrder( $orderId, $from, true );
				foreach ( $rows as $r ) {
					self::deleteUnits( $r->id );
					self::writeStatus(
						$r,
						'cancelled',
						$paymentState,
						self::ctx(
							array(
								'notify' => $notify,
								'reason' => $reason,
							),
							$r->orderId
						)
					);
				}
				return count( $rows );
			},
			static fn(): array => array(),
			array( self::orderLock( $orderId ) )
		);
	}

	/**
	 * Kişisel sütunları boşaltır (KVKK/GDPR; `Privacy\Anonymiser` tek çağıranı): ad/e-posta/telefon, oturum ve IP özeti.
	 * Satırlar birincil anahtarla kilitlenir, tek işlemde yazılır; randevu kaydı (tarih, ürün, durum) kalır. Aynı işlemde
	 * (yoksa) `anonymised` işaret olayı yazılır (pre-ZIP gate G5): işaret yalnız boşaltmayla birlikte kalıcılaşır, işaretli
	 * satır kişisel veri taşıyamaz; işaret müşteri e-postalarını keser (`isAnonymised`).
	 *
	 * @param list<int> $ids Rezervasyonlar.
	 * @return int Boşaltılan (var olan) satır sayısı.
	 * @throws \RuntimeException Veritabanı hatası (çağıran satırı "tutuldu" sayar).
	 */
	public static function scrubPersonal( array $ids ): int { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $i ): bool => $i > 0 ) ) );
		if ( ! $ids ) {
			return 0;
		}
		return self::transact(
			static function () use ( $ids ): int {
				global $wpdb;
				$locked = self::lockIds( $ids, static fn(): bool => true );
				if ( ! $locked ) {
					return 0;
				}
				$res  = Schema::table( 'reservations' );
				$keep = array_map( static fn( Reservation $r ): int => $r->id, $locked );
				// `updated_at` bilerek değişmez (süpürücünün havale geriye bakışı ona dayanır).
				self::guard( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET customer_name = \'\', customer_email = \'\', customer_phone = \'\', session_hash = NULL, ip_hash = NULL WHERE id IN (' . implode( ',', array_fill( 0, count( $keep ), '%d' ) ) . ')', array_merge( array( $res ), $keep ) ) ) );
				$evt = Schema::table( 'events' );
				self::guard( $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (reservation_id, type, actor, data, created_at) SELECT r.id, %s, \'system\', \'{}\', %s FROM %i r WHERE r.id IN (' . implode( ',', array_fill( 0, count( $keep ), '%d' ) ) . ') AND NOT EXISTS (SELECT 1 FROM %i x WHERE x.reservation_id = r.id AND x.type = %s)', array_merge( array( $evt, self::ANONYMISED, Clock::now()->format( 'Y-m-d H:i:s' ), $res ), $keep, array( $evt, self::ANONYMISED ) ) ) ) );
				return count( $locked );
			},
			static fn(): array => array()
		);
	}

	/**
	 * Tek işlemde: (oturum supersede + kota) → siparişin held|confirmed|expired satırları `superseded` (birimleri
	 * silinir) → yeni dilimler alınır. Çakışmada tamamı geri alınır; eski satırlar olduğu gibi kalır.
	 *
	 * @param int                 $orderId Sipariş.
	 * @param array<string,mixed> $req     `claim()` isteği (order_id bu siparişe zorlanır).
	 * @return list<int> Yeni kimlikler.
	 * @throws SlotTakenException Yeni dilimlerden biri dolu.
	 * @throws LockBusyException Kilit alınamadı.
	 * @throws HoldQuotaException Kota aşıldı.
	 */
	public static function replaceForOrder( int $orderId, array $req ): array {
		$req     = self::normalize( array_merge( $req, array( 'order_id' => $orderId ) ) );
		$exclude = array_merge(
			array_map( static fn( Reservation $r ): int => $r->id, self::forOrder( $orderId ) ),
			self::sessionRowIds( $req, $orderId )
		);
		return self::transact(
			static function () use ( $orderId, $req ): array {
				self::sessionPhase( $req, $orderId );
				$rows = self::lockOrder( $orderId, self::REPLACEABLE, true );
				// Kabul, siparişin satırları silinmeden ÖNCE: aynı başlangıcın yeniden alımı kendi koltuğunu tanır (K5).
				$plan = self::planClaim( $req, $orderId, self::carried( $rows ) );
				foreach ( $rows as $r ) {
					self::deleteUnits( $r->id );
					self::writeStatus(
						$r,
						'superseded',
						null,
						self::ctx(
							array(
								'notify' => false,
								'reason' => 'replaced',
							),
							$orderId
						),
						'replace'
					);
				}
				return self::insertClaim( $req, $plan );
			},
			static fn( string $unit ): array => self::takenSlots( $req, $unit, $exclude ),
			self::claimLocks( $req )
		);
	}

	/**
	 * Order-pay alması (Store API `/checkout/{id}`, klasik form ve varsayılan olarak her diğer çağıran): supersede edilmiş
	 * sipariş REDDEDİLİR (§15.3). Ayrıntı: claimOrder().
	 *
	 * @param int                                                         $orderId  Sipariş.
	 * @param array<string,mixed>                                         $req      `claim()` isteği; `slots` = siparişin kayıtlı talebi.
	 * @param callable(list<\DateTimeImmutable>):list<\DateTimeImmutable> $validate Yeni alma öncesi doğrulanmış dilimler.
	 * @return array{ids:list<int>,reused:bool}
	 * @throws SlotTakenException Yeni dilimlerden biri dolu.
	 * @throws LockBusyException Kilit alınamadı ya da işlem sonucu belirlenemedi.
	 * @throws HoldQuotaException Kota aşıldı.
	 * @throws OrderSupersededException Siparişin son kuşağı supersede edilmiş.
	 * @throws OrderCancelledException Siparişin son kuşağında iptal edilmiş satır var.
	 */
	public static function claimForOrderPay( int $orderId, array $req, callable $validate ): array {
		return self::claimOrder( $orderId, $req, $validate, true );
	}

	/**
	 * Ana ödeme yolunda oturumun GÜNCEL taslağının yerleştirilmesi: supersede edilmiş satırlar geçmiş olarak kalır, dilimler
	 * yeniden alınır (adet değişimi taslağın kendi satırlarını supersede etmiş olabilir). Yalnız CheckoutFlow, siparişi bu
	 * istekte ana ödeme yolundan yakaladıysa çağırır. Ayrıntı: claimOrder().
	 *
	 * @param int                                                         $orderId  Sipariş.
	 * @param array<string,mixed>                                         $req      `claim()` isteği; `slots` = siparişin kayıtlı talebi.
	 * @param callable(list<\DateTimeImmutable>):list<\DateTimeImmutable> $validate Yeni alma öncesi doğrulanmış dilimler.
	 * @return array{ids:list<int>,reused:bool}
	 * @throws SlotTakenException Yeni dilimlerden biri dolu.
	 * @throws LockBusyException Kilit alınamadı ya da işlem sonucu belirlenemedi.
	 * @throws HoldQuotaException Kota aşıldı.
	 */
	public static function claimForCheckoutDraft( int $orderId, array $req, callable $validate ): array {
		return self::claimOrder( $orderId, $req, $validate, false );
	}

	/**
	 * Ödeme yolunun idempotent alması (Store API ödeme + order-pay, klasik order-pay): KARAR ve YAZMA tek işlemde, oturum →
	 * IP → sipariş kilitleri altında; 1213/1205'te karar dahil tamamı baştan (spec §15.1, §15.3, §15.5).
	 *
	 * 1. Siparişin tüm satırları birincil anahtarla kilitlenir ve yeniden okunur.
	 * 2. YALNIZ order-pay yollarında (`$rejectSuperseded`): etkin satır yoksa ve son kuşak (en büyük kimlik) `superseded`
	 *    ise yeniden alma reddedilir (OrderSupersededException; sonradan gelen ödeme PaymentListener'da `conflict` olur,
	 *    §15.3). Ana ödeme yolunda sipariş oturumun GÜNCEL taslağıdır (adet değişimi `supersedeForSession(…, 0)` ile kendi
	 *    satırlarını da supersede etmiş olabilir; sepet özeti geri eşleşince WC aynı taslağı kullanır): bu yeni bir
	 *    yerleştirmedir, supersede satırlar yerinde kalır ve dilimler yeniden alınır.
	 *    Yine yalnız order-pay'de: son kuşakta (en son oluşturma anındaki satırlar) `cancelled` satır varsa reddedilir
	 *    (OrderCancelledException; yöneticinin iptal ettiği saat geri gelmez, final review F2). Etkin satır sayısı talebe
	 *    eşitse dilimler canlı satırlardan alınır (yönetici saat değişimi bayat talebi ezmez, F1).
	 *    Her iki yolda: kilitli satırlardan biri `confirmed/paid` ise hiçbir şey yazılmaz, etkin satırlar döner (F13).
	 * 3. Etkin satırlar istekle birebir aynıysa (ürün/varyasyon, dilimler, durum, ödeme durumu; `held` ise hepsinin süresi
	 *    geçmemiş) yeniden kullanılır: `held` ise bitiş yenilenir ve `slotiva_hold_refreshed` COMMIT'ten sonra tetiklenir.
	 * 4. Aksi hâlde `$validate( dilimler )` (takvim yeniden doğrulaması; istisnası işlemi geri alır) → oturum supersede + kota →
	 *    siparişin held|confirmed|expired satırları `superseded` → yeni dilimler alınır.
	 *
	 * @param int                                                         $orderId          Sipariş.
	 * @param array<string,mixed>                                         $req              `claim()` isteği; `slots` = siparişin kayıtlı talebi.
	 * @param callable(list<\DateTimeImmutable>):list<\DateTimeImmutable> $validate         Yeni alma öncesi doğrulanmış dilimler (yeniden kullanımda çağrılmaz).
	 * @param bool                                                        $rejectSuperseded Order-pay yolu: supersede edilmiş siparişi reddet.
	 * @return array{ids:list<int>,reused:bool} Etkin kimlikler; reused = mevcut tutma kullanıldı.
	 * @throws SlotTakenException Yeni dilimlerden biri dolu; hiçbir şey değişmedi.
	 * @throws LockBusyException Kilit alınamadı ya da işlem sonucu belirlenemedi.
	 * @throws HoldQuotaException Kota aşıldı.
	 * @throws OrderSupersededException Order-pay yolunda siparişin son kuşağı supersede edilmiş.
	 * @throws OrderCancelledException Order-pay yolunda siparişin son kuşağında iptal edilmiş satır var.
	 */
	private static function claimOrder( int $orderId, array $req, callable $validate, bool $rejectSuperseded ): array {
		$req     = self::normalize( array_merge( $req, array( 'order_id' => $orderId ) ) );
		$exclude = array_merge(
			array_map( static fn( Reservation $r ): int => $r->id, self::forOrder( $orderId ) ),
			self::sessionRowIds( $req, $orderId )
		);
		return self::transact(
			static function () use ( $orderId, $req, $validate, $rejectSuperseded ): array {
				$rows   = self::lockOrder( $orderId, self::STATUSES, true );
				$active = array_values( array_filter( $rows, static fn( Reservation $r ): bool => in_array( $r->status, self::ACTIVE, true ) ) );
				// Ödenmiş satır asla supersede edilmez (final review F13): bellekteki sipariş ağ geçidi geri çağrısından önce
				// okunmuş olabilir; kilit altında görülen confirmed+paid satır aynen kullanılır.
				foreach ( $active as $r ) {
					if ( 'confirmed' === $r->status && 'paid' === $r->paymentState ) {
						return array(
							'ids'    => array_map( static fn( Reservation $a ): int => $a->id, $active ),
							'reused' => true,
						);
					}
				}
				if ( $rejectSuperseded && ! $active && $rows ) {
					$latest = $rows[0];
					foreach ( $rows as $r ) {
						$latest = $r->id > $latest->id ? $r : $latest;
					}
					if ( 'superseded' === $latest->status ) {
						throw new OrderSupersededException( (int) $orderId );
					}
				}
				if ( $rejectSuperseded && self::latestGenerationCancelled( $rows ) ) {
					throw new OrderCancelledException( (int) $orderId );
				}
				if ( $rejectSuperseded && $active && count( $active ) === count( $req['slots'] ) ) {
					// Order-pay'de müşteri saat seçemez: canlı satırlar (yönetici saati değiştirmiş olabilir) bayat kayıtlı
					// talepten önce gelir (final review F1). Karar kilit altında; eşzamanlı saat değişimi de görülür.
					$req['slots'] = array_map( static fn( Reservation $r ): \DateTimeImmutable => $r->startsAt, $active );
				}
				if ( self::reusable( $active, $req ) ) {
					self::refreshLocked( $orderId, $active, $req['status'], $req['hold_expires_at'] );
					return array(
						'ids'    => array_map( static fn( Reservation $r ): int => $r->id, $active ),
						'reused' => true,
					);
				}
				$req = self::normalize( array_merge( $req, array( 'slots' => $validate( $req['slots'] ) ) ) );
				self::sessionPhase( $req, $orderId );
				// Kabul, siparişin satırları silinmeden ÖNCE (K5); yeniden ekleme mevcut kapasiteyi taşır (K4).
				$plan = self::planClaim( $req, $orderId, self::carried( array_values( array_filter( $rows, static fn( Reservation $r ): bool => in_array( $r->status, self::REPLACEABLE, true ) ) ) ) );
				foreach ( $rows as $r ) {
					if ( ! in_array( $r->status, self::REPLACEABLE, true ) ) {
						continue;
					}
					self::deleteUnits( $r->id );
					self::writeStatus(
						$r,
						'superseded',
						null,
						self::ctx(
							array(
								'notify' => false,
								'reason' => 'replaced',
							),
							$orderId
						),
						'replace'
					);
				}
				return array(
					'ids'    => self::insertClaim( $req, $plan ),
					'reused' => false,
				);
			},
			static fn( string $unit ): array => self::takenSlots( $req, $unit, $exclude ),
			self::claimLocks( $req )
		);
	}

	/**
	 * Oturumun `$exceptOrderId` dışındaki siparişlerinin kart taslağı satırlarını (held|expired + payment_state=pending) `superseded`
	 * yapar (birimleri silinir; olay notify=false, reason=session). Oturum kilidi altında, tek işlemde. `confirmed`, havale
	 * (`awaiting_transfer`) ve diğer ödeme durumlarındaki satırlara dokunmaz: onlar verilmiş siparişlerdir.
	 *
	 * @param string $sessionHash   Oturum özeti (32 hex).
	 * @param int    $exceptOrderId Korunacak sipariş (0 = yok).
	 * @return int Supersede edilen satır sayısı.
	 * @throws LockBusyException Oturum kilidi alınamadı.
	 * @throws \InvalidArgumentException Geçersiz özet.
	 */
	public static function supersedeForSession( string $sessionHash, int $exceptOrderId ): int {
		$hash = self::hashOrNull( $sessionHash );
		if ( null === $hash ) {
			throw new \InvalidArgumentException( 'slotiva_invalid_hash' );
		}
		return self::transact(
			static fn(): int => self::supersedeSessionRows( $hash, $exceptOrderId ),
			static fn(): array => array(),
			array( self::lockName( 'slotiva_sess_' . $hash ) )
		);
	}

	/**
	 * Saati değiştirir: tek işlemde ÖNCE kendi birimleri silinir, SONRA yenileri eklenir (InnoDB silinen anahtarları
	 * işlem bitene dek kilitli tutar). Çakışmada eski durum korunur. `sequence++`; `reschedule_count++` yalnız $countChange.
	 *
	 * `conflict` satır (ödendi ama saati alınamadı; birimi yok) da yeni saate taşınabilir (final review F3, spec §6.4
	 * "yeni saat elle"): yeni saatin birimleri (süre + GÜNCEL ara) alınır ve satır `confirmed` olur (ödeme durumu korunur,
	 * olay reason=admin_reschedule). Başka randevunun üstüne yazılmaz (1062 → SlotTakenException).
	 *
	 * @param int                $id             Rezervasyon.
	 * @param \DateTimeImmutable $newStartUtc    Yeni başlangıç.
	 * @param bool               $countChange    Değişiklik sayılsın mı.
	 * @param bool               $notify         Müşteriye bildirilsin mi (olaya geçer).
	 * @param int|null           $expectSequence Beklenen `sequence` (null = denetleme); tutmazsa no-op.
	 * @return bool Uygulandı mı (false = bayat karar, hiçbir şey yazılmadı).
	 * @throws SlotTakenException Yeni saat dolu.
	 * @throws \LogicException Rezervasyon yok ya da etkin değil.
	 */
	public static function reschedule( int $id, \DateTimeImmutable $newStartUtc, bool $countChange, bool $notify, ?int $expectSequence = null ): bool {
		$new_start = $newStartUtc->setTimezone( new \DateTimeZone( 'UTC' ) );
		$buffer    = Rules::load()->bufferMin;
		return self::transact(
			static function () use ( $id, $new_start, $countChange, $notify, $expectSequence, $buffer ): bool {
				global $wpdb;
				$r = self::lockById( $id );
				if ( null === $r || ( ! $r->isActive() && 'conflict' !== $r->status ) ) {
					throw new \LogicException( 'slotiva_reservation_not_active' );
				}
				if ( null !== $expectSequence && $expectSequence !== $r->sequence ) {
					return false;
				}
				$conflict = 'conflict' === $r->status;
				$duration = (int) ( ( $r->endsAt->getTimestamp() - $r->startsAt->getTimestamp() ) / 60 );
				$occ      = Schema::table( 'occupancy' );
				$held     = (int) self::scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE reservation_id = %d', $occ, $id ) );
				// Ara, eski birim sayısından korunur; birimi olmayan çakışma satırı güncel arayı alır.
				$minutes = $held > 0 ? $held * Rules::STEP : $duration + ( $conflict ? $buffer : 0 );
				$units   = Units::span( $new_start, $minutes, 0 );
				// Taşımada kabul (K4): kendi satırları sayılmaz, satırın `capacity`'si sınıra girer ve yeniden damgalanmaz.
				$seat = SeatPolicy::admit( Units::RESOURCE_ID, $r->productId, $new_start, $units, $r->capacity, $id );

				self::deleteUnits( $id );
				self::insertUnits( Units::RESOURCE_ID, $id, $units, $seat );
				self::sweepStale( $units, array( $id ) );

				$res = Schema::table( 'reservations' );
				self::guard(
					$wpdb->query(
						$wpdb->prepare(
							'UPDATE %i SET starts_at = %s, ends_at = %s, sequence = sequence + 1, reschedule_count = reschedule_count + %d, updated_at = %s WHERE id = %d',
							$res,
							$new_start->format( self::FMT ),
							$new_start->modify( '+' . $duration . ' minutes' )->format( self::FMT ),
							$countChange ? 1 : 0,
							Clock::now()->format( self::FMT ),
							$id
						)
					)
				);
				self::logEvent(
					$id,
					'rescheduled',
					array(
						'from'    => $r->startsAt->format( 'c' ),
						'to'      => $new_start->format( 'c' ),
						'counted' => $countChange,
					)
				);
				if ( $conflict ) {
					self::writeStatus(
						$r,
						'confirmed',
						null,
						self::ctx(
							array(
								'notify' => $notify,
								'reason' => 'admin_reschedule',
							),
							$r->orderId
						),
						null
					);
				}
				self::$pending[] = array( 'slotiva_reservation_rescheduled', array( $id, $r->startsAt, $notify ) );
				self::queueSaved( $id, 'reschedule' );
				return true;
			},
			static fn(): array => array( $new_start ),
			array( self::resourceLock( Units::RESOURCE_ID ) )
		);
	}

	/**
	 * Durumu (ve isteğe bağlı ödeme durumunu) yazar. `held` dışı durumda tutma süresi temizlenir; FREEING durumuna geçişte
	 * birimler de silinir (birim yalnız etkin satırda kalır). Durum aynıysa olay üretilmez.
	 *
	 * @param int                 $id           Rezervasyon.
	 * @param string              $status       Yeni durum.
	 * @param string|null         $paymentState Yeni ödeme durumu (null = değişmez).
	 * @param array<string,mixed> $ctx          notify, reason; beklenti: expect_status (list), expect_sequence (int),
	 *                                          expect_hold_expires_at (\DateTimeImmutable|null; anahtar varsa denetlenir).
	 * @return bool Uygulandı mı (false = kilit altında yeniden okunan satır beklentiye uymadı; hiçbir şey yazılmadı).
	 * @throws \LogicException Rezervasyon yok ya da birimsiz (FREEING) satır etkin duruma geçirilmek isteniyor (→ reclaim()).
	 */
	public static function setStatus( int $id, string $status, ?string $paymentState = null, array $ctx = array() ): bool {
		self::assertStatus( $status );
		return self::transact(
			static function () use ( $id, $status, $paymentState, $ctx ): bool {
				$r = self::lockById( $id );
				if ( null === $r ) {
					throw new \LogicException( 'slotiva_reservation_not_found' );
				}
				if ( ! self::expected( $r, $ctx ) ) {
					return false;
				}
				if ( in_array( $r->status, self::FREEING, true ) && in_array( $status, self::ACTIVE, true ) ) {
					throw new \LogicException( 'slotiva_reactivate_requires_claim' );
				}
				if ( in_array( $status, self::FREEING, true ) ) {
					self::deleteUnits( $id );
				}
				self::writeStatus( $r, $status, $paymentState, self::ctx( $ctx, $r->orderId ) );
				return true;
			},
			static fn(): array => array()
		);
	}

	/**
	 * Birimleri siler ve durumu yazar. Hedef durum etkin (held|confirmed) olamaz.
	 *
	 * @param int                 $id     Rezervasyon.
	 * @param string              $status Yeni durum.
	 * @param array<string,mixed> $ctx    notify, reason, expect_* (bkz. setStatus).
	 * @return bool Uygulandı mı (false = beklentiye uymadı, no-op).
	 * @throws \LogicException Rezervasyon yok.
	 */
	public static function release( int $id, string $status, array $ctx = array() ): bool {
		self::assertReleaseStatus( $status );
		return self::transact(
			static function () use ( $id, $status, $ctx ): bool {
				$r = self::lockById( $id );
				if ( null === $r ) {
					throw new \LogicException( 'slotiva_reservation_not_found' );
				}
				if ( ! self::expected( $r, $ctx ) ) {
					return false;
				}
				self::deleteUnits( $id );
				self::writeStatus( $r, $status, null, self::ctx( $ctx, $r->orderId ) );
				return true;
			},
			static fn(): array => array()
		);
	}

	/**
	 * Sipariş geneli, atomik yeniden alma (spec §6.4 "süre dolduktan sonra ödeme geldi"): verilen rezervasyonun
	 * siparişindeki TÜM expired|conflict satırları kendi saatlerinde birimlerini geri alır ve `confirmed` olur — ya hepsi
	 * ya hiçbiri. Siparişsiz satırda yalnız o satır. Sipariş kilidi + satır kilitleri altında.
	 *
	 * @param int                 $id  Siparişin herhangi bir rezervasyonu.
	 * @param array<string,mixed> $ctx notify, reason, payment_state (isteğe bağlı), buffer (dk; vars. Rules::bufferMin),
	 *                                 expect_* (verilen satır için; bkz. setStatus).
	 * @return bool Yeniden alındı mı (false = geri alınacak satır yok ya da beklenti tutmadı; no-op).
	 * @throws SlotTakenException Satırlardan birinin saati dolu; hiçbiri değişmedi (çağıran `conflict` yazabilir).
	 * @throws \LogicException Rezervasyon yok ya da durumu reclaim'e uygun değil (superseded|cancelled|completed|no_show).
	 * @throws LockBusyException Sipariş kilidi alınamadı.
	 */
	public static function reclaim( int $id, array $ctx = array() ): bool {
		$buffer  = isset( $ctx['buffer'] ) ? max( 0, (int) $ctx['buffer'] ) : Rules::load()->bufferMin;
		$payment = isset( $ctx['payment_state'] ) ? (string) $ctx['payment_state'] : null;
		$row     = self::find( $id );
		if ( null === $row ) {
			throw new \LogicException( 'slotiva_reservation_not_found' );
		}
		$orderId = $row->orderId;
		// Yalnız çakışma iletisi için (ROLLBACK sonrası hangi dilimlerin dolu olduğu).
		$for_err = array_values( array_filter( null === $orderId ? array( $row ) : self::forOrder( $orderId ), static fn( Reservation $r ): bool => in_array( $r->status, self::RECLAIMABLE, true ) ) );
		return self::transact(
			static function () use ( $id, $orderId, $ctx, $buffer, $payment ): bool {
				global $wpdb;
				$rows = null === $orderId ? array_filter( array( self::lockById( $id ) ) ) : self::lockOrder( $orderId, self::STATUSES, true );
				$me   = null;
				foreach ( $rows as $r ) {
					if ( $r->id === $id ) {
						$me = $r;
					}
				}
				if ( null === $me ) {
					throw new \LogicException( 'slotiva_reservation_not_found' );
				}
				if ( ! in_array( $me->status, array_merge( self::RECLAIMABLE, self::ACTIVE ), true ) ) {
					throw new \LogicException( 'slotiva_reservation_not_reclaimable' );
				}
				if ( ! self::expected( $me, $ctx ) ) {
					return false;
				}
				$targets = array_values( array_filter( $rows, static fn( Reservation $r ): bool => in_array( $r->status, self::RECLAIMABLE, true ) ) );
				if ( ! $targets ) {
					return false;
				}
				// Taşımada kabul (K4; K5 muafiyeti YOK: koltuk bırakılmış): her satır kendi `capacity`'siyle, ürün kapasitesi
				// yazma başına bir kez (D4). Ret → hiçbiri yazılmaz; çağıran `conflict` yazar (D5(b)).
				$units   = array();
				$all     = array();
				$refused = array();
				$current = array();
				foreach ( $targets as $r ) {
					$span                       = Units::span( $r->startsAt, self::minutes( $r ), $buffer );
					$current[ $r->productId ] ??= Capacity::forProduct( $r->productId );
					try {
						$seat = SeatPolicy::admit( Units::RESOURCE_ID, $r->productId, $r->startsAt, $span, $r->capacity, $r->id, null, $current[ $r->productId ] );
					} catch ( SlotTakenException $e ) {
						$refused = array_merge( $refused, $e->taken );
						continue;
					}
					foreach ( $span as $u ) {
						$units[] = array( $u, $r, $seat );
						$all[]   = $u;
					}
				}
				if ( $refused ) {
					// İleti sabit kod (`slotiva_slot_taken`); taşınan veri DateTimeImmutable listesidir, metin değil.
					$taken = new SlotTakenException( $refused );
					throw $taken;
				}
				usort( $units, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
				foreach ( $units as [ $u, $r, $seat ] ) {
					self::insertUnits( Units::RESOURCE_ID, $r->id, array( $u ), $seat );
				}
				self::sweepStale( $all, array_map( static fn( Reservation $r ): int => $r->id, $targets ) );
				$res = Schema::table( 'reservations' );
				foreach ( $targets as $r ) {
					// `.ics` sürümü: yeniden etkinleşen satırın sonraki REQUEST'i, daha önce gitmiş olabilecek CANCEL'dan
					// kesin büyük SEQUENCE taşımalı (RFC 5546 §2.1.5; Task 11 I1).
					self::guard( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET sequence = sequence + 1 WHERE id = %d', $res, $r->id ) ) );
					self::writeStatus( $r, 'confirmed', $payment, self::ctx( $ctx, $r->orderId, 'reclaim' ), 'reclaim' );
				}
				return true;
			},
			static fn( string $unit ): array => self::takenFromSpans( self::rowSpans( $for_err, $buffer ), $unit, array_map( static fn( Reservation $r ): int => $r->id, $for_err ) ),
			null === $orderId ? array( self::resourceLock( Units::RESOURCE_ID ) ) : array( self::orderLock( $orderId ), self::resourceLock( Units::RESOURCE_ID ) )
		);
	}

	/**
	 * "Bir kez" işareti (spec §15.7: hatırlatma/onay/iptal e-postası gönderildi): satır kilidi altında satırı yeniden okur,
	 * beklentiyi (`expect_*`) denetler ve aynı türde, `$criteria` alanlarını taşıyan olay yoksa olayı yazar. Yan etkiyi (e-posta)
	 * çağıran COMMIT'ten SONRA yapar; böylece eşzamanlı iki iş en çok bir kez gönderir.
	 *
	 * Doğruluk okuma sırasına bağlıdır: önce KİLİTLİ okuma (`lockById`, InnoDB RR anlık görüntüsünü kurmaz), sonra olayların
	 * düz okuması (anlık görüntü kilitten sonra kurulur, bekleyenin COMMIT'ini görür). Başa düz bir okuma eklemek yarışı açar.
	 *
	 * @param int                      $id           Rezervasyon.
	 * @param string                   $type         Olay türü (ör. `reminder_sent`, `mailed_cancelled`).
	 * @param array<string,mixed>      $data         Yazılacak olay verisi.
	 * @param array<string,mixed>      $ctx          expect_status, expect_sequence, expect_hold_expires_at (bkz. setStatus).
	 * @param array<string,mixed>|null $criteria        "Zaten yapıldı" ölçütü: bu alanları aynı değerle taşıyan aynı türde olay
	 *                                               (null = `$data`'nın tamamı; boş dizi = türün herhangi bir olayı).
	 * @param bool                     $bumpSequence Aynı işlemde `sequence = sequence + 1` (iptal `.ics`'i yeni sürümdür, §15.11).
	 * @param bool                     $perCycle     İşaret sürüm (yeniden etkinleşme döngüsü) başınadır: ölçüte kilitli satırın
	 *                                               `sequence`'i eklenir, olaya işaretten SONRAKİ `sequence` yazılır. Aynı
	 *                                               döngüde yineleme bastırılır; `reclaim`/saat değişimi sayacı artırdıysa yeni
	 *                                               döngü kendi işaretini alır (ör. ikinci CANCEL).
	 * @return Reservation|null Kilit altında okunan (bump'ta güncellenmiş) satır; null = satır yok, beklenti tutmadı ya da zaten işaretli.
	 */
	public static function recordOnce( int $id, string $type, array $data = array(), array $ctx = array(), ?array $criteria = null, bool $bumpSequence = false, bool $perCycle = false ): ?Reservation {
		$criteria = $criteria ?? $data;
		return self::transact(
			static function () use ( $id, $type, $data, $ctx, $criteria, $bumpSequence, $perCycle ): ?Reservation {
				global $wpdb;
				$r = self::lockById( $id );
				if ( null === $r || ! self::expected( $r, $ctx ) ) {
					return null;
				}
				if ( $perCycle ) {
					// Ölçüt = şimdiki sürüm; yazılan = işaretten sonraki sürüm. Böylece aynı döngünün yinelemesi (sayaç
					// değişmedi) önceki işaretle eşleşir, sayaç arada arttıysa eşleşmez.
					$criteria['sequence'] = $r->sequence;
					$data['sequence']     = $r->sequence + ( $bumpSequence ? 1 : 0 );
				}
				foreach ( EventLog::for( $id ) as $e ) {
					if ( $e['type'] === $type && array_intersect_key( $e['data'], $criteria ) == $criteria ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- JSON'dan çözülen dizi: anahtar sırası/sayı türü değil içerik karşılaştırılır.
						return null;
					}
				}
				self::logEvent( $id, $type, $data );
				if ( ! $bumpSequence ) {
					return $r;
				}
				$res = Schema::table( 'reservations' );
				self::guard( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET sequence = sequence + 1 WHERE id = %d', $res, $id ) ) );
				return self::lockById( $id );
			},
			static fn(): array => array()
		);
	}

	/**
	 * Müşteriye bildirim tercihi: alma anında olaya yazılan `notify` (siparişsiz elle eklenende "e-posta gönderme" seçilmiş
	 * olabilir). Kayıt yoksa (eski satır) true.
	 *
	 * @param int $id Rezervasyon.
	 */
	public static function customerNotify( int $id ): bool {
		if ( self::isAnonymised( $id ) ) {
			return false; // Kişisel verisi silinmiş randevuya müşteri e-postası (hatırlatma, saat değişimi, iptal) gitmez.
		}
		foreach ( EventLog::for( $id ) as $e ) {
			if ( 'status' === $e['type'] && '' === ( $e['data']['from'] ?? null ) ) {
				return (bool) ( $e['data']['notify'] ?? true );
			}
		}
		return true;
	}

	/**
	 * Kişisel verisi silinmiş mi (`Privacy\Anonymiser` işaret olayı yazdı).
	 *
	 * @param int $id Rezervasyon.
	 */
	public static function isAnonymised( int $id ): bool {
		global $wpdb;
		$evt = Schema::table( 'events' );
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE reservation_id = %d AND type = %s LIMIT 1', $evt, $id, self::ANONYMISED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- eklentinin kendi tablosu (olaylar); anonimleştirme işareti aynı istekte yazılıp okunur, önbellek eskiyi gösterir.
	}

	/**
	 * Tek rezervasyon.
	 *
	 * @param int $id Kimlik.
	 */
	public static function find( int $id ): ?Reservation {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $res, $id ), ARRAY_A );
		return is_array( $row ) ? Reservation::fromRow( $row ) : null;
	}

	/**
	 * Müşteri bağlantısının 32 hex belirteciyle tek rezervasyon (biçim geçersizse sorgu yapılmaz).
	 *
	 * @param string $token Belirteç.
	 */
	public static function findByToken( string $token ): ?Reservation {
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return null;
		}
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', $res, $token ), ARRAY_A );
		return is_array( $row ) ? Reservation::fromRow( $row ) : null;
	}

	/**
	 * Siparişin rezervasyonları (başlangıca göre artan).
	 *
	 * @param int  $orderId    Sipariş.
	 * @param bool $activeOnly Yalnız held|confirmed.
	 * @return list<Reservation>
	 */
	public static function forOrder( int $orderId, bool $activeOnly = false ): array {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		if ( $activeOnly ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d AND FIND_IN_SET(status, %s) ORDER BY starts_at ASC, id ASC', $res, $orderId, implode( ',', self::ACTIVE ) ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY starts_at ASC, id ASC', $res, $orderId ), ARRAY_A );
		}
		return self::hydrate( $rows );
	}

	/**
	 * Siparişin süresi henüz geçmemiş tutulan (held) satırlarının süresini yeniler (süresi geçmiş tutma canlandırılmaz).
	 * Sipariş kilidi + satır kilitleri altında (yeniden kullanım/alma kararlarıyla serileşir, spec §15.1). Olay üretmez.
	 *
	 * @param int                $orderId   Sipariş.
	 * @param \DateTimeImmutable $expiresAt Yeni bitiş.
	 * @return int Yenilenen (kilit altında uygun bulunan) satır sayısı.
	 * @throws LockBusyException Sipariş kilidi alınamadı.
	 * @throws \RuntimeException Veritabanı hatası.
	 */
	public static function refreshHold( int $orderId, \DateTimeImmutable $expiresAt ): int {
		return self::transact(
			static function () use ( $orderId, $expiresAt ): int {
				$now  = Clock::now();
				$live = array_values( array_filter( self::lockOrder( $orderId, array( 'held' ), true ), static fn( Reservation $r ): bool => null === $r->holdExpiresAt || $r->holdExpiresAt > $now ) );
				self::writeHold( $live, $expiresAt );
				return count( $live );
			},
			static fn(): array => array(),
			array( self::orderLock( $orderId ) )
		);
	}

	/**
	 * Yönetim listesi. Sekmeler yerel güne göre (wp_timezone):
	 * today = bugün başlayan, upcoming = şimdiden itibaren, past = şimdiden önce (üçü de held|confirmed|completed|no_show|conflict);
	 * cancelled = cancelled|expired. `superseded` hiçbir sekmede görünmez.
	 *
	 * @param array<string,mixed> $f tab, page (1+), per_page (1–100, vars. 20), orderby (yalnız starts_at), order (asc|desc).
	 * @return array{items:list<Reservation>,total:int}
	 */
	public static function query( array $f ): array {
		global $wpdb;
		$res = Schema::table( 'reservations' );
		$tab = in_array( $f['tab'] ?? '', array( 'today', 'upcoming', 'past', 'cancelled' ), true ) ? (string) $f['tab'] : 'upcoming';

		$utc   = new \DateTimeZone( 'UTC' );
		$today = Clock::now()->setTimezone( wp_timezone() )->setTime( 0, 0 );
		$start = $today->setTimezone( $utc )->format( self::FMT );
		$end   = $today->modify( '+1 day' )->setTimezone( $utc )->format( self::FMT );
		$now   = Clock::now()->setTimezone( $utc )->format( self::FMT );
		$min   = '1000-01-01 00:00:00';
		$max   = '9999-12-31 23:59:59';

		// [durumlar, starts_at >=, starts_at <, varsayılan yön].
		$listed                         = 'held,confirmed,completed,no_show,conflict';
		$tabs                           = array(
			'today'     => array( $listed, $start, $end, 'asc' ),
			'upcoming'  => array( $listed, $now, $max, 'asc' ),
			'past'      => array( $listed, $min, $now, 'desc' ),
			'cancelled' => array( 'cancelled,expired', $min, $max, 'desc' ),
		);
		[ $statuses, $from, $to, $dir ] = $tabs[ $tab ];
		if ( isset( $f['order'] ) && in_array( strtolower( (string) $f['order'] ), array( 'asc', 'desc' ), true ) ) {
			$dir = strtolower( (string) $f['order'] );
		}
		$per_page = max( 1, min( 100, (int) ( $f['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $f['page'] ?? 1 ) ) - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE FIND_IN_SET(status, %s) AND starts_at >= %s AND starts_at < %s', $res, $statuses, $from, $to ) );
		if ( 'desc' === $dir ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE FIND_IN_SET(status, %s) AND starts_at >= %s AND starts_at < %s ORDER BY starts_at DESC, id DESC LIMIT %d OFFSET %d', $res, $statuses, $from, $to, $per_page, $offset ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE FIND_IN_SET(status, %s) AND starts_at >= %s AND starts_at < %s ORDER BY starts_at ASC, id ASC LIMIT %d OFFSET %d', $res, $statuses, $from, $to, $per_page, $offset ), ARRAY_A );
		}
		return array(
			'items' => self::hydrate( $rows ),
			'total' => $total,
		);
	}

	/**
	 * Yönetim istatistik kartları (spec §8). Günler yerel (wp_timezone); "bu hafta" = Pazartesi 00:00 – sonraki Pazartesi.
	 * today/week = held|confirmed|completed|no_show (`query()` sekmeleri `conflict`i de listeler; saati olmadığı için sayılmaz);
	 * awaiting_payment = tutulan havale (held + awaiting_transfer); on_site = bugünden itibaren onaylı + işletmede ödenecek.
	 *
	 * @return array{today:int,week:int,awaiting_payment:int,on_site:int}
	 */
	public static function stats(): array {
		global $wpdb;
		$res    = Schema::table( 'reservations' );
		$utc    = new \DateTimeZone( 'UTC' );
		$today  = Clock::now()->setTimezone( wp_timezone() )->setTime( 0, 0 );
		$monday = $today->modify( '-' . ( (int) $today->format( 'N' ) - 1 ) . ' days' );
		$listed = 'held,confirmed,completed,no_show'; // `conflict` listede görünür ama saati alınmadığı için sayılmaz (F3).
		return array(
			'today'            => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE FIND_IN_SET(status, %s) AND starts_at >= %s AND starts_at < %s', $res, $listed, $today->setTimezone( $utc )->format( self::FMT ), $today->modify( '+1 day' )->setTimezone( $utc )->format( self::FMT ) ) ),
			'week'             => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE FIND_IN_SET(status, %s) AND starts_at >= %s AND starts_at < %s', $res, $listed, $monday->setTimezone( $utc )->format( self::FMT ), $monday->modify( '+7 days' )->setTimezone( $utc )->format( self::FMT ) ) ),
			'awaiting_payment' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND payment_state = %s', $res, 'held', 'awaiting_transfer' ) ),
			'on_site'          => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = 'confirmed' AND payment_state = 'on_site' AND starts_at >= %s", $res, $today->setTimezone( $utc )->format( self::FMT ) ) ),
		);
	}

	/**
	 * Dışa aktarma satırları: `superseded` dışındaki tüm satırlar, başlangıca göre artan. Tarihler yerel ve uçlar dahil.
	 *
	 * @param string|null $fromLocal Y-m-d (null = sınırsız).
	 * @param string|null $toLocal   Y-m-d (null = sınırsız).
	 * @param int         $limit     En çok satır.
	 * @return list<Reservation>
	 */
	public static function exportRows( ?string $fromLocal, ?string $toLocal, int $limit = 10000 ): array {
		global $wpdb;
		$res  = Schema::table( 'reservations' );
		$tz   = wp_timezone();
		$utc  = new \DateTimeZone( 'UTC' );
		$from = '1000-01-01 00:00:00';
		$to   = '9999-12-31 23:59:59';
		if ( null !== $fromLocal ) {
			$d    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fromLocal, $tz );
			$from = $d ? $d->setTimezone( $utc )->format( self::FMT ) : $from;
		}
		if ( null !== $toLocal ) {
			$d  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $toLocal, $tz );
			$to = $d ? $d->modify( '+1 day' )->setTimezone( $utc )->format( self::FMT ) : $to;
		}
		return self::hydrate( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status <> 'superseded' AND starts_at >= %s AND starts_at < %s ORDER BY starts_at ASC, id ASC LIMIT %d", $res, $from, $to, max( 1, $limit ) ), ARRAY_A ) );
	}

	/**
	 * Şimdiden sonra başlayan etkin satırların bir sayfası (çalışma saati değişikliği uyarısı), (starts_at, id) artan.
	 * Anahtar kümesi sayfalaması: sonraki sayfa için son satırın başlangıcı ve kimliği verilir; tavan yoktur, çağıran
	 * boş sayfaya kadar ilerler. Süresi dolmuş ama henüz bırakılmamış `held` satırlar dahil değildir (zamanlayıcı bırakır).
	 *
	 * @param int                     $limit      Sayfa boyu.
	 * @param \DateTimeImmutable|null $afterStart Önceki sayfanın son başlangıcı (null = ilk sayfa).
	 * @param int                     $afterId    Önceki sayfanın son kimliği.
	 * @return list<Reservation>
	 */
	public static function upcomingActive( int $limit = 500, ?\DateTimeImmutable $afterStart = null, int $afterId = 0 ): array {
		global $wpdb;
		$res    = Schema::table( 'reservations' );
		$now    = Clock::now()->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::FMT );
		$active = implode( ',', self::ACTIVE );
		if ( null === $afterStart ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE FIND_IN_SET(status, %s) AND starts_at >= %s AND NOT ( status = 'held' AND hold_expires_at IS NOT NULL AND hold_expires_at <= %s ) ORDER BY starts_at ASC, id ASC LIMIT %d", $res, $active, $now, $now, max( 1, $limit ) ), ARRAY_A );
		} else {
			$after = $afterStart->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::FMT );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE FIND_IN_SET(status, %s) AND starts_at >= %s AND NOT ( status = 'held' AND hold_expires_at IS NOT NULL AND hold_expires_at <= %s ) AND ( starts_at > %s OR ( starts_at = %s AND id > %d ) ) ORDER BY starts_at ASC, id ASC LIMIT %d", $res, $active, $now, $now, $after, $after, $afterId, max( 1, $limit ) ), ARRAY_A );
		}
		return self::hydrate( $rows );
	}

	// ----------------------------------------------------------------- iç işleyiş

	/**
	 * İşlem çerçevesi: adlı kilitler (verilen sırayla) → START TRANSACTION → iş → COMMIT; çakışmada ROLLBACK. 1213/1205'te
	 * TÜM iş (supersede/kaldırma dahil) bir kez baştan koşar; kilitler denemeler boyunca tutulur ve `finally`de ters
	 * sırayla bırakılır. Her ROLLBACK'in sonucu denetlenir: başarısızsa yeniden deneme yapılmaz, bağlantı atılır ve
	 * LockBusyException (rollback()). Hata gösterimi işlem süresince bastırılır ve önceki hâline döndürülür. Olaylar yalnız COMMIT
	 * başarılıysa, kilitler ve bastırma geri alındıktan sonra tetiklenir.
	 *
	 * @template T
	 * @param callable():T                              $work  İşlem içi iş.
	 * @param callable(string):list<\DateTimeImmutable> $taken Çakışan birimden dolu dilim listesini üretir (ROLLBACK sonrası).
	 * @param list<string>                              $locks Adlı kilitler, alınma sırasıyla.
	 * @return T
	 * @throws SlotTakenException Birim çakışması (1062).
	 * @throws LockBusyException Adlı kilit alınamadı/okunamadı, iki kez 1213/1205 ya da ROLLBACK başarısız.
	 * @throws \LogicException İç içe işlem.
	 * @throws \RuntimeException İşlem açılamadı, bağlantı değişti ya da COMMIT başarısız.
	 * @throws \Throwable İş içinden gelen diğer hatalar (ROLLBACK sonrası yeniden atılır).
	 */
	private static function transact( callable $work, callable $taken, array $locks = array() ) { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		global $wpdb;
		if ( self::$in_tx ) {
			throw new \LogicException( 'slotiva_nested_transaction' );
		}
		$suppress    = $wpdb->suppress_errors( true );
		self::$in_tx = true;
		$acquired    = array();
		try {
			// Kilitler bağlantıya özeldir: bağlantı değişirse (wpdb 2006 sonrası sessiz yeniden bağlanma) hem işlem hem
			// kilitler kaybolmuştur → COMMIT öncesi denetlenir.
			$conn = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			if ( '' === $conn ) {
				throw new \RuntimeException( 'slotiva_connection_lost' );
			}
			foreach ( $locks as $name ) {
				self::acquire( $name );
				$acquired[] = $name;
			}
			for ( $attempt = 1; ; $attempt++ ) {
				self::$pending = array();
				// İşlem açılamadıysa hiçbir yazma yapılmaz (aksi hâlde her ifade tek tek COMMIT'lenirdi).
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					throw new \RuntimeException( 'slotiva_transaction_start_failed' );
				}
				try {
					$result = $work();
					if ( (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) !== $conn ) {
						throw new \RuntimeException( 'slotiva_connection_lost' );
					}
					if ( false === $wpdb->query( 'COMMIT' ) ) {
						throw new \RuntimeException( 'slotiva_commit_failed' );
					}
					break;
				} catch ( UnitConflict $c ) {
					// Sonucu denetlenir: başarısızsa yeniden deneme YOK (LockBusyException), bkz. rollback().
					self::rollback( $c );
					if ( 'retry' === self::errorKind( $c->errno, true ) ) {
						if ( $attempt < 2 ) {
							continue;
						}
						// Kilitlenme/kilit bekleme iki kez: karar verilemedi (409), "saat dolu" DEĞİL.
						throw new LockBusyException( self::ER_DEADLOCK === $c->errno ? 'innodb_deadlock' : 'innodb_lock_wait' );
					}
					$slots = $taken( $c->unit );
					if ( ! $slots ) {
						throw new \RuntimeException( 'slotiva_unexpected_conflict' );
					}
					throw new SlotTakenException( $slots );
				} catch ( \Throwable $t ) {
					self::rollback( $t );
					throw $t;
				}
			}
		} finally {
			foreach ( array_reverse( $acquired ) as $name ) {
				$wpdb->query( $wpdb->prepare( 'DO RELEASE_LOCK(%s)', $name ) );
			}
			self::$in_tx = false;
			$wpdb->suppress_errors( $suppress );
		}

		foreach ( self::takePending() as [ $hook, $args ] ) {
			do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- kanca adları yukarıdaki sabit 'slotiva_reservation_*' dizgileri.
		}
		return $result;
	}

	/**
	 * Adlı kilidi alır. 1 = alındı; 0 (zaman aşımı), NULL ya da hata (ör. 3058 kullanıcı kilidi kilitlenmesi) = karar
	 * verilemedi → LockBusyException. Asla "boş" sayılmaz.
	 *
	 * @param string $name Kilit adı (≤ 64 karakter).
	 * @throws LockBusyException Alınamadı.
	 */
	private static function acquire( string $name ): void {
		global $wpdb;
		/**
		 * Adlı kilit bekleme süresi (saniye, 0–60). Varsayılan 10 (spec §15.1).
		 *
		 * @param int $seconds Saniye.
		 */
		$timeout = max( 0, min( 60, (int) apply_filters( 'slotiva_lock_timeout', self::LOCK_TIMEOUT ) ) );
		$got     = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );
		if ( '' !== $wpdb->last_error || '1' !== (string) $got ) {
			throw new LockBusyException( esc_html( $name ) );
		}
	}

	/**
	 * Alma/değiştirme kilitleri, sabit sırayla: oturum → IP → sipariş.
	 *
	 * @param array<string,mixed> $req Normalleştirilmiş istek.
	 * @return list<string>
	 */
	private static function claimLocks( array $req ): array {
		$locks = array();
		if ( null !== $req['session_hash'] ) {
			$locks[] = self::lockName( 'slotiva_sess_' . $req['session_hash'] );
		}
		if ( null !== $req['ip_hash'] ) {
			$locks[] = self::lockName( 'slotiva_ip_' . $req['ip_hash'] );
		}
		if ( null !== $req['order_id'] ) {
			$locks[] = self::orderLock( (int) $req['order_id'] );
		}
		// K1: kaynak kilidi HER ZAMAN son (oturum → IP → sipariş → kaynak); `insertClaim` aynı kaynağa yazar (R14).
		$locks[] = self::resourceLock( Units::RESOURCE_ID );
		return $locks;
	}

	/**
	 * Kaynak kilidinin adı (K1): birim yazan her yol, işlemden ÖNCE ve diğer kilitlerden SONRA alır; böylece farklı oturumlar
	 * aynı dilimde yarışamaz (çok koltuklu kapasite bunun üstüne kurulur).
	 *
	 * @param int $resourceId Kaynak.
	 */
	private static function resourceLock( int $resourceId ): string {
		return self::lockName( 'slotiva_res_' . $resourceId );
	}

	/**
	 * Sipariş kilidinin adı.
	 *
	 * @param int $orderId Sipariş.
	 */
	private static function orderLock( int $orderId ): string {
		return self::lockName( 'slotiva_order_' . $orderId );
	}

	/**
	 * Adlı kilit ad alanı MySQL SUNUCUSU genelidir: aynı sunucudaki başka sitelerle çakışmasın diye tablo önekiyle
	 * başlar. 64 karakteri aşarsa (uzun önek) önekli ad özetlenir.
	 *
	 * @param string $base `slotiva_order_42` gibi.
	 */
	private static function lockName( string $base ): string {
		global $wpdb;
		$name = $wpdb->prefix . $base;
		return strlen( $name ) <= 64 ? $name : 'slotiva_' . md5( $name );
	}

	/**
	 * İşlem içinde, oturum kilidi altında: oturumun diğer siparişlerini supersede eder, sonra kotayı denetler.
	 * Kota supersede'den SONRA sayılır: aynı oturumun yerini alan taslakları sayılmaz. Kota YALNIZ müşteri ödeme alımları
	 * içindir (`held` + sipariş var); siparişsiz (yönetici elle) ve `confirmed` alımlar muaftır.
	 *
	 * @param array<string,mixed> $req    Normalleştirilmiş istek.
	 * @param int                 $except Mevcut sipariş (0 = yok).
	 * @throws HoldQuotaException Kota aşıldı (işlem geri alınır).
	 */
	private static function sessionPhase( array $req, int $except ): void {
		global $wpdb;
		if ( null !== $req['session_hash'] ) {
			self::supersedeSessionRows( $req['session_hash'], $except );
		}
		if ( 'held' !== $req['status'] || null === $req['order_id'] ) {
			return;
		}
		$res = Schema::table( 'reservations' );
		$now = Clock::now()->format( self::FMT );
		foreach ( array(
			'session' => 'session_hash',
			'ip'      => 'ip_hash',
		) as $scope => $col ) {
			if ( null === $req[ $col ] ) {
				continue;
			}
			// Düz okuma (boşluk kilidi yok): oturum/IP adlı kilidi altındayız, anlık görüntü kilitten sonra başladı.
			// Süresi geçmiş tutma (süpürücü geç kalmış) sayılmaz.
			$n = (int) self::scalar(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT order_id) FROM %i WHERE %i = %s AND status = 'held' AND order_id <> %d AND (hold_expires_at IS NULL OR hold_expires_at > %s)",
					$res,
					$col,
					$req[ $col ],
					$except,
					$now
				)
			);
			if ( $n >= self::HOLD_QUOTA ) {
				throw new HoldQuotaException( esc_html( $scope ), (int) $n );
			}
		}
	}

	/**
	 * İşlem içinde: oturumun `$except` dışındaki siparişlerindeki kart taslağı (held|expired + pending) satırları kilitler ve
	 * supersede eder.
	 *
	 * @param string $hash   Oturum özeti.
	 * @param int    $except Korunacak sipariş.
	 * @return int Satır sayısı.
	 */
	private static function supersedeSessionRows( string $hash, int $except ): int {
		global $wpdb;
		$res    = Schema::table( 'reservations' );
		$ids    = self::ids(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE session_hash = %s AND FIND_IN_SET(status, %s) AND payment_state = 'pending' AND (order_id IS NULL OR order_id <> %d) ORDER BY id ASC",
				$res,
				$hash,
				implode( ',', self::SESSION_SUPERSEDABLE ),
				$except
			)
		);
		$locked = self::lockIds( $ids, static fn( Reservation $r ): bool => in_array( $r->status, self::SESSION_SUPERSEDABLE, true ) && 'pending' === $r->paymentState );
		foreach ( $locked as $r ) {
			self::deleteUnits( $r->id );
			self::writeStatus(
				$r,
				'superseded',
				null,
				self::ctx(
					array(
						'notify' => false,
						'reason' => 'session',
					),
					$r->orderId
				),
				'replace'
			);
		}
		return count( $locked );
	}

	/**
	 * İşlem DIŞINDA (yalnız çakışma iletisi için): supersede edilecek oturum satırları.
	 *
	 * @param array<string,mixed> $req    Normalleştirilmiş istek.
	 * @param int                 $except Korunacak sipariş.
	 * @return list<int>
	 */
	private static function sessionRowIds( array $req, int $except ): array {
		global $wpdb;
		if ( null === $req['session_hash'] ) {
			return array();
		}
		$res = Schema::table( 'reservations' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE session_hash = %s AND FIND_IN_SET(status, %s) AND payment_state = 'pending' AND (order_id IS NULL OR order_id <> %d)", $res, $req['session_hash'], implode( ',', self::SESSION_SUPERSEDABLE ), $except ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Kilitli etkin satırlar istekle birebir aynı mı (yeniden kullanım şartı): aynı sayı, ürün/varyasyon, dilim, durum ve
	 * ödeme durumu; hedef `held` ise her satırın süresi geçmemiş olmalı (geçmişse takım yeniden alınır).
	 *
	 * @param list<Reservation>   $active Kilitli etkin satırlar (başlangıca göre artan).
	 * @param array<string,mixed> $req    Normalleştirilmiş istek.
	 */
	private static function reusable( array $active, array $req ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		if ( ! $active || count( $active ) !== count( $req['slots'] ) ) {
			return false;
		}
		$now = Clock::now();
		foreach ( $active as $i => $r ) {
			if ( $r->productId !== $req['product_id'] || $r->variationId !== $req['variation_id']
				|| $r->startsAt->getTimestamp() !== $req['slots'][ $i ]->getTimestamp()
				|| $r->status !== $req['status'] || $r->paymentState !== $req['payment_state'] ) {
				return false;
			}
			if ( 'held' === $r->status && ( null === $r->holdExpiresAt || $r->holdExpiresAt <= $now ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Siparişin son alma kuşağında iptal edilmiş satır var mı (kilitli satırlar üzerinden). Kuşak = aynı alma işleminde
	 * yazılan satırlar: `insertClaim` hepsine aynı `created_at`'i yazar; son kuşak en büyük `created_at`'li satırlardır.
	 * Eski kuşaktaki iptal (sonradan ana ödeme yolundan yeniden verilmiş sipariş) engel değildir.
	 *
	 * @param list<Reservation> $rows Siparişin kilitli satırları.
	 */
	private static function latestGenerationCancelled( array $rows ): bool { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$latest = null;
		foreach ( $rows as $r ) {
			$latest = null === $latest || $r->createdAt > $latest ? $r->createdAt : $latest;
		}
		foreach ( $rows as $r ) {
			if ( 'cancelled' === $r->status && $r->createdAt == $latest ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- DateTimeImmutable değer karşılaştırması.
				return true;
			}
		}
		return false;
	}

	/**
	 * İşlem içinde: yeniden kullanılan `held` takımın bitişini yeniler ve `slotiva_hold_refreshed`ı COMMIT sonrasına
	 * kuyruğa alır. Hedef `held` değilse ya da bitiş yoksa hiçbir şey yapmaz.
	 *
	 * @param int                     $orderId   Sipariş.
	 * @param list<Reservation>       $active    Kilitli satırlar.
	 * @param string                  $status    Hedef durum.
	 * @param \DateTimeImmutable|null $expiresAt Yeni bitiş.
	 */
	private static function refreshLocked( int $orderId, array $active, string $status, ?\DateTimeImmutable $expiresAt ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		if ( 'held' !== $status || null === $expiresAt ) {
			return;
		}
		self::writeHold( $active, $expiresAt );
		/**
		 * Aynı siparişin tutması yenilendi (COMMIT sonrası; süre dolumu işleri yeniden kurulur, Task 10).
		 *
		 * @param int                $orderId   Sipariş.
		 * @param \DateTimeImmutable $expiresAt Yeni bitiş (UTC).
		 * @param list<int>          $ids       Rezervasyonlar.
		 */
		self::$pending[] = array( 'slotiva_hold_refreshed', array( $orderId, $expiresAt, array_map( static fn( Reservation $r ): int => $r->id, $active ) ) );
	}

	/**
	 * İşlem içinde kilitli satırların tutma bitişini yazar.
	 *
	 * @param list<Reservation>  $rows      Kilitli satırlar.
	 * @param \DateTimeImmutable $expiresAt Yeni bitiş.
	 */
	private static function writeHold( array $rows, \DateTimeImmutable $expiresAt ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		global $wpdb;
		$until = $expiresAt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::FMT );
		$now   = Clock::now()->format( self::FMT );
		foreach ( $rows as $r ) {
			self::guard(
				$wpdb->update(
					Schema::table( 'reservations' ),
					array(
						'hold_expires_at' => $until,
						'updated_at'      => $now,
					),
					array( 'id' => $r->id ),
					array( '%s', '%s' ),
					array( '%d' )
				)
			);
		}
	}

	/**
	 * ROLLBACK ve sonucunun denetimi (spec §15.5). Başarısızsa işlemin durumu BİLİNMİYOR: bağlantıda yarım bir deneme
	 * açık kalmış olabilir ve sonraki herhangi bir START TRANSACTION onu örtük COMMIT eder. Bu yüzden yeniden denenmez;
	 * bağlantı atılıp yeniden kurulur (sunucu açık işlemi geri alır, adlı kilitler de bırakılır), olay günlüğe yazılır
	 * ve LockBusyException (409, "karar verilemedi") atılır.
	 *
	 * @param \Throwable $cause ROLLBACK'e yol açan hata (günlük için).
	 * @throws LockBusyException ROLLBACK başarısız.
	 */
	private static function rollback( \Throwable $cause ): void {
		global $wpdb;
		self::$pending = array();
		if ( false !== $wpdb->query( 'ROLLBACK' ) ) {
			return;
		}
		$error = (string) $wpdb->last_error;
		$wpdb->close();
		$reconnected = $wpdb->db_connect( false );
		$message     = sprintf( 'slotiva rollback failed after %s: %s; connection discarded (reconnect %s)', $cause->getMessage(), $error, $reconnected ? 'ok' : 'failed' );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'mhm-slotiva' ) );
		} else {
			error_log( '[mhm-slotiva] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WC günlüğü yoksa sunucu günlüğüne.
		}
		throw new LockBusyException( 'slotiva_rollback_failed' );
	}

	/**
	 * Çağıranın beklentisi kilitli (yeniden okunmuş) satırla tutuyor mu.
	 *
	 * @param Reservation         $r   Kilitli satır.
	 * @param array<string,mixed> $ctx expect_status, expect_sequence, expect_hold_expires_at.
	 */
	private static function expected( Reservation $r, array $ctx ): bool {
		if ( isset( $ctx['expect_status'] ) && ! in_array( $r->status, (array) $ctx['expect_status'], true ) ) {
			return false;
		}
		if ( isset( $ctx['expect_sequence'] ) && (int) $ctx['expect_sequence'] !== $r->sequence ) {
			return false;
		}
		if ( array_key_exists( 'expect_hold_expires_at', $ctx ) ) {
			$want = $ctx['expect_hold_expires_at'];
			$want = $want instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface( $want )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::FMT ) : null;
			if ( $want !== $r->holdExpiresAt?->format( self::FMT ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Kuyruktaki olayları alır ve kuyruğu boşaltır.
	 *
	 * @return list<array{0:string,1:list<mixed>}>
	 */
	private static function takePending(): array {
		$events        = self::$pending;
		self::$pending = array();
		return $events;
	}

	/**
	 * İşlem içinde, kaynak kilidi altında: her dilimin kabulü (SeatPolicy) — koltuk ve damgalanacak kapasite. Ürün kapasitesi
	 * bu yazmada BİR kez okunur (D4). Reddedilen dilimler toplanır; biri bile varsa hiçbir şey yazılmadan SlotTakenException.
	 *
	 * @param array<string,mixed> $req         Normalleştirilmiş istek.
	 * @param int|null            $sameOrderId Aynı işlemde satırları yerine yenisi yazılan sipariş (K5; null = yeni alma).
	 * @param int|null            $carry       Yeniden eklemede taşınan kapasite (K4; null = yeni katılım, etkin kapasite damgalanır).
	 * @return list<array{seat:int,capacity:int}> Dilim sırasıyla.
	 * @throws SlotTakenException Bir ya da daha çok dilim dolu.
	 */
	private static function planClaim( array $req, ?int $sameOrderId, ?int $carry ): array {
		$current = Capacity::forProduct( $req['product_id'] );
		$plan    = array();
		$refused = array();
		foreach ( $req['slots'] as $slot ) {
			try {
				$p = SeatPolicy::place( Units::RESOURCE_ID, $req['product_id'], $slot, Units::span( $slot, $req['duration'], $req['buffer'] ), $carry ?? $current, null, $sameOrderId, $current );
			} catch ( SlotTakenException $e ) {
				$refused = array_merge( $refused, $e->taken );
				continue;
			}
			$plan[] = array(
				'seat'     => $p['seat'],
				'capacity' => null !== $carry && ! $p['exempt'] ? $carry : $p['stamp'],
			);
		}
		if ( $refused ) {
			// İleti sabit kod (`slotiva_slot_taken`); taşınan veri DateTimeImmutable listesidir, metin değil.
			$taken = new SlotTakenException( $refused );
			throw $taken;
		}
		return $plan;
	}

	/**
	 * Yeniden eklemede taşınan kapasite (K4 "asla yeniden damgalanmaz"): yerine yenisi yazılan satırların en küçüğü; satır
	 * yoksa null (yeni alma).
	 *
	 * @param list<Reservation> $rows Değiştirilen satırlar.
	 */
	private static function carried( array $rows ): ?int { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		return $rows ? min( array_map( static fn( Reservation $r ): int => $r->capacity, $rows ) ) : null;
	}

	/**
	 * İşlem içinde: verilen birimlerde kalan süresi geçmiş `held` tutmaları bırakır (hangi koltukta olursa olsun, K2).
	 * Seçilen koltuktaki tutma 1062 yolunda zaten bırakılmıştır; burada çok koltuklu birimin diğer koltukları kalır.
	 *
	 * @param list<\DateTimeImmutable> $units  Birimler.
	 * @param list<int>                $except Bırakılmayacak rezervasyonlar (yazan taraf).
	 */
	private static function sweepStale( array $units, array $except ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$keys  = array_values( array_unique( array_map( static fn( \DateTimeImmutable $u ): string => $u->format( self::FMT ), $units ) ) );
		$now   = Clock::now()->getTimestamp();
		$stale = array();
		foreach ( Units::occupants( $keys ) as $row ) {
			if ( 'held' === $row['status'] && null !== $row['hold_expires_at'] && $row['hold_expires_at'] < $now && ! in_array( $row['reservation_id'], $except, true ) ) {
				$stale[ $row['reservation_id'] ] = true;
			}
		}
		$ids = array_keys( $stale );
		sort( $ids );
		foreach ( $ids as $owner ) {
			self::expireIfStale( $owner, 0 );
		}
	}

	/**
	 * İşlem içinde: rezervasyon satırlarını, sonra TÜM birimleri artan sırayla yazar.
	 *
	 * @param array<string,mixed>                     $req  Normalleştirilmiş istek.
	 * @param list<array{seat:int,capacity:int}>|null $plan Kabul planı (null = burada, yeni alma olarak).
	 * @return list<int>
	 */
	private static function insertClaim( array $req, ?array $plan = null ): array {
		global $wpdb;
		$plan     = $plan ?? self::planClaim( $req, null, null );
		$now      = Clock::now()->format( self::FMT );
		$res      = Schema::table( 'reservations' );
		$ids      = array();
		$units    = array(); // Birim + sahibi rezervasyon + koltuk.
		$all      = array();
		$customer = $req['customer'];
		foreach ( $req['slots'] as $i => $slot ) {
			self::guard(
				$wpdb->insert(
					$res,
					array(
						'resource_id'      => Units::RESOURCE_ID,
						'product_id'       => $req['product_id'],
						'variation_id'     => $req['variation_id'],
						'order_id'         => $req['order_id'],
						'order_item_id'    => $req['order_item_id'],
						'starts_at'        => $slot->format( self::FMT ),
						'ends_at'          => $slot->modify( '+' . $req['duration'] . ' minutes' )->format( self::FMT ),
						'status'           => $req['status'],
						'payment_state'    => $req['payment_state'],
						'hold_expires_at'  => $req['hold_expires_at'] ? $req['hold_expires_at']->format( self::FMT ) : null,
						'customer_name'    => $customer['name'],
						'customer_email'   => $customer['email'],
						'customer_phone'   => $customer['phone'],
						'location_text'    => $req['location'],
						'reschedule_count' => 0,
						'token'            => bin2hex( random_bytes( 16 ) ),
						'sequence'         => 0,
						'created_at'       => $now,
						'updated_at'       => $now,
						'product_name'     => $req['product_name'],
						'session_hash'     => $req['session_hash'],
						'ip_hash'          => $req['ip_hash'],
						'capacity'         => $plan[ $i ]['capacity'],
					),
					array( '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
				)
			);
			$id    = (int) $wpdb->insert_id;
			$ids[] = $id;
			foreach ( Units::span( $slot, $req['duration'], $req['buffer'] ) as $u ) {
				$units[] = array( $u, $id, $plan[ $i ]['seat'] );
				$all[]   = $u;
			}
			self::logEvent(
				$id,
				'status',
				array(
					'from'   => '',
					'to'     => $req['status'],
					'notify' => $req['notify'], // Müşteri bildirimi tercihi kalıcı (Task 11 I2): hatırlatma/saat değişimi/iptal buna uyar.
				)
			);
			self::$pending[] = array( 'slotiva_reservation_status', array( $id, '', $req['status'], self::ctx( array( 'notify' => $req['notify'] ), $req['order_id'], 'claim' ) ) );
			self::queueSaved( $id, 'claim' );
		}

		// Kilitlenme önlemi: birimler dilimden bağımsız, küresel artan sırayla.
		usort( $units, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		foreach ( $units as [ $u, $id, $seat ] ) {
			self::insertUnits( Units::RESOURCE_ID, $id, array( $u ), $seat );
		}
		self::sweepStale( $all, $ids );
		return $ids;
	}

	/**
	 * Birimleri artan sırayla, kabulün (SeatPolicy) seçtiği koltukta ekler. 1062 artık "o koltuk dolu" demektir (K2): sahibi
	 * süresi geçmiş bir `held` tutma ise (süpürücü geç kalmış) o tutma aynı işlemde `expired` yapılır ve ekleme BİR kez
	 * yinelenir (tembel süre dolumu); aksi hâlde UnitConflict.
	 *
	 * @param int                      $resourceId Kaynak.
	 * @param int                      $id         Rezervasyon.
	 * @param list<\DateTimeImmutable> $units      Birimler (artan).
	 * @param int                      $seat       Koltuk (kabulün seçtiği; kapasite 1'de hep 1).
	 * @throws UnitConflict Çakışma ya da kilitlenme.
	 */
	private static function insertUnits( int $resourceId, int $id, array $units, int $seat = 1 ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		foreach ( $units as $u ) {
			$unit = $u->format( self::FMT );
			$ok   = self::insertUnit( $resourceId, $id, $unit, $seat );
			if ( false === $ok && self::ER_DUP === self::errno() ) {
				if ( ! self::lazyExpire( $resourceId, $unit, $seat, $id ) ) {
					throw new UnitConflict( (int) self::ER_DUP, esc_html( $unit ) );
				}
				$ok = self::insertUnit( $resourceId, $id, $unit, $seat );
			}
			self::guard( $ok, $unit );
		}
	}

	/**
	 * Tek birim ekler.
	 *
	 * @param int    $resourceId Kaynak.
	 * @param int    $id         Rezervasyon.
	 * @param string $unit       Birim (Y-m-d H:i:s).
	 * @param int    $seat       Koltuk.
	 * @return int|false
	 */
	private static function insertUnit( int $resourceId, int $id, string $unit, int $seat ) {
		global $wpdb;
		return $wpdb->insert(
			Schema::table( 'occupancy' ),
			array(
				'resource_id'    => $resourceId,
				'unit_start'     => $unit,
				'seat'           => $seat,
				'reservation_id' => $id,
			),
			array( '%d', '%s', '%d', '%d' )
		);
	}

	/**
	 * Tembel süre dolumu: çakışan (birim, koltuk) sahibini kilitler; süresi geçmiş `held` ise birimlerini siler ve `expired`
	 * yazar (olay reason=lazy_expired, COMMIT sonrası). Koltuk hangisi olursa olsun (K2).
	 *
	 * @param int    $resourceId Kaynak.
	 * @param string $unit       Çakışan birim.
	 * @param int    $seat       Çakışan koltuk.
	 * @param int    $claimant   Birimi isteyen rezervasyon (kendisi bırakılmaz).
	 * @return bool Birim boşaltıldı mı (yeniden eklenebilir).
	 */
	private static function lazyExpire( int $resourceId, string $unit, int $seat, int $claimant ): bool {
		global $wpdb;
		$occ = Schema::table( 'occupancy' );
		// Sahip önce DÜZ okunur, sonra rezervasyon satırı kilitlenir: geçişlerle aynı sıra (reservations → occupancy).
		$sql   = $wpdb->prepare( 'SELECT reservation_id FROM %i WHERE resource_id = %d AND unit_start = %s AND seat = %d', $occ, $resourceId, $unit, $seat );
		$owner = self::scalar( $sql );
		if ( null === $owner ) {
			// Birim anlık görüntüden sonra COMMIT'lenmiş: var olan kaydı kilitli okumayla (kayıt kilidi) al.
			$owner = self::scalar( $sql . ' FOR UPDATE' );
		}
		if ( null === $owner ) {
			return true; // Bu arada boşaldı: bir kez daha dene.
		}
		return self::expireIfStale( (int) $owner, $claimant );
	}

	/**
	 * Rezervasyonu kilitler; süresi geçmiş `held` ise (ve isteyen kendisi değilse) birimlerini siler, `expired` yazar.
	 *
	 * @param int $owner    Rezervasyon.
	 * @param int $claimant İsteyen rezervasyon (0 = yok).
	 * @return bool Bırakıldı mı.
	 */
	private static function expireIfStale( int $owner, int $claimant ): bool {
		$r = self::lockById( $owner );
		if ( null === $r || $r->id === $claimant || 'held' !== $r->status || null === $r->holdExpiresAt || $r->holdExpiresAt >= Clock::now() ) {
			return false;
		}
		self::deleteUnits( $r->id );
		self::writeStatus( $r, 'expired', null, self::ctx( array( 'reason' => 'lazy_expired' ), $r->orderId ) );
		return true;
	}

	/**
	 * Son sorgunun MySQL hata kodu.
	 */
	private static function errno(): int {
		global $wpdb;
		return $wpdb->dbh instanceof \mysqli ? mysqli_errno( $wpdb->dbh ) : 0; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno -- wpdb hata kodu API'si sunmaz; 1062/1213/1205 ayrımı spec §6.3 gereği.
	}

	/**
	 * Rezervasyonun birimlerini siler.
	 *
	 * @param int $id Rezervasyon.
	 */
	private static function deleteUnits( int $id ): void {
		global $wpdb;
		self::guard( $wpdb->delete( Schema::table( 'occupancy' ), array( 'reservation_id' => $id ), array( '%d' ) ) );
	}

	/**
	 * İşlem içinde durum yazar, geçmişe ekler ve olayı kuyruğa alır (durum aynıysa olay yok).
	 *
	 * @param Reservation                                    $r            Kilitli satır.
	 * @param string                                         $status       Yeni durum.
	 * @param string|null                                    $paymentState Yeni ödeme durumu (null = değişmez).
	 * @param array{notify:bool,reason:string,order_id:?int} $ctx          Olay bağlamı.
	 * @param string|null                                    $saved        `slotiva_after_booking_saved` bağlamı (null = çağıran kendisi kuyruğa alır).
	 */
	private static function writeStatus( Reservation $r, string $status, ?string $paymentState, array $ctx, ?string $saved = 'status' ): void {
		global $wpdb;
		$data = array(
			'status'     => $status,
			'updated_at' => Clock::now()->format( self::FMT ),
		);
		if ( null !== $paymentState ) {
			$data['payment_state'] = $paymentState;
		}
		if ( 'held' !== $status ) {
			$data['hold_expires_at'] = null;
		}
		self::guard( $wpdb->update( Schema::table( 'reservations' ), $data, array( 'id' => $r->id ), null, array( '%d' ) ) );
		if ( $r->status === $status ) {
			self::queueSaved( $r->id, $saved );
			return;
		}
		if ( 'cancelled' === $status ) {
			// İptal `.ics`'in yeni sürümüdür (§15.11): bildirim olsun olmasın iptal işleminde artar (Codex final 4). İptal
			// e-postası ayrıca artırmaz; işaretini bu sürümle yazar.
			$res = Schema::table( 'reservations' );
			self::guard( $wpdb->query( $wpdb->prepare( 'UPDATE %i SET sequence = sequence + 1 WHERE id = %d', $res, $r->id ) ) );
		}
		self::logEvent(
			$r->id,
			'status',
			array(
				'from'   => $r->status,
				'to'     => $status,
				'reason' => $ctx['reason'],
			)
		);
		self::$pending[] = array( 'slotiva_reservation_status', array( $r->id, $r->status, $status, $ctx ) );
		self::queueSaved( $r->id, $saved );
	}

	/**
	 * `slotiva_after_booking_saved` olayını kuyruğa alır; COMMIT'ten sonra tetiklenir, geri alma kuyruğu boşaltır.
	 *
	 * @param int         $id      Rezervasyon.
	 * @param string|null $context Bağlam (claim|replace|reschedule|reclaim|status); null = kuyruğa alma.
	 */
	private static function queueSaved( int $id, ?string $context ): void {
		if ( null !== $context ) {
			self::$pending[] = array( 'slotiva_after_booking_saved', array( $id, $context ) );
		}
	}

	/**
	 * İşlem içinde tek satırı kilitleyerek okur (SELECT … FOR UPDATE).
	 *
	 * @param int $id Rezervasyon.
	 */
	private static function lockById( int $id ): ?Reservation {
		global $wpdb;
		$res  = Schema::table( 'reservations' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $res, $id ), ARRAY_A );
		return self::hydrateLocked( $rows )[0] ?? null;
	}

	/**
	 * İşlem içinde siparişin satırlarını kilitleyerek okur: durumu listede olan ($in) ya da olmayan (! $in).
	 *
	 * @param int          $orderId  Sipariş.
	 * @param list<string> $statuses Durumlar.
	 * @param bool         $in       true = listedekiler, false = listede olmayanlar.
	 * @return list<Reservation>
	 */
	private static function lockOrder( int $orderId, array $statuses, bool $in ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		global $wpdb;
		$res    = Schema::table( 'reservations' );
		$ids    = self::ids( $wpdb->prepare( 'SELECT id FROM %i WHERE order_id = %d ORDER BY id ASC', $res, $orderId ) );
		$locked = self::lockIds( $ids, static fn( Reservation $r ): bool => in_array( $r->status, $statuses, true ) === $in );
		usort( $locked, static fn( Reservation $a, Reservation $b ): int => array( $a->startsAt, $a->id ) <=> array( $b->startsAt, $b->id ) );
		return $locked;
	}

	/**
	 * İşlem içinde DÜZ okumayla kimlik listesi (boşluk kilidi almaz).
	 *
	 * @param string $sql Hazırlanmış `SELECT id …`.
	 * @return list<int>
	 */
	private static function ids( string $sql ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tek yürütücü yardımcı (hata korumalı): iki çağıranın ikisi de $wpdb->prepare() sonucunu verir, değer bağlanmamış sorgu buraya gelmez.
		if ( '' !== $wpdb->last_error ) {
			self::guard( false );
		}
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Var olduğu bilinen satırları birincil anahtarla, artan kimlik sırasıyla kilitler (kayıt kilidi) ve kilit altında
	 * yeniden okunan durumu `$still` ile süzer (bu arada değişen satır atlanır).
	 *
	 * @param list<int>                  $ids   Kimlikler.
	 * @param callable(Reservation):bool $still Kilitli satır hâlâ uygun mu.
	 * @return list<Reservation>
	 */
	private static function lockIds( array $ids, callable $still ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		sort( $ids );
		$out = array();
		foreach ( $ids as $id ) {
			$r = self::lockById( $id );
			if ( null !== $r && $still( $r ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Kilitli okuma sonucunu denetler (1213/1205 → yeniden deneme) ve nesneye çevirir.
	 *
	 * @param mixed $rows get_results sonucu.
	 * @return list<Reservation>
	 */
	private static function hydrateLocked( $rows ): array {
		global $wpdb;
		if ( '' !== $wpdb->last_error ) {
			self::guard( false );
		}
		return self::hydrate( $rows );
	}

	/**
	 * İşlem içinde tek değer okur; sorgu hatası işlemi düşürür (1213/1205 → yeniden deneme).
	 *
	 * @param string $sql Hazırlanmış sorgu.
	 */
	private static function scalar( string $sql ): ?string {
		global $wpdb;
		$v = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tek yürütücü yardımcı (hata korumalı): üç çağıranın üçü de $wpdb->prepare() sonucunu verir (birinin sonuna yalnız sabit ' FOR UPDATE' eklenir).
		if ( '' !== $wpdb->last_error ) {
			self::guard( false );
		}
		return null === $v ? null : (string) $v;
	}

	/**
	 * Yazma sonucunu denetler. false ise: 1213/1205 → UnitConflict; 1062 yalnız birim eklemesinde çakışmadır; diğerleri RuntimeException.
	 *
	 * @param int|bool    $ok   wpdb sonucu.
	 * @param string|null $unit Birim eklemesiyse birim (Y-m-d H:i:s).
	 * @throws UnitConflict Çakışma ya da kilitlenme.
	 * @throws \RuntimeException Diğer veritabanı hataları.
	 */
	private static function guard( $ok, ?string $unit = null ): void {
		global $wpdb;
		if ( false !== $ok ) {
			return;
		}
		$errno = self::errno();
		if ( 'error' !== self::errorKind( $errno, null !== $unit ) ) {
			throw new UnitConflict( (int) $errno, esc_html( (string) $unit ) );
		}
		throw new \RuntimeException( 'slotiva_db_error: ' . esc_html( $wpdb->last_error ) );
	}

	/**
	 * MySQL hata kodunun sınıfı: `retry` (1213 kilitlenme, 1205 kilit bekleme zaman aşımı → bir kez yeniden dene, sonra
	 * çakışma), `conflict` (1062, yalnız birim eklemesinde), `error` (diğer her şey → RuntimeException).
	 *
	 * @internal Testler için açık; depo dışında kullanılmaz.
	 * @param int  $errno      MySQL hata kodu.
	 * @param bool $unitInsert Hata bir occupancy birim eklemesinde mi.
	 * @return 'retry'|'conflict'|'error'
	 */
	public static function errorKind( int $errno, bool $unitInsert ): string {
		if ( self::ER_DEADLOCK === $errno || self::ER_LOCK_WAIT === $errno ) {
			return 'retry';
		}
		if ( self::ER_DUP === $errno && $unitInsert ) {
			return 'conflict';
		}
		return 'error';
	}

	/**
	 * ROLLBACK sonrası: istenen dilimlerden hangileri başka bir rezervasyonun birimine çarpıyor?
	 *
	 * @param array<string,mixed> $req     Normalleştirilmiş istek.
	 * @param string              $unit    Çakışan birim ('' = bilinmiyor).
	 * @param list<int>           $exclude Sahibi sayılmayan rezervasyonlar (ör. değiştirilecek kendi satırları).
	 * @return list<\DateTimeImmutable>
	 */
	private static function takenSlots( array $req, string $unit, array $exclude ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$spans = array();
		foreach ( $req['slots'] as $slot ) {
			$spans[] = array( $slot, Units::span( $slot, $req['duration'], $req['buffer'] ) );
		}
		return self::takenFromSpans( $spans, $unit, $exclude );
	}

	/**
	 * Satırların (kendi süresi + ara) dilim/birim çiftleri.
	 *
	 * @param list<Reservation> $rows   Satırlar.
	 * @param int               $buffer Ara (dk).
	 * @return list<array{0:\DateTimeImmutable,1:list<\DateTimeImmutable>}>
	 */
	private static function rowSpans( array $rows, int $buffer ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$spans = array();
		foreach ( $rows as $r ) {
			$spans[] = array( $r->startsAt, Units::span( $r->startsAt, self::minutes( $r ), $buffer ) );
		}
		return $spans;
	}

	/**
	 * Dilimlerden başka bir rezervasyonun birimine çarpanlar. Kimse çarpmıyorsa (istek kendi içinde örtüşüyor ya da
	 * kilitlenme) hatalı birimi içeren dilim(ler), o da yoksa hepsi.
	 *
	 * @param list<array{0:\DateTimeImmutable,1:list<\DateTimeImmutable>}> $spans   Dilim + birimleri.
	 * @param string                                                       $unit    Çakışan birim ('' = bilinmiyor).
	 * @param list<int>                                                    $exclude Sahibi sayılmayan rezervasyonlar.
	 * @return list<\DateTimeImmutable>
	 */
	private static function takenFromSpans( array $spans, string $unit, array $exclude ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		global $wpdb;
		$keys = array();
		$all  = array();
		foreach ( $spans as $i => $span ) {
			$keys[ $i ] = array_map( static fn( \DateTimeImmutable $u ): string => $u->format( self::FMT ), $span[1] );
			$all        = array_merge( $all, $keys[ $i ] );
		}
		$busy = array();
		if ( $all ) {
			sort( $all );
			$occ  = Schema::table( 'occupancy' );
			$res  = Schema::table( 'reservations' );
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT o.unit_start, o.reservation_id, r.capacity FROM %i o LEFT JOIN %i r ON r.id = o.reservation_id WHERE o.resource_id = %d AND o.unit_start >= %s AND o.unit_start <= %s', $occ, $res, Units::RESOURCE_ID, $all[0], $all[ count( $all ) - 1 ] ), ARRAY_A );
			$want = array_flip( $all );
			// Koltuk farkındalığı (K2): birim, diğer satırların sayısı onların en küçük kapasitesine ulaştıysa doludur (sahipsiz
			// satır kapasite 1 sayılır; kapasite 1'de tek satır yeter, 1.0.3 ile aynı).
			$count = array();
			$cap   = array();
			foreach ( (array) $rows as $row ) {
				$u = (string) $row['unit_start'];
				if ( isset( $want[ $u ] ) && ! in_array( (int) $row['reservation_id'], $exclude, true ) ) {
					$count[ $u ] = ( $count[ $u ] ?? 0 ) + 1;
					$cap[ $u ]   = min( $cap[ $u ] ?? Capacity::MAX, max( 1, (int) ( $row['capacity'] ?? 1 ) ) );
				}
			}
			foreach ( $count as $u => $n ) {
				if ( $n >= $cap[ $u ] ) {
					$busy[ $u ] = true;
				}
			}
		}
		$out      = array();
		$fallback = array();
		foreach ( $spans as $i => $span ) {
			if ( array_intersect_key( array_flip( $keys[ $i ] ), $busy ) ) {
				$out[] = $span[0];
			}
			if ( in_array( $unit, $keys[ $i ], true ) ) {
				$fallback[] = $span[0];
			}
		}
		if ( $out ) {
			return $out;
		}
		return $fallback ? $fallback : array_map( static fn( array $s ): \DateTimeImmutable => $s[0], $spans );
	}

	/**
	 * Satırın süresi (dk, ara hariç).
	 *
	 * @param Reservation $r Satır.
	 */
	private static function minutes( Reservation $r ): int {
		return (int) ( ( $r->endsAt->getTimestamp() - $r->startsAt->getTimestamp() ) / 60 );
	}

	/**
	 * Oturum/IP özeti: null ya da '' → null; 32 küçük hex → kendisi; diğerleri reddedilir.
	 *
	 * @param mixed $v Değer.
	 * @throws \InvalidArgumentException Geçersiz özet.
	 */
	private static function hashOrNull( $v ): ?string {
		if ( null === $v || '' === $v ) {
			return null;
		}
		if ( ! is_string( $v ) || ! preg_match( '/^[0-9a-f]{32}$/', $v ) ) {
			throw new \InvalidArgumentException( 'slotiva_invalid_hash' );
		}
		return $v;
	}

	/**
	 * İsteği doğrular/normalleştirir: dilimler UTC'ye çevrilir ve artan sıralanır.
	 *
	 * @param array<string,mixed> $req Ham istek.
	 * @return array{product_id:int,product_name:string,variation_id:int,order_id:?int,order_item_id:?int,slots:list<\DateTimeImmutable>,duration:int,buffer:int,status:string,payment_state:string,hold_expires_at:?\DateTimeImmutable,customer:array{name:string,email:string,phone:string},location:string,notify:bool,session_hash:?string,ip_hash:?string}
	 * @throws \InvalidArgumentException Geçersiz istek.
	 */
	private static function normalize( array $req ): array {
		$utc   = new \DateTimeZone( 'UTC' );
		$slots = array();
		foreach ( (array) ( $req['slots'] ?? array() ) as $s ) {
			if ( ! $s instanceof \DateTimeImmutable ) {
				throw new \InvalidArgumentException( 'slotiva_invalid_slot' );
			}
			$slots[] = $s->setTimezone( $utc );
		}
		usort( $slots, static fn( \DateTimeImmutable $a, \DateTimeImmutable $b ): int => $a <=> $b );
		$duration = (int) ( $req['duration'] ?? 0 );
		$buffer   = (int) ( $req['buffer'] ?? 0 );
		$status   = (string) ( $req['status'] ?? 'held' );
		if ( ! $slots || $duration <= 0 || $duration % Rules::STEP || $buffer < 0 || $buffer % Rules::STEP || ! in_array( $status, self::ACTIVE, true ) ) {
			throw new \InvalidArgumentException( 'slotiva_invalid_claim' );
		}
		$hold     = $req['hold_expires_at'] ?? null;
		$customer = (array) ( $req['customer'] ?? array() );
		return array(
			'product_id'      => (int) ( $req['product_id'] ?? 0 ),
			'product_name'    => mb_substr( (string) ( $req['product_name'] ?? '' ), 0, 255 ),
			'variation_id'    => (int) ( $req['variation_id'] ?? 0 ),
			'order_id'        => isset( $req['order_id'] ) ? (int) $req['order_id'] : null,
			'order_item_id'   => isset( $req['order_item_id'] ) ? (int) $req['order_item_id'] : null,
			'slots'           => $slots,
			'duration'        => $duration,
			'buffer'          => $buffer,
			'status'          => $status,
			'payment_state'   => (string) ( $req['payment_state'] ?? 'pending' ),
			'hold_expires_at' => 'held' === $status && $hold instanceof \DateTimeImmutable ? $hold->setTimezone( $utc ) : null,
			'customer'        => array(
				'name'  => mb_substr( (string) ( $customer['name'] ?? '' ), 0, 190 ),
				'email' => mb_substr( (string) ( $customer['email'] ?? '' ), 0, 190 ),
				'phone' => mb_substr( (string) ( $customer['phone'] ?? '' ), 0, 60 ),
			),
			'location'        => mb_substr( (string) ( $req['location'] ?? '' ), 0, 255 ),
			'notify'          => (bool) ( $req['notify'] ?? true ),
			'session_hash'    => self::hashOrNull( $req['session_hash'] ?? null ),
			'ip_hash'         => self::hashOrNull( $req['ip_hash'] ?? null ),
		);
	}

	/**
	 * Olay bağlamı: notify (vars. true), reason, order_id.
	 *
	 * @param array<string,mixed> $ctx     Çağıranın bağlamı.
	 * @param int|null            $orderId Satırın siparişi.
	 * @param string              $reason  Varsayılan neden.
	 * @return array{notify:bool,reason:string,order_id:?int}
	 */
	private static function ctx( array $ctx, ?int $orderId, string $reason = '' ): array {
		return array(
			'notify'   => (bool) ( $ctx['notify'] ?? true ),
			'reason'   => (string) ( $ctx['reason'] ?? $reason ),
			'order_id' => $orderId,
		);
	}

	/**
	 * İşlem içinde geçmiş satırı yazar; başarısızlık (ör. 1213) işlemi düşürür.
	 *
	 * @param int                 $id   Rezervasyon.
	 * @param string              $type Olay türü.
	 * @param array<string,mixed> $data Ek veri.
	 */
	private static function logEvent( int $id, string $type, array $data ): void {
		self::guard( EventLog::insert( $id, $type, $data ) );
	}

	/**
	 * Bırakma hedefi: geçerli ve etkin olmayan bir durum.
	 *
	 * @param string $status Durum.
	 * @throws \InvalidArgumentException Bilinmeyen ya da etkin durum.
	 */
	private static function assertReleaseStatus( string $status ): void {
		self::assertStatus( $status );
		if ( in_array( $status, self::ACTIVE, true ) ) {
			throw new \InvalidArgumentException( 'slotiva_release_to_active_status' );
		}
	}

	/**
	 * Durum beyaz listesi.
	 *
	 * @param string $status Durum.
	 * @throws \InvalidArgumentException Bilinmeyen durum.
	 */
	private static function assertStatus( string $status ): void {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'slotiva_invalid_status' );
		}
	}

	/**
	 * Satırları nesneye çevirir.
	 *
	 * @param mixed $rows get_results(ARRAY_A) sonucu.
	 * @return list<Reservation>
	 */
	private static function hydrate( $rows ): array {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = Reservation::fromRow( (array) $row );
		}
		return $out;
	}
}
