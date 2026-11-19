<?php
/**
 * Koltuk kabulü: bir rezervasyon bir saatin birimlerine girebilir mi, girerse hangi koltukta (K2–K7, K10).
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Reservation;

use MHM\Slotiva\Availability\SlotCalculator;
use MHM\Slotiva\Availability\Units;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar, yöntem ve BookableProduct alan adları sözleşmeyle camelCase.

/**
 * Tek kabul fonksiyonu: her yazma yolu (alma, sipariş yeniden alma, değiştirme, saat değiştirme, reclaim) bunu kaynak kilidi
 * altında, düz okumayla çağırır (spec kural 3). Ret = `SlotTakenException` (1062 ile aynı kullanıcı yolu, K2).
 *
 * Kurallar birim başına denetlenir (K2): birimdeki DİĞER doluluk satırları (taşınanın kendi satırları, değiştirilen siparişin
 * satırları ve süresi geçmiş tutmalar hariç) hep aynı ebeveyn ürün + aynı başlangıç olmalı (K3) ve satır sayısı + 1 ≤
 * min( diğerlerinin `capacity`'leri, ürünün şimdiki kapasitesi, gelenin kapasitesi ) (K4). Katılım (birimde başka satır var)
 * yalnız ürünün ŞİMDİKİ süre ızgarasındaki başlangıca olur (K6). Aynı siparişin aynı başlangıcı yeniden alması, kendi satırları
 * hâlâ dururken, kendi satırının tuttuğu birimlerde denetimden ve K6'dan muaftır; kendi koltuğunu (tüm birimlerde boşsa) korur
 * (K5). Yeni aralığın kendi satırının tutmadığı birimleri olağan K3/K4'ten geçer.
 */
final class SeatPolicy {

	/** Siparişin yeniden alımda değiştirilen (kendi koltuğu sayılan) durumları. */
	private const REPLACED = array( 'held', 'confirmed', 'expired' );

	/**
	 * Kabul: kullanılacak koltuk (claim'in tüm birimlerinde aynı) ya da ret.
	 *
	 * @param int                      $resourceId          Kaynak.
	 * @param int                      $parentProductId     Ebeveyn ürün (varyasyon aynı ürün sayılır, K3).
	 * @param \DateTimeImmutable       $startUtc            Rezervasyon başlangıcı.
	 * @param list<\DateTimeImmutable> $unitStarts          Kapsadığı birimler (süre + ara).
	 * @param int                      $capacity            Gelenin kapasitesi: yeni almada ürün kapasitesi, taşımada satırın `capacity`'si (K4).
	 * @param int|null                 $movingReservationId Taşınan rezervasyon (kendi satırları sayılmaz).
	 * @param int|null                 $sameOrderId         Aynı işlemde yerine yenisi yazılan sipariş (K5).
	 * @param int|null                 $current             Ürünün şimdiki kapasitesi (yazma başına bir kez okunur, D4; null = burada okunur).
	 * @throws SlotTakenException Birim dolu ya da başka saate ait.
	 */
	public static function admit( int $resourceId, int $parentProductId, \DateTimeImmutable $startUtc, array $unitStarts, int $capacity, ?int $movingReservationId = null, ?int $sameOrderId = null, ?int $current = null ): int { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		return self::place( $resourceId, $parentProductId, $startUtc, $unitStarts, $capacity, $movingReservationId, $sameOrderId, $current )['seat'];
	}

	/**
	 * `admit()`'in tamamı: koltuk + damgalanacak kapasite. `stamp` = yeni katılımcının etkin kapasitesi (K4: birimlerdeki
	 * satırların ve ürünün şimdikinin en küçüğü); `exempt` = K5 muafiyeti (stamp = kendi eski satırının değeri).
	 *
	 * @param int                      $resourceId          Kaynak.
	 * @param int                      $parentProductId     Ebeveyn ürün.
	 * @param \DateTimeImmutable       $startUtc            Başlangıç.
	 * @param list<\DateTimeImmutable> $unitStarts          Birimler.
	 * @param int                      $capacity            Gelenin kapasitesi.
	 * @param int|null                 $movingReservationId Taşınan rezervasyon.
	 * @param int|null                 $sameOrderId         Yerine yenisi yazılan sipariş.
	 * @param int|null                 $current             Ürünün şimdiki kapasitesi (null = okunur).
	 * @return array{seat:int,stamp:int,exempt:bool}
	 * @throws SlotTakenException Ret.
	 */
	public static function place( int $resourceId, int $parentProductId, \DateTimeImmutable $startUtc, array $unitStarts, int $capacity, ?int $movingReservationId = null, ?int $sameOrderId = null, ?int $current = null ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$utc     = new \DateTimeZone( 'UTC' );
		$start   = $startUtc->setTimezone( $utc )->format( Units::FORMAT );
		$keys    = array_values( array_unique( array_map( static fn( \DateTimeImmutable $u ): string => $u->setTimezone( $utc )->format( Units::FORMAT ), $unitStarts ) ) );
		$current = $current ?? Capacity::forProduct( $parentProductId );
		$limit   = self::limit( $capacity, $current );
		$refuse  = static fn(): SlotTakenException => new SlotTakenException( array( $startUtc->setTimezone( $utc ) ) );
		if ( ! $keys ) {
			return array(
				'seat'   => 1,
				'stamp'  => $limit,
				'exempt' => false,
			);
		}
		$now    = Clock::now()->getTimestamp();
		$own    = array();
		$others = array();
		foreach ( Units::occupants( $keys, $resourceId ) as $row ) {
			$rid = $row['reservation_id'];
			if ( null !== $movingReservationId && $rid === $movingReservationId ) {
				continue;
			}
			if ( null !== $sameOrderId && $row['order_id'] === $sameOrderId && in_array( $row['status'], self::REPLACED, true ) ) {
				$own[] = $row;
				continue;
			}
			if ( 'held' === $row['status'] && null !== $row['hold_expires_at'] && $row['hold_expires_at'] < $now ) {
				// Süresi geçmiş tutma sayılmaz; koltuğu seçilirse 1062 → tembel dolum (depo), kalanları depo süpürür (K2).
				continue;
			}
			$others[ $row['unit_start'] ][] = $row;
		}
		$used   = array();
		$groups = array();
		foreach ( $others as $unit => $rows ) {
			foreach ( $rows as $row ) {
				$used[ $row['seat'] ] = true;
				$groups[ $unit ][]    = array(
					'product_id' => $row['product_id'],
					'starts_at'  => $row['starts_at'],
					'capacity'   => $row['capacity'],
					'seats'      => 1,
				);
			}
		}

		// K5: aynı sipariş aynı başlangıcı, kendi satırları dururken yeniden alıyor → kendi koltuğu. Muafiyet YALNIZ kendi
		// satırının hâlâ tuttuğu birimler içindir; yeni aralığın (ara/süre büyümüş) diğer birimleri olağan K3/K4'ten geçer.
		$exempt = null;
		foreach ( $own as $row ) {
			if ( $row['product_id'] === $parentProductId && $row['starts_at'] === $start && $row['unit_start'] === $keys[0] ) {
				$exempt = $row;
				break;
			}
		}
		$held = array();
		if ( null !== $exempt ) {
			foreach ( $own as $row ) {
				if ( $row['reservation_id'] === $exempt['reservation_id'] ) {
					$held[ $row['unit_start'] ] = true;
				}
			}
		}

		$verdict = self::evaluate(
			$parentProductId,
			$start,
			array_values( array_filter( $keys, static fn( string $k ): bool => ! isset( $held[ $k ] ) ) ),
			$groups,
			null === $exempt ? $limit : min( $limit, max( 1, $exempt['capacity'] ?? 1 ) )
		);
		if ( $verdict['left'] < 1 ) {
			throw $refuse();
		}
		$stamp   = $verdict['stamp'];
		$joining = $verdict['joining'];
		if ( null !== $exempt ) {
			// Kendi koltuğu, yalnız TÜM birimlerde boşsa; değilse ret (başka koltuğa kaydırmak muafiyeti genişletirdi).
			if ( isset( $used[ $exempt['seat'] ] ) ) {
				throw $refuse();
			}
			return array(
				'seat'   => $exempt['seat'],
				'stamp'  => max( 1, $exempt['capacity'] ?? 1 ),
				'exempt' => true,
			);
		}
		if ( $joining && ! self::onGrid( $parentProductId, $startUtc ) ) {
			throw $refuse();
		}
		return array(
			'seat'   => self::freeSeat( $used ),
			'stamp'  => $stamp,
			'exempt' => false,
		);
	}

	/**
	 * Gelenin sınırı (K4): kendi kapasitesi (1..MAX) ile ürünün şimdiki kapasitesinin küçüğü.
	 *
	 * @param int $capacity Gelenin kapasitesi (yeni almada ürün kapasitesi, taşımada satırın `capacity`'si).
	 * @param int $current  Ürünün şimdiki kapasitesi.
	 */
	public static function limit( int $capacity, int $current ): int {
		return min( max( 1, min( Capacity::MAX, $capacity ) ), $current );
	}

	/**
	 * Saf kabul değerlendirmesi — K3 sahiplik + K4 etkin kapasite, birim başına (K2). Yazma (`place`) ve müsaitlik
	 * (`seatsLeft`) bu TEK fonksiyonu kullanır (R18); veritabanına bakmaz.
	 *
	 * Birimde satır yoksa kalan = `$limit`. Varsa: hepsi aynı ebeveyn ürün + aynı başlangıç olmalı (değilse `left` 0), etkin
	 * kapasite = min( `$limit`, grupların `capacity`'leri ), kalan = etkin − dolu koltuk. `left` = birimlerin en küçüğü, 0'a
	 * kırpılır; `stamp` = katılınan birimlerin etkin kapasitesinin en küçüğü (boşsa `$limit`); `joining` = dolu birim var.
	 *
	 * @param int                                                                                  $parentProductId Ebeveyn ürün.
	 * @param string                                                                               $start           Başlangıç ('Y-m-d H:i:s' UTC).
	 * @param list<string>                                                                         $keys            Denetlenecek birimler.
	 * @param array<string,list<array{product_id:?int,starts_at:?string,capacity:?int,seats:int}>> $groups          Birim → diğer satırlar (ürün + başlangıç grupları).
	 * @param int                                                                                  $limit           Gelenin sınırı (`limit()`).
	 * @return array{left:int,stamp:int,joining:bool}
	 */
	public static function evaluate( int $parentProductId, string $start, array $keys, array $groups, int $limit ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$left    = $limit;
		$stamp   = $limit;
		$joining = false;
		foreach ( $keys as $key ) {
			$rows = $groups[ $key ] ?? array();
			if ( ! $rows ) {
				continue;
			}
			$joining = true;
			$min     = $limit;
			$taken   = 0;
			foreach ( $rows as $g ) {
				// K3: sahiplik ebeveyn ürün + başlangıç; sahipsiz (rezervasyonu olmayan) satır yabancıdır.
				if ( $g['product_id'] !== $parentProductId || $g['starts_at'] !== $start ) {
					return array(
						'left'    => 0,
						'stamp'   => $stamp,
						'joining' => true,
					);
				}
				$min    = min( $min, max( 1, $g['capacity'] ?? 1 ) );
				$taken += $g['seats'];
			}
			$left  = min( $left, $min - $taken );
			$stamp = min( $stamp, $min );
		}
		return array(
			'left'    => max( 0, $left ),
			'stamp'   => $stamp,
			'joining' => $joining,
		);
	}

	/**
	 * Gösterim için kalan yer (K2, kural 8): `evaluate()` + K6 (katılım yalnız ürünün şimdiki ızgarasında, `place` ile aynı
	 * denetim). Okuma anındaki değerdir; yazma kilit altında `place()` ile yeniden denetlenir (kural 3).
	 *
	 * @param int                                                                                  $parentProductId Ebeveyn ürün.
	 * @param \DateTimeImmutable                                                                   $startUtc        Başlangıç.
	 * @param list<string>                                                                         $keys            Birimler ('Y-m-d H:i:s' UTC).
	 * @param array<string,list<array{product_id:?int,starts_at:?string,capacity:?int,seats:int}>> $groups          Birim → gruplar (`Units::occupancy`).
	 * @param int                                                                                  $limit           Gelenin sınırı (`limit()`).
	 * @param bool                                                                                 $checkGrid       K6 denetlensin mi (müşteri adayları zaten ızgaradan gelir → false).
	 */
	public static function seatsLeft( int $parentProductId, \DateTimeImmutable $startUtc, array $keys, array $groups, int $limit, bool $checkGrid ): int { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$verdict = self::evaluate( $parentProductId, $startUtc->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Units::FORMAT ), $keys, $groups, $limit );
		if ( $verdict['left'] > 0 && $verdict['joining'] && $checkGrid && ! self::onGrid( $parentProductId, $startUtc ) ) {
			return 0;
		}
		return $verdict['left'];
	}

	/**
	 * Kullanılmayan en küçük koltuk.
	 *
	 * @param array<int,true> $used Dolu koltuklar.
	 */
	private static function freeSeat( array $used ): int {
		$seat = 1;
		while ( isset( $used[ $seat ] ) ) {
			++$seat;
		}
		return $seat;
	}

	/**
	 * K6: başlangıç ürünün ŞİMDİKİ süre ızgarasında mı — tek kaynak `SlotCalculator::onGrid` (müşteri adaylarıyla aynı
	 * ızgara; ön süre/ufuk UYGULANMAZ, yönetici ve geç ödeme yolları da buradan geçer). Ürün randevulu değilse
	 * (silinmiş/kapatılmış, K10) ızgara bilinmez → engellenmez.
	 *
	 * @param int                $productId Ebeveyn ürün.
	 * @param \DateTimeImmutable $startUtc  Başlangıç.
	 */
	private static function onGrid( int $productId, \DateTimeImmutable $startUtc ): bool {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $productId ) : null;
		$bp      = $product instanceof \WC_Product ? BookableProduct::from( $product ) : null;
		return null === $bp || SlotCalculator::onGrid( $bp->durationMin, $startUtc );
	}
}
