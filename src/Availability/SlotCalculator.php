<?php
/**
 * Müsaitlik hesabı: haftalık düzen + istisnalar + ön süre/ufuk + dolu birimler → sunulan dilimler.
 *
 * @package MHM\Slotiva
 */

namespace MHM\Slotiva\Availability;

use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\Capacity;
use MHM\Slotiva\Reservation\SeatPolicy;
use MHM\Slotiva\Schedule\ExceptionRepository;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase, WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- imzalar sözleşmeyle camelCase.

/**
 * Tüm hesap işletme saat diliminde (`wp_timezone()`) yapılır; çıktı `start` UTC'dir.
 * Dilim ızgarası = ürün süresi adımı, o günün (birleştirilmiş) açık aralığının başından.
 *
 * @phpstan-type ExceptionRow array{id:int,date:string,from_time:?string,to_time:?string,type:'open'|'closed',note:string}
 */
final class SlotCalculator {

	/** `days()` en çok bu kadar gün döndürür. */
	private const MAX_DAYS = 62;

	/**
	 * Gün başına özet. Aralık `bugün_yerel … bugün_yerel + horizonDays` ile ve en çok 62 günle sınırlanır
	 * (dışı çıktıda yer almaz).
	 *
	 * `open` = takvim (haftalık düzen + istisnalar + ön süre/ufuk) sonrası aday dilim var; doluluktan BAĞIMSIZ.
	 * `free` = en az 1 yeri kalan aday dilim sayısı (kural 8; kapasite 1'de dolu birime değmeyenler). Tam dolu açık gün:
	 * open=true, free=0.
	 *
	 * @param BookableProduct $p        Ürün.
	 * @param string          $fromDate Y-m-d (yerel, dahil).
	 * @param string          $toDate   Y-m-d (yerel, dahil).
	 * @param int|null        $capacity Ürünün şimdiki kapasitesi (istek başına bir kez okunur; null = burada okunur).
	 * @return list<array{date:string,open:bool,free:int}>
	 */
	public static function days( BookableProduct $p, string $fromDate, string $toDate, ?int $capacity = null ): array {
		$tz   = wp_timezone();
		$from = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fromDate, $tz );
		$to   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $toDate, $tz );
		if ( ! $from || ! $to ) {
			return array();
		}
		$today = Clock::now()->setTimezone( $tz )->setTime( 0, 0 );
		$from  = max( $from, $today );
		$to    = min( $to, $today->modify( '+' . $p->horizonDays . ' days' ), $from->modify( '+' . ( self::MAX_DAYS - 1 ) . ' days' ) );
		if ( $to < $from ) {
			return array();
		}
		$rules      = Rules::load();
		$exceptions = self::group_exceptions( ExceptionRepository::between( $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ) ) );
		$weekly     = WeeklySchedule::load();
		$occupancy  = Units::occupancy( $from, $to->modify( '+1 day' )->modify( '+' . ( $p->durationMin + $rules->bufferMin ) . ' minutes' ) );
		$capacity   = $capacity ?? Capacity::forProduct( $p->productId );

		$out = array();
		for ( $d = $from; $d <= $to; $d = $d->modify( '+1 day' ) ) {
			$date       = $d->format( 'Y-m-d' );
			$candidates = self::candidates( $p, $d, $weekly, $exceptions[ $date ] ?? array() );
			$out[]      = array(
				'date' => $date,
				'open' => count( $candidates ) > 0,
				'free' => count( self::with_seats( $p, $rules, $candidates, $occupancy, $capacity ) ),
			);
		}
		return $out;
	}
	/**
	 * Bir yerel günün sunulan dilimleri (1.0.3 biçimi).
	 *
	 * @param BookableProduct $p         Ürün.
	 * @param string          $localDate Y-m-d (yerel).
	 * @param int|null        $capacity  Ürünün şimdiki kapasitesi (null = burada okunur).
	 * @return list<array{start:string,label:string}> `start` ISO-8601 UTC (`Z`), `label` yerel "HH:MM".
	 */
	public static function slots( BookableProduct $p, string $localDate, ?int $capacity = null ): array {
		return array_map(
			static fn( array $s ): array => array(
				'start' => $s['start'],
				'label' => $s['label'],
			),
			self::seatSlots( $p, $localDate, $capacity )
		);
	}

	/**
	 * Bir yerel günün sunulan dilimleri ve her birinin kalan yeri (K2: birimlerdeki kalanın en küçüğü). Yalnız en az 1 yeri
	 * kalanlar döner; karar `SeatPolicy::seatsLeft` (kabulle aynı fonksiyon, R18). `seatsLeft` iç değerdir — REST yanıtına
	 * Lite koymaz (K8).
	 *
	 * @param BookableProduct $p         Ürün.
	 * @param string          $localDate Y-m-d (yerel).
	 * @param int|null        $capacity  Ürünün şimdiki kapasitesi (null = burada okunur).
	 * @return list<array{start:string,label:string,seatsLeft:int}>
	 */
	public static function seatSlots( BookableProduct $p, string $localDate, ?int $capacity = null ): array {
		$tz  = wp_timezone();
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $localDate, $tz );
		if ( ! $day ) {
			return array();
		}
		$rules     = Rules::load();
		$occupancy = Units::occupancy( $day, $day->modify( '+1 day' )->modify( '+' . ( $p->durationMin + $rules->bufferMin ) . ' minutes' ) );
		$capacity  = $capacity ?? Capacity::forProduct( $p->productId );
		$utc       = new \DateTimeZone( 'UTC' );
		$out       = array();
		foreach ( self::with_seats( $p, $rules, self::candidates( $p, $day, WeeklySchedule::load(), ExceptionRepository::between( $localDate, $localDate ) ), $occupancy, $capacity ) as $c ) {
			$out[] = array(
				'start'     => $c[0]->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' ),
				'label'     => $c[0]->setTimezone( $tz )->format( 'H:i' ),
				'seatsLeft' => $c[1],
			);
		}
		return $out;
	}

	/**
	 * Başlangıç ızgara/düzen/istisna/ön süre/ufuk kurallarına uyuyor mu? Doluluğa BAKMAZ (o `claim()` işi).
	 *
	 * @param BookableProduct    $p        Ürün.
	 * @param \DateTimeImmutable $startUtc Dilim başlangıcı.
	 */
	public static function isBookable( BookableProduct $p, \DateTimeImmutable $startUtc ): bool {
		$tz    = wp_timezone();
		$local = $startUtc->setTimezone( $tz );
		$date  = $local->format( 'Y-m-d' );
		$day   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( ! $day ) {
			return false;
		}
		$ts = $startUtc->getTimestamp();
		foreach ( self::candidates( $p, $day, WeeklySchedule::load(), ExceptionRepository::between( $date, $date ) ) as $c ) {
			if ( $c->getTimestamp() === $ts ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Yönetici ızgarası (spec §15.10): o yerel günün açık aralıkları içinde, aralık başından 15 dakikalık adımlar; ön süre ve
	 * ufuk UYGULANMAZ (yönetici bugün için de ekler), yalnız şimdiden önceki başlangıçlar çıkar. Kalan yeri olmayan dilim
	 * çıkmaz (`SeatPolicy::seatsLeft`, kabulle aynı karar — R18): kapasite 1'de süre + ara birimlerinden biri doluysa; grup
	 * ürününde aynı ürün + aynı başlangıç olmayan doluluğa değerse (kural 4) ya da katılım ürünün şimdiki ızgarasında
	 * değilse (K6). `$excludeReservation` birimleri dolu sayılmaz (o randevunun saatini değiştirirken; K4 taşıma kabulü).
	 *
	 * @param int      $durationMin        Süre (dk).
	 * @param int      $bufferMin          Ara (dk).
	 * @param string   $localDate          Y-m-d (yerel).
	 * @param int      $excludeReservation Birimleri yok sayılacak rezervasyon (0 = yok).
	 * @param int      $productId          Ebeveyn ürün (K3 sahipliği; 0 = bilinmiyor → her doluluk yabancı).
	 * @param int|null $capacity           Gelenin kapasitesi: ekleme = ürünün şimdiki, saat değişimi = rezervasyonun `capacity`'si (null = şimdiki).
	 * @param int|null $current            Ürünün şimdiki kapasitesi (istek başına bir kez; null = burada okunur).
	 * @return list<array{start:string,label:string,seatsLeft:int}> `start` ISO-8601 UTC (`Z`), `label` yerel "HH:MM".
	 */
	public static function adminSlots( int $durationMin, int $bufferMin, string $localDate, int $excludeReservation = 0, int $productId = 0, ?int $capacity = null, ?int $current = null ): array {
		$tz  = wp_timezone();
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $localDate, $tz );
		if ( ! $day || $durationMin < Rules::STEP ) {
			return array();
		}
		$occupancy = Units::occupancy( $day, $day->modify( '+1 day' )->modify( '+' . ( $durationMin + $bufferMin ) . ' minutes' ), Units::RESOURCE_ID, $excludeReservation );
		$current   = $current ?? ( $productId > 0 ? Capacity::forProduct( $productId ) : 1 );
		$limit     = SeatPolicy::limit( $capacity ?? $current, $current );
		$now       = Clock::now()->getTimestamp();
		$utc       = new \DateTimeZone( 'UTC' );
		$out       = array();
		foreach ( self::open_ranges( WeeklySchedule::load()[ (int) $day->format( 'N' ) - 1 ] ?? array(), ExceptionRepository::between( $localDate, $localDate ) ) as $range ) {
			for ( $m = $range[0]; $m + $durationMin <= $range[1]; $m += Rules::STEP ) {
				$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $localDate . ' ' . sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 ), $tz );
				if ( ! $start || $start->getTimestamp() < $now ) {
					continue;
				}
				$left = SeatPolicy::seatsLeft( $productId, $start, self::unit_keys( $start, $durationMin, $bufferMin ), $occupancy, $limit, true );
				if ( $left > 0 ) {
					$out[] = array(
						'start'     => $start->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' ),
						'label'     => $start->format( 'H:i' ),
						'seatsLeft' => $left,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Yönetici ızgarasına uyuyor mu (doluluğa BAKMAZ, o `claim()`/`reschedule()` işi): şimdiden önce değil, yerel saatte
	 * 15 dakikalık birime hizalı ve [başlangıç, başlangıç + süre) o günün tek bir açık aralığının içinde.
	 *
	 * @param int                $durationMin Süre (dk).
	 * @param \DateTimeImmutable $startUtc    Başlangıç.
	 */
	public static function isAdminBookable( int $durationMin, \DateTimeImmutable $startUtc ): bool {
		return $startUtc->getTimestamp() >= Clock::now()->getTimestamp() && self::withinOpenHours( $startUtc, $durationMin );
	}

	/**
	 * Başlangıç 15 dk'ya hizalı ve [başlangıç, başlangıç + süre) o yerel günün (haftalık düzen + istisnalar) tek bir açık
	 * aralığının içinde mi? Zamana (geçmiş/ön süre/ufuk) ve doluluğa bakmaz — çalışma saati değişikliği uyarısı da bunu kullanır.
	 *
	 * Çok satırlı denetimde (`outside_hours`) haftalık düzen ve istisnalar bir kez yüklenip verilir; verilmezse okunur.
	 *
	 * @param \DateTimeImmutable                                 $startUtc         Başlangıç.
	 * @param int                                                $durationMin      Süre (dk).
	 * @param array<string,list<array<string,mixed>>>|null       $exceptionsByDate Yerel tarih → o günün istisnaları.
	 * @param array<int,list<array{from:string,to:string}>>|null $weekly        Haftalık düzen (0 = Pazartesi).
	 */
	public static function withinOpenHours( \DateTimeImmutable $startUtc, int $durationMin, ?array $exceptionsByDate = null, ?array $weekly = null ): bool {
		$local = $startUtc->setTimezone( wp_timezone() );
		$date  = $local->format( 'Y-m-d' );
		$m     = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
		if ( 0 !== (int) $local->format( 's' ) || 0 !== $m % Rules::STEP || $durationMin <= 0 ) {
			return false;
		}
		$weekly     = $weekly ?? WeeklySchedule::load();
		$exceptions = null === $exceptionsByDate ? ExceptionRepository::between( $date, $date ) : ( $exceptionsByDate[ $date ] ?? array() );
		foreach ( self::open_ranges( $weekly[ (int) $local->format( 'N' ) - 1 ] ?? array(), $exceptions ) as $range ) {
			if ( $m >= $range[0] && $m + $durationMin <= $range[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Adaylardan en az 1 yeri kalanlar ve kalan yerleri (`SeatPolicy::seatsLeft`). Adaylar zaten şimdiki süre ızgarasından
	 * gelir (`candidates` → `gridStarts`), K6 ayrıca denetlenmez. Kapasite 1'de = süre + ara birimlerinin hiçbiri dolu
	 * olmayanlar (1.0.3).
	 *
	 * @param BookableProduct                                                                      $p          Ürün.
	 * @param Rules                                                                                $rules      Genel kurallar.
	 * @param list<\DateTimeImmutable>                                                             $candidates Aday başlangıçlar.
	 * @param array<string,list<array{product_id:?int,starts_at:?string,capacity:?int,seats:int}>> $occupancy  `Units::occupancy`.
	 * @param int                                                                                  $capacity   Ürünün şimdiki kapasitesi.
	 * @return list<array{0:\DateTimeImmutable,1:int}>
	 */
	private static function with_seats( BookableProduct $p, Rules $rules, array $candidates, array $occupancy, int $capacity ): array { // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint -- list<> sözdizimi.
		$limit = SeatPolicy::limit( $capacity, $capacity );
		$out   = array();
		foreach ( $candidates as $start ) {
			$left = SeatPolicy::seatsLeft( $p->productId, $start, self::unit_keys( $start, $p->durationMin, $rules->bufferMin ), $occupancy, $limit, false );
			if ( $left > 0 ) {
				$out[] = array( $start, $left );
			}
		}
		return $out;
	}

	/**
	 * Dilimin birim anahtarları ('Y-m-d H:i:s' UTC).
	 *
	 * @param \DateTimeImmutable $start       Başlangıç.
	 * @param int                $durationMin Süre (dk).
	 * @param int                $bufferMin   Ara (dk).
	 * @return list<string>
	 */
	private static function unit_keys( \DateTimeImmutable $start, int $durationMin, int $bufferMin ): array {
		$utc = new \DateTimeZone( 'UTC' );
		return array_map( static fn( \DateTimeImmutable $u ): string => $u->setTimezone( $utc )->format( Units::FORMAT ), Units::span( $start, $durationMin, $bufferMin ) );
	}
	/**
	 * Doluluk hariç adaylar: açık aralıklara sığan, ön süre ve ufuk içindeki dilim başlangıçları.
	 *
	 * @param BookableProduct                               $p          Ürün.
	 * @param \DateTimeImmutable                            $day        Yerel gün başı.
	 * @param array<int,list<array{from:string,to:string}>> $weekly     Haftalık düzen.
	 * @param list<array<string,mixed>>                     $exceptions Bu günün istisnaları.
	 * @return list<\DateTimeImmutable> Artan, UTC.
	 */
	private static function candidates( BookableProduct $p, \DateTimeImmutable $day, array $weekly, array $exceptions ): array {
		$tz    = $day->getTimezone();
		$date  = $day->format( 'Y-m-d' );
		$now   = Clock::now();
		$today = $now->setTimezone( $tz )->setTime( 0, 0 );
		$last  = $today->modify( '+' . $p->horizonDays . ' days' )->format( 'Y-m-d' );
		if ( $date < $today->format( 'Y-m-d' ) || $date > $last ) {
			return array();
		}
		$earliest = $now->getTimestamp() + $p->leadHours * HOUR_IN_SECONDS;
		return array_values( array_filter( self::gridStarts( $p->durationMin, $day, $weekly, $exceptions ), static fn( \DateTimeImmutable $s ): bool => $s->getTimestamp() >= $earliest ) );
	}

	/**
	 * Süre ızgarası: o yerel günün birleşik açık aralıklarının başından süre adımlarıyla, aralığa sığan başlangıçlar. Ön süre,
	 * ufuk ve doluluk UYGULANMAZ — müşteri adayları (`candidates`) ve grup katılım ızgarası (K6, `onGrid`) bu TEK kaynaktan.
	 *
	 * @param int                                           $durationMin Süre (dk).
	 * @param \DateTimeImmutable                            $day         Yerel gün başı.
	 * @param array<int,list<array{from:string,to:string}>> $weekly      Haftalık düzen.
	 * @param list<array<string,mixed>>                     $exceptions  Bu günün istisnaları.
	 * @return list<\DateTimeImmutable> Artan, UTC.
	 */
	public static function gridStarts( int $durationMin, \DateTimeImmutable $day, array $weekly, array $exceptions ): array {
		if ( $durationMin <= 0 ) {
			return array();
		}
		$tz   = $day->getTimezone();
		$date = $day->format( 'Y-m-d' );
		$utc  = new \DateTimeZone( 'UTC' );
		$out  = array();
		foreach ( self::open_ranges( $weekly[ (int) $day->format( 'N' ) - 1 ] ?? array(), $exceptions ) as $range ) {
			for ( $m = $range[0]; $m + $durationMin <= $range[1]; $m += $durationMin ) {
				$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 ), $tz );
				if ( $start ) {
					$out[] = $start->setTimezone( $utc );
				}
			}
		}
		return $out;
	}

	/**
	 * Başlangıç verilen sürenin ızgarasında mı (`gridStarts`; ön süre/ufuk/doluluk YOK). Grup katılımı (K6) bunu kullanır.
	 *
	 * @param int                $durationMin Süre (dk).
	 * @param \DateTimeImmutable $startUtc    Başlangıç.
	 */
	public static function onGrid( int $durationMin, \DateTimeImmutable $startUtc ): bool {
		$tz   = wp_timezone();
		$date = $startUtc->setTimezone( $tz )->format( 'Y-m-d' );
		$day  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		if ( ! $day ) {
			return false;
		}
		$ts = $startUtc->getTimestamp();
		foreach ( self::gridStarts( $durationMin, $day, WeeklySchedule::load(), ExceptionRepository::between( $date, $date ) ) as $s ) {
			if ( $s->getTimestamp() === $ts ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Haftalık aralıklara açık istisnaları ekler, sonra kapalıları çıkarır: çakışan kapalı istisna HER ZAMAN açık istisnaya
	 * baskındır (sıra: önce birleştirme, sonra kesme). Dakika cinsinden birleşik [başlangıç, bitiş).
	 *
	 * @param list<array{from:string,to:string}> $weekly     O günün haftalık aralıkları.
	 * @param list<array<string,mixed>>          $exceptions O günün istisnaları.
	 * @return list<array{0:int,1:int}>
	 */
	private static function open_ranges( array $weekly, array $exceptions ): array {
		$ranges = array();
		foreach ( $weekly as $r ) {
			$ranges[] = array( self::minutes( $r['from'] ), self::minutes( $r['to'] ) );
		}
		foreach ( $exceptions as $e ) {
			if ( 'open' === $e['type'] ) {
				$ranges[] = self::exception_range( $e );
			}
		}
		$ranges = self::merge( $ranges );

		foreach ( $exceptions as $e ) {
			if ( 'closed' !== $e['type'] ) {
				continue;
			}
			$cut  = self::exception_range( $e );
			$next = array();
			foreach ( $ranges as $r ) {
				if ( $cut[1] <= $r[0] || $cut[0] >= $r[1] ) {
					$next[] = $r;
					continue;
				}
				if ( $cut[0] > $r[0] ) {
					$next[] = array( $r[0], $cut[0] );
				}
				if ( $cut[1] < $r[1] ) {
					$next[] = array( $cut[1], $r[1] );
				}
			}
			$ranges = $next;
		}
		return $ranges;
	}

	/**
	 * İstisna satırının dakika aralığı; saat yoksa tüm gün.
	 *
	 * @param array<string,mixed> $e İstisna satırı.
	 * @return array{0:int,1:int}
	 */
	private static function exception_range( array $e ): array {
		if ( empty( $e['from_time'] ) || empty( $e['to_time'] ) ) {
			return array( 0, 1440 );
		}
		return array( self::minutes( (string) $e['from_time'] ), self::minutes( (string) $e['to_time'] ) );
	}

	/**
	 * Çakışan/bitişik aralıkları birleştirir.
	 *
	 * @param list<array{0:int,1:int}> $ranges Aralıklar.
	 * @return list<array{0:int,1:int}>
	 */
	private static function merge( array $ranges ): array {
		usort( $ranges, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		$out = array();
		foreach ( $ranges as $r ) {
			$n = count( $out );
			if ( $n && $r[0] <= $out[ $n - 1 ][1] ) {
				$out[ $n - 1 ][1] = max( $out[ $n - 1 ][1], $r[1] );
			} else {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * "HH:MM" → günün dakikası.
	 *
	 * @param string $t Saat.
	 */
	private static function minutes( string $t ): int {
		return (int) substr( $t, 0, 2 ) * 60 + (int) substr( $t, 3, 2 );
	}

	/**
	 * İstisnaları tarihe göre gruplar.
	 *
	 * @param list<array<string,mixed>> $rows Satırlar.
	 * @return array<string,list<array<string,mixed>>>
	 */
	private static function group_exceptions( array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['date'] ][] = $r;
		}
		return $out;
	}
}
