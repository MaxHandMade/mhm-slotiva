<?php
/**
 * K11 zorunlu yarış kapısı (Task 13): iki ayrı DB bağlantısı / gerçek paralel süreçlerle koltuk motoru.
 *
 * Eşzamanlılık: A = ayrı PHP süreci (`Support\Race`, kendi bağlantısı) gerçek depo yolunu çalıştırır; kaynak kilidini (K1)
 * alır, satırlarını yazar ve COMMIT'ten hemen önce ~1,5 sn bekler (kilit zaman aşımının, 10 sn, çok altında). B = bu
 * süreç; kilitte BEKLER, sonra karar verir. `LockBusyException` (`slotiva_busy`, 409) her yerde BAŞARISIZLIKTIR (K11).
 * N+1: N+1 çocuk süreç, bir engel (rakip bağlantı kaynak kilidini tutar, hepsi GET_LOCK'ta bekleyince bırakır) ile aynı
 * anda yarışır. Ham yazar: rakip bağlantı adlı kilit ALMADAN satır yazar (benzersiz anahtar / satır kilidi yolu).
 *
 * Her adımdan sonra SQL değişmezi (`assert_invariant`) ve her yeni katılımda K4 kabul sınırı (`assert_join_within`).
 * Kapasite yalnız bu testin süzgecinden gelir (`slotiva_product_capacity`; pakete girmez), tear_down'da kaldırılır.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Reservation;

use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Reservation\LockBusyException;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\Race;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Reservation alanları sözleşmeyle camelCase.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test yardımcıları özel tabloları doğrudan okur/yazar; tablo adları Schema'dan.
// phpcs:disable WordPress.DB.RestrictedFunctions -- bağımsız ikinci bağlantı (mysqli) yalnız test için.

class RaceTest extends SlotivaTestCase {

	/** Grup ürünü: kapasite 3, 60 dk. */
	private const P = 21;

	/** Birebir ürün: kapasite 1, 30 dk. */
	private const Q = 22;

	/** Koltuk deliği ürünü: kapasite 2, 30 dk. */
	private const G = 23;

	/** Saat. */
	private const T = '2026-11-17 10:00:00';

	/**
	 * Ürün → kapasite (test süzgeci; çocuk süreçlere de aynısı geçer).
	 *
	 * @var array<int,int>
	 */
	private array $caps = array();

	/** Ürün süreleri (dk). */
	private const DURATION = array(
		self::P => 60,
		self::Q => 30,
		self::G => 30,
	);

	public function set_up(): void {
		parent::set_up();
		$this->caps = array(
			self::P => 3,
			self::Q => 1,
			self::G => 2,
		);
		add_filter( 'slotiva_product_capacity', array( $this, 'capacity' ), 10, 2 );
		Clock::freeze( self::utc( '2026-11-10 12:00:00' ) );
	}

	public function tear_down(): void {
		remove_filter( 'slotiva_product_capacity', array( $this, 'capacity' ), 10 );
		parent::tear_down();
	}

	/**
	 * Test kapasite süzgeci.
	 *
	 * @param mixed $cap        Varsayılan.
	 * @param mixed $product_id Ürün.
	 * @return int
	 */
	public function capacity( $cap, $product_id ): int {
		return $this->caps[ (int) $product_id ] ?? (int) $cap;
	}

	private static function utc( string $s ): \DateTimeImmutable {
		return new \DateTimeImmutable( $s, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Claim isteği: her `order` ayrı oturum/IP (kota/supersede karışmasın).
	 *
	 * @param int    $product Ürün.
	 * @param string $start   Başlangıç (UTC).
	 * @param int    $order   Sipariş.
	 * @param string $status  held|confirmed.
	 * @return array<string,mixed>
	 */
	private function req( int $product, string $start, int $order, string $status = 'confirmed' ): array {
		return array(
			'product_id'      => $product,
			'product_name'    => 'Ürün ' . $product,
			'order_id'        => $order,
			'order_item_id'   => $order * 10,
			'slots'           => array( self::utc( $start ) ),
			'duration'        => self::DURATION[ $product ],
			'buffer'          => 0,
			'status'          => $status,
			'hold_expires_at' => 'held' === $status ? self::utc( '2026-11-10 12:45:00' ) : null,
			'customer'        => array(
				'name'  => 'Müşteri ' . $order,
				'email' => "m{$order}@example.test",
				'phone' => '5550000000',
			),
			'session_hash'    => md5( "sess-{$order}" ),
			'ip_hash'         => md5( "ip-{$order}" ),
		);
	}

	/**
	 * Çocuğa giden JSON biçimi (tarihler dizgi).
	 *
	 * @param array<string,mixed> $req İstek.
	 * @return array<string,mixed>
	 */
	private static function wire( array $req ): array {
		$req['slots'] = array_map( static fn( \DateTimeImmutable $d ): string => $d->format( 'Y-m-d H:i:s' ), $req['slots'] );
		if ( $req['hold_expires_at'] instanceof \DateTimeImmutable ) {
			$req['hold_expires_at'] = $req['hold_expires_at']->format( 'Y-m-d H:i:s' );
		}
		return $req;
	}

	/**
	 * Üretimdeki gibi autocommit=1 altında çalıştırır.
	 *
	 * @param callable $work İş.
	 */
	private function with_autocommit( callable $work ): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT @@SESSION.autocommit' );
		$wpdb->query( 'SET autocommit = 1' );
		try {
			$work();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET autocommit = %d', $before ? 1 : 0 ) );
		}
	}

	/** Bağımsız ikinci bağlantı. */
	private function rival(): \mysqli {
		$rival = mysqli_init();
		$this->assertTrue( @mysqli_real_connect( $rival, DB_HOST, DB_USER, DB_PASSWORD, DB_NAME ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- test bağlantısı.
		return $rival;
	}

	/** Önekli kaynak kilidi (tek kaynak: 1). */
	private static function res_lock(): string {
		return $GLOBALS['wpdb']->prefix . 'slotiva_res_1';
	}

	/**
	 * B'nin işlemi: sonuç ya da atılan istisna. `slotiva_busy` (LockBusyException) K11 gereği BAŞARISIZLIK.
	 *
	 * @param callable $work İş.
	 * @return array{0:mixed,1:float} [sonuç|istisna, bekleme sn]
	 */
	private function attempt( callable $work ): array {
		$t0 = microtime( true );
		try {
			$out = $work();
		} catch ( LockBusyException $e ) {
			$this->fail( 'K11: slotiva_busy (LockBusyException ' . $e->lock . ') başarısızlıktır' );
		} catch ( \Throwable $e ) {
			$out = $e;
		}
		return array( $out, microtime( true ) - $t0 );
	}

	/**
	 * Çocuk sonucunu döndürür; çocukta `slotiva_busy` da BAŞARISIZLIK.
	 *
	 * @param Race $race Çocuk.
	 * @return array<string,mixed>
	 */
	private function child( Race $race ): array {
		$res = $race->finish();
		$this->assertNotSame( LockBusyException::class, $res['error'] ?? '', 'K11: çocukta slotiva_busy: ' . (string) wp_json_encode( $res ) );
		return $res;
	}

	/**
	 * SQL değişmezi (K11): her birimde tek (ebeveyn ürün, başlangıç), sahipsiz satır yok, satır sayısı ≤ birimdeki
	 * satırların `capacity` değerlerinin en küçüğü, koltuklar birim içinde ayrık.
	 *
	 * @param string $step Adım adı (hata iletisi).
	 */
	private function assert_invariant( string $step ): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // autocommit=0 ise eski anlık görüntüyü bırak.
		$occ  = Schema::table( 'occupancy' );
		$res  = Schema::table( 'reservations' );
		$rows = $wpdb->get_results( "SELECT o.unit_start, COUNT(*) AS n, COUNT(DISTINCT r.product_id, r.starts_at) AS owners, MIN(r.capacity) AS cap, SUM(r.id IS NULL) AS orphans, COUNT(DISTINCT o.seat) AS seats FROM {$occ} o LEFT JOIN {$res} r ON r.id = o.reservation_id GROUP BY o.resource_id, o.unit_start", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adları Schema'dan, girdi yok.
		$this->assertSame( '', $wpdb->last_error, $step );
		foreach ( (array) $rows as $row ) {
			$where = "{$step} @ {$row['unit_start']}";
			$this->assertSame( 0, (int) $row['orphans'], "{$where}: sahipsiz doluluk satırı" );
			$this->assertSame( 1, (int) $row['owners'], "{$where}: birimde birden çok (ürün, başlangıç)" );
			$this->assertLessThanOrEqual( (int) $row['cap'], (int) $row['n'], "{$where}: satır sayısı en küçük kapasiteyi aşıyor" );
			$this->assertSame( (int) $row['n'], (int) $row['seats'], "{$where}: koltuk çakışması" );
		}
	}

	/**
	 * Yeni katılım anında (K11): katılanın her biriminde satır sayısı ≤ K4 kabul sınırı.
	 *
	 * @param int    $id    Katılan rezervasyon.
	 * @param int    $limit Kabul sınırı (claim: etkin kapasite; taşıma: taşınanın `capacity`'si dahil en küçük).
	 * @param string $step  Adım adı.
	 */
	private function assert_join_within( int $id, int $limit, string $step ): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$occ   = Schema::table( 'occupancy' );
		$units = (array) $wpdb->get_col( $wpdb->prepare( "SELECT unit_start FROM {$occ} WHERE reservation_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adı Schema'dan.
		$this->assertNotEmpty( $units, "{$step}: katılımın birimi yok" );
		foreach ( $units as $unit ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$occ} WHERE resource_id = 1 AND unit_start = %s", $unit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adı Schema'dan.
			$this->assertLessThanOrEqual( $limit, $n, "{$step} @ {$unit}: katılım sonrası sayı K4 sınırını aşıyor" );
		}
	}

	/**
	 * Birimdeki satır sayısı.
	 *
	 * @param string $unit Birim.
	 */
	private function unit_count( string $unit ): int {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'occupancy' ) . ' WHERE unit_start = %s', $unit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adı Schema'dan.
	}

	/**
	 * Siparişin satır sayısı.
	 *
	 * @param int $order Sipariş.
	 */
	private function order_rows( int $order ): int {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'reservations' ) . ' WHERE order_id = %d', $order ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adı Schema'dan.
	}

	/**
	 * Rezervasyonun koltukları.
	 *
	 * @param int $id Rezervasyon.
	 * @return list<int>
	 */
	private function seats_of( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return array_values( array_unique( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT seat FROM ' . Schema::table( 'occupancy' ) . ' WHERE reservation_id = %d', $id ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tablo adı Schema'dan.
	}

	/**
	 * Başka bağlantılarda koşulu sağlayan iş parçacığı sayısı `$n`'e ulaşana kadar bekler (engel).
	 *
	 * @param string $info_like PROCESSLIST.INFO LIKE kalıbı.
	 * @param int    $n         Beklenen sayı.
	 * @param float  $timeout   Saniye.
	 * @param Race[] $ready     Bu çocuklardan `pause_at` noktasına gelenler de sayılır (kilit kaldırılmış RED denemesinde engel
	 *                          kilide bağlı kalmasın diye).
	 */
	private function wait_for_threads( string $info_like, int $n, float $timeout = 20.0, array $ready = array() ): void {
		global $wpdb;
		$until = microtime( true ) + $timeout;
		do {
			$seen  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE %s', $info_like ) );
			$seen += count( array_filter( $ready, static fn( Race $r ): bool => $r->is_ready() ) );
			if ( $seen >= $n ) {
				return;
			}
			usleep( 20000 );
		} while ( microtime( true ) < $until );
		$this->fail( "engel: {$n} iş parçacığı beklenirken {$seen} görüldü ({$info_like})" );
	}

	/**
	 * A = çocuk süreç gerçek depo yolunu çalıştırır ve COMMIT'ten hemen önce bekler; B = `$b` bu süreçte. B kaynak
	 * kilidinde BEKLEMELİ, sonra karar vermeli.
	 *
	 * @param string           $op   Çocuğun depo yöntemi.
	 * @param array<int,mixed> $args Çocuğun bağımsız değişkenleri.
	 * @param callable         $b    B'nin işlemi.
	 * @return array{0:mixed,1:array<string,mixed>} [B sonucu|istisnası, çocuk sonucu]
	 */
	private function hold_then( string $op, array $args, callable $b ): array {
		$out = array( null, array() );
		$this->with_autocommit(
			function () use ( $op, $args, $b, &$out ): void {
				$race = Race::start( $op, $args, 1500, 'commit', $this->caps );
				$race->wait_ready();
				[ $mine, $waited ] = $this->attempt( $b );
				$child             = $this->child( $race );
				$this->assertTrue( $child['ok'], (string) wp_json_encode( $child ) );
				$this->assertGreaterThan( 0.8, $waited, 'B kaynak kilidinde beklemedi — yarış kurulmadı' );
				$out = array( $mine, $child );
			}
		);
		return $out;
	}

	// ------------------------------------------------------------------ A COMMIT'siz grup saatini tutarken B

	/** K11 (i): A grup ürününü (kap. 3) 10:00'da tutarken B kapasite 1 başka ürünü aynı saate dener → ret. */
	public function test_held_group_slot_refuses_other_cap1_product_same_time(): void {
		[ $b, $a ] = $this->hold_then( 'claim', array( self::wire( $this->req( self::P, self::T, 101 ) ) ), fn() => ReservationRepository::claim( $this->req( self::Q, self::T, 102 ) ) );
		$this->assertInstanceOf( SlotTakenException::class, $b );
		$this->assertSame( self::T, $b->taken[0]->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 0, $this->order_rows( 102 ) );
		$this->assert_join_within( (int) $a['result'][0], 3, 'A grup' );
		$this->assert_invariant( '(i)' );
	}

	/** K11 (ii): A grup ürününü 10:00–11:00 tutarken B AYNI ürünü 30 dk kaydırılmış başlangıca (10:30) dener → ret (K3). */
	public function test_held_group_slot_refuses_same_product_shifted_30_min(): void {
		[ $b ] = $this->hold_then( 'claim', array( self::wire( $this->req( self::P, self::T, 111 ) ) ), fn() => ReservationRepository::claim( $this->req( self::P, '2026-11-17 10:30:00', 112 ) ) );
		$this->assertInstanceOf( SlotTakenException::class, $b );
		$this->assertSame( 0, $this->order_rows( 112 ) );
		$this->assertSame( 1, $this->unit_count( '2026-11-17 10:30:00' ) );
		$this->assert_invariant( '(ii)' );
	}

	/**
	 * K11 (iii) koltuk deliği: kap. 2 grupta koltuk 1 iptalle boşaldı (koltuk 2 dolu). A deliği (koltuk 1) COMMIT'siz tutar;
	 * B aynı saate katılmayı dener → ret (3. koltuk açılmaz, 2/2 aşılmaz).
	 */
	public function test_seat_hole_filled_by_held_claim_refuses_next(): void {
		$first  = ReservationRepository::claim( $this->req( self::G, self::T, 121 ) )[0];
		$second = ReservationRepository::claim( $this->req( self::G, self::T, 122 ) )[0];
		$this->assertSame( array( 1 ), $this->seats_of( $first ) );
		$this->assertSame( array( 2 ), $this->seats_of( $second ) );
		$this->assertTrue( ReservationRepository::setStatus( $first, 'cancelled' ) );
		$this->assert_invariant( 'delik' );

		[ $b, $a ] = $this->hold_then( 'claim', array( self::wire( $this->req( self::G, self::T, 123 ) ) ), fn() => ReservationRepository::claim( $this->req( self::G, self::T, 124 ) ) );
		$this->assertInstanceOf( SlotTakenException::class, $b );
		$this->assertSame( array( 1 ), $this->seats_of( (int) $a['result'][0] ), 'A deliği (koltuk 1) doldurmalı' );
		$this->assert_join_within( (int) $a['result'][0], 2, 'A delik' );
		$this->assertSame( 0, $this->order_rows( 124 ) );
		$this->assertSame( 2, $this->unit_count( self::T ) );
		$this->assert_invariant( '(iii)' );
	}

	/** Koltuk deliği sahte "dolu" üretmez: delik varken (1/2) katılım kabul, koltuk 1'e oturur. */
	public function test_seat_hole_is_reused_without_false_full(): void {
		$first  = ReservationRepository::claim( $this->req( self::G, self::T, 131 ) )[0];
		$second = ReservationRepository::claim( $this->req( self::G, self::T, 132 ) )[0];
		$this->assertTrue( ReservationRepository::setStatus( $first, 'cancelled' ) );
		[ $ids ] = $this->attempt( fn() => ReservationRepository::claim( $this->req( self::G, self::T, 133 ) ) );
		$this->assertIsArray( $ids, $ids instanceof \Throwable ? get_class( $ids ) : '' );
		$this->assertSame( array( 1 ), $this->seats_of( $ids[0] ) );
		$this->assertSame( array( 2 ), $this->seats_of( $second ) );
		$this->assert_join_within( $ids[0], 2, 'delik katılım' );
		$this->assert_invariant( 'delik yeniden' );
	}

	// ------------------------------------------------------------------ N koltuk, N+1 eşzamanlı

	/**
	 * K11: 3 koltuğa 4 gerçek paralel süreç. Engel: rakip bağlantı kaynak kilidini tutar; dört çocuk da GET_LOCK'ta
	 * bekleyince bırakır → aynı anda yarışırlar. Her çocuk okumalarını yapıp ilk INSERT'ten önce 600 ms durur (okuma ile
	 * yazma arasındaki pencere genişler; kaynak kilidi olmasa hepsi boş görüp koltuk 1'de çarpışırdı). Tam 3 başarı +
	 * 1 SlotTakenException; sahte dolu yok, slotiva_busy yok.
	 */
	public function test_n_seats_n_plus_one_concurrent_exactly_n_win(): void {
		$this->with_autocommit(
			function (): void {
				$n     = 3;
				$lock  = self::res_lock();
				$rival = $this->rival();
				$this->assertSame( '1', (string) mysqli_query( $rival, "SELECT GET_LOCK('{$lock}', 0)" )->fetch_row()[0] );
				$races = array();
				try {
					for ( $i = 0; $i <= $n; $i++ ) {
						$races[] = Race::start( 'claim', array( self::wire( $this->req( self::P, self::T, 201 + $i, 'held' ) ) ), 600, 'insert', $this->caps );
					}
					$this->wait_for_threads( "SELECT GET_LOCK('{$lock}'%", $n + 1, 20.0, $races );
				} finally {
					mysqli_query( $rival, "SELECT RELEASE_LOCK('{$lock}')" );
					mysqli_close( $rival );
				}
				$ok    = array();
				$taken = 0;
				$other = array();
				foreach ( $races as $race ) {
					$res = $this->child( $race );
					if ( $res['ok'] ) {
						$ok[] = (int) $res['result'][0];
					} elseif ( SlotTakenException::class === $res['error'] ) {
						++$taken;
					} else {
						$other[] = $res;
					}
				}
				$this->assertSame( array(), $other, 'beklenmeyen hata' );
				$this->assertCount( $n, $ok, 'tam N başarı (sahte dolu yok)' );
				$this->assertSame( 1, $taken, 'tam 1 SlotTakenException' );
				$seats = array();
				foreach ( $ok as $id ) {
					$seats = array_merge( $seats, $this->seats_of( $id ) );
					$this->assertSame( 3, ReservationRepository::find( $id )?->capacity );
					$this->assert_join_within( $id, 3, 'N+1 katılım' );
				}
				sort( $seats );
				$this->assertSame( array( 1, 2, 3 ), $seats );
				$this->assertSame( $n, $this->unit_count( self::T ) );
				$this->assert_invariant( 'N+1' );
			}
		);
	}

	// ------------------------------------------------------------------ Ham yazar (adlı kilitsiz rakip)

	/**
	 * Rakip bağlantı Q (kap. 1) 10:00 satırlarını ADLI KİLİT ALMADAN, COMMIT'siz yazar. B (çocuk süreç, üretim yolu) kaynak
	 * kilidini hemen alır, düz okumada rakibi görmez ve koltuk 1'i ekler → benzersiz anahtarda satır kilidi bekler.
	 * Rakip COMMIT → 1062 → SlotTakenException (busy değil); rakip ROLLBACK → B kazanır (sahte dolu yok).
	 *
	 * @param bool $commit Rakip COMMIT mi ROLLBACK mi eder.
	 * @return array{0:array<string,mixed>,1:int} [B (çocuk) sonucu, ham rezervasyon kimliği]
	 */
	private function raw_writer_case( bool $commit ): array {
		$out = array();
		$this->with_autocommit(
			function () use ( $commit, &$out ): void {
				$occ   = Schema::table( 'occupancy' );
				$res   = Schema::table( 'reservations' );
				$rival = $this->rival();
				mysqli_query( $rival, 'SET autocommit = 0' );
				$this->assertTrue( mysqli_query( $rival, "INSERT INTO {$res} (resource_id, product_id, starts_at, ends_at, status, payment_state, token, created_at, updated_at, capacity) VALUES (1, " . self::Q . ", '2026-11-17 10:00:00', '2026-11-17 10:30:00', 'confirmed', 'onsite', '" . md5( 'raw' ) . "', '2026-11-10 12:00:00', '2026-11-10 12:00:00', 1)" ) );
				$raw = (int) mysqli_insert_id( $rival );
				$this->assertTrue( mysqli_query( $rival, "INSERT INTO {$occ} (resource_id, unit_start, seat, reservation_id) VALUES (1, '2026-11-17 10:00:00', 1, {$raw}), (1, '2026-11-17 10:15:00', 1, {$raw})" ) );
				try {
					$race = Race::start( 'claim', array( self::wire( $this->req( self::Q, self::T, 301 ) ) ), 0, 'never', $this->caps );
					// B birim INSERT'inde rakibin satır kilidine takılana kadar bekle (gerçekten bekledi kanıtı).
					$this->wait_for_threads( "INSERT INTO `{$occ}`%", 1 );
					usleep( 300000 );
				} finally {
					mysqli_query( $rival, $commit ? 'COMMIT' : 'ROLLBACK' );
					mysqli_close( $rival );
				}
				$out = array( $this->child( $race ), $raw );
			}
		);
		return $out;
	}

	/** Ham yazar COMMIT eder → B reddedilir (1062, "dolu"); bekleme satır kilidinde oldu; slotiva_busy değil. */
	public function test_raw_writer_without_named_lock_commit_refuses(): void {
		[ $b ] = $this->raw_writer_case( true );
		$this->assertFalse( $b['ok'], (string) wp_json_encode( $b ) );
		$this->assertSame( SlotTakenException::class, $b['error'] );
		$this->assertGreaterThan( 250, $b['elapsed_ms'], 'B satır kilidinde beklemedi' );
		$this->assertSame( 0, $this->order_rows( 301 ) );
		$this->assert_invariant( 'ham yazar COMMIT' );
	}

	/** Ham yazar ROLLBACK eder → B kazanır (koltuk 1), sahte dolu yok. */
	public function test_raw_writer_without_named_lock_rollback_admits(): void {
		[ $b ] = $this->raw_writer_case( false );
		$this->assertTrue( $b['ok'], (string) wp_json_encode( $b ) );
		$this->assertGreaterThan( 250, $b['elapsed_ms'], 'B satır kilidinde beklemedi' );
		$this->assertSame( array( 1 ), $this->seats_of( (int) $b['result'][0] ) );
		$this->assert_join_within( (int) $b['result'][0], 1, 'ham yazar ROLLBACK' );
		$this->assert_invariant( 'ham yazar ROLLBACK' );
	}

	// ------------------------------------------------------------------ Taşıma (K4 kabul)

	/** Fable delta-3: `capacity=1` satır (kap. 1 iken alınmış) 1/3 dolu gruba taşınamaz. */
	public function test_move_capacity_one_row_into_one_third_group_refused(): void {
		$this->caps[ self::P ] = 1;
		$old                   = ReservationRepository::claim( $this->req( self::P, '2026-11-17 12:00:00', 401 ) )[0];
		$this->assertSame( 1, ReservationRepository::find( $old )?->capacity );
		$this->caps[ self::P ] = 3;
		$member                = ReservationRepository::claim( $this->req( self::P, self::T, 402 ) )[0];
		$this->assertSame( 3, ReservationRepository::find( $member )?->capacity );
		$this->assert_invariant( '1/3 grup' );

		[ $out ] = $this->attempt( fn() => ReservationRepository::reschedule( $old, self::utc( self::T ), true, false ) );
		$this->assertInstanceOf( SlotTakenException::class, $out );
		$this->assertSame( '2026-11-17 12:00:00', ReservationRepository::find( $old )?->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 1, $this->unit_count( self::T ) );
		$this->assert_invariant( 'delta-3' );
	}

	/** 2/3 dolu 10:00 grubundan bir üye 10:15'e kaydırılamaz (hedefte başka satır var → başlangıç gruba eşit olmalı). */
	public function test_move_member_of_two_thirds_group_to_quarter_past_refused(): void {
		$a = ReservationRepository::claim( $this->req( self::P, self::T, 411 ) )[0];
		$b = ReservationRepository::claim( $this->req( self::P, self::T, 412 ) )[0];
		$this->assert_join_within( $b, 3, '2/3' );
		$this->assert_invariant( '2/3 grup' );

		[ $out ] = $this->attempt( fn() => ReservationRepository::reschedule( $a, self::utc( '2026-11-17 10:15:00' ), true, false ) );
		$this->assertInstanceOf( SlotTakenException::class, $out );
		$this->assertSame( self::T, ReservationRepository::find( $a )?->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 2, $this->unit_count( self::T ) );
		$this->assert_invariant( '2/3 kaydırma' );
	}

	/** Fable delta-4: tek başına birebir randevu 10:00 → 10:15 (kendi birimleriyle örtüşür) kabul. */
	public function test_move_lone_one_to_one_booking_to_quarter_past_accepted(): void {
		$id      = ReservationRepository::claim( $this->req( self::Q, self::T, 421 ) )[0];
		[ $out ] = $this->attempt( fn() => ReservationRepository::reschedule( $id, self::utc( '2026-11-17 10:15:00' ), true, false ) );
		$this->assertTrue( $out, $out instanceof \Throwable ? get_class( $out ) : '' );
		$this->assertSame( '2026-11-17 10:15:00', ReservationRepository::find( $id )?->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 0, $this->unit_count( self::T ) );
		$this->assert_join_within( $id, 1, 'delta-4' );
		$this->assert_invariant( 'delta-4' );
	}

	/** Taşıma × yarış: A, Q'yu 11:00'de COMMIT'siz tutarken B tek başına birebir randevuyu 11:00'e taşır → bekler, ret. */
	public function test_move_into_slot_held_uncommitted_waits_then_refused(): void {
		$id    = ReservationRepository::claim( $this->req( self::Q, self::T, 431 ) )[0];
		[ $b ] = $this->hold_then( 'claim', array( self::wire( $this->req( self::Q, '2026-11-17 11:00:00', 432 ) ) ), fn() => ReservationRepository::reschedule( $id, self::utc( '2026-11-17 11:00:00' ), true, false ) );
		$this->assertInstanceOf( SlotTakenException::class, $b );
		$this->assertSame( self::T, ReservationRepository::find( $id )?->startsAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( 1, $this->unit_count( '2026-11-17 11:00:00' ) );
		$this->assert_invariant( 'taşıma yarışı' );
	}
}
