<?php
/**
 * Task 12: koltuk farkında müsaitlik (kural 3/4/8, K2, K6, K8, K9) — REST yanıtı, `slotiva_availability_slot` süzgeci,
 * `slotiva_flush_availability` eylemi ve yönetici ızgarası; kabul (`SeatPolicy::place`) ile aynı karar (R18).
 *
 * Takvim: hafta içi 10:00–12:00 İstanbul, süre 30 dk, ara 0; Salı 2026-11-17 10:00 yerel = 07:00Z.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Availability;

use MHM\Slotiva\Availability\RestController;
use MHM\Slotiva\Availability\SlotCalculator;
use MHM\Slotiva\Availability\Units;
use MHM\Slotiva\Db\Schema;
use MHM\Slotiva\Product\BookableProduct;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Reservation\SeatPolicy;
use MHM\Slotiva\Reservation\SlotTakenException;
use MHM\Slotiva\Schedule\Rules;
use MHM\Slotiva\Schedule\WeeklySchedule;
use MHM\Slotiva\Support\Clock;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class SeatsAvailabilityTest extends SlotivaTestCase {

	private const DAY = '2026-11-17';

	/**
	 * Ürün başına süzgeç kapasitesi (yoksa 1).
	 *
	 * @var array<int,int>
	 */
	private array $caps = array();

	/**
	 * Süzgece gelen çağrılar: [slot, seatsLeft, capacity, productId].
	 *
	 * @var list<array{0:array<string,mixed>,1:int,2:int,3:int}>
	 */
	private array $calls = array();

	/**
	 * Test öncesi saat dilimi seçenekleri.
	 *
	 * @var array<string,mixed>
	 */
	private array $tz_backup = array();

	public function set_up(): void {
		parent::set_up();
		$this->tz_backup = array(
			'timezone_string' => get_option( 'timezone_string' ),
			'gmt_offset'      => get_option( 'gmt_offset' ),
		);
		update_option( 'timezone_string', 'Europe/Istanbul' );
		update_option( 'gmt_offset', 0 );
		Clock::freeze( new \DateTimeImmutable( '2026-11-16T06:00:00Z' ) );
		$day = array(
			array(
				'from' => '10:00',
				'to'   => '12:00',
			),
		);
		WeeklySchedule::save(
			array(
				0 => $day,
				1 => $day,
				2 => $day,
				3 => $day,
				4 => $day,
			)
		);
		Rules::save(
			array(
				'leadHours'   => 24,
				'horizonDays' => 90,
				'bufferMin'   => 0,
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 254 );
		$this->caps             = array();
		$this->calls            = array();
		add_filter( 'slotiva_product_capacity', array( $this, 'cap_filter' ), 10, 2 );

		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- çekirdek kancası.
	}

	public function tear_down(): void {
		remove_filter( 'slotiva_product_capacity', array( $this, 'cap_filter' ), 10 );
		remove_filter( 'slotiva_availability_slot', array( $this, 'record_slot' ), 10 );
		remove_filter( 'slotiva_availability_slot', array( $this, 'add_seats_field' ), 10 );
		global $wp_rest_server; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- çekirdek küresel.
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
		unset( $_SERVER['REMOTE_ADDR'] );
		update_option( 'timezone_string', $this->tz_backup['timezone_string'] );
		update_option( 'gmt_offset', $this->tz_backup['gmt_offset'] );
		parent::tear_down();
	}

	/**
	 * Test kapasite süzgeci.
	 *
	 * @param mixed $value      Gelen değer.
	 * @param mixed $product_id Ürün.
	 * @return mixed
	 */
	public function cap_filter( $value, $product_id ) {
		return $this->caps[ (int) $product_id ] ?? $value;
	}

	/**
	 * Dilim süzgeci: çağrıyı kaydeder, dilimi değiştirmez.
	 *
	 * @param array<string,mixed> $slot       Dilim.
	 * @param int                 $seats_left Kalan yer.
	 * @param int                 $capacity   Kapasite.
	 * @param int                 $product_id Ürün.
	 * @return array<string,mixed>
	 */
	public function record_slot( $slot, $seats_left, $capacity, $product_id ) {
		$this->calls[] = array( $slot, $seats_left, $capacity, $product_id );
		return $slot;
	}

	/**
	 * Dilim süzgeci: Pro'nun yapacağı gibi alan ekler.
	 *
	 * @param array<string,mixed> $slot       Dilim.
	 * @param int                 $seats_left Kalan yer.
	 * @return array<string,mixed>
	 */
	public function add_seats_field( $slot, $seats_left ) {
		$slot['seatsLeft'] = $seats_left;
		return $slot;
	}

	private function product( int $duration = 30 ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Grup dersi' );
		$p->set_regular_price( '100' );
		$p->set_status( 'publish' );
		$p->update_meta_data( '_slotiva_enabled', 'yes' );
		$p->update_meta_data( '_slotiva_duration', (string) $duration );
		$p->update_meta_data( '_slotiva_horizon_days', '90' );
		return $p->save();
	}

	/**
	 * Onaylı tek dilim alır (kabul `SeatPolicy`'den geçer).
	 *
	 * @param int    $pid      Ürün.
	 * @param string $start    UTC başlangıç.
	 * @param int    $duration Süre (dk).
	 * @param int    $buffer   Ara (dk).
	 * @return int Rezervasyon.
	 */
	private function book( int $pid, string $start, int $duration = 30, int $buffer = 0 ): int {
		return ReservationRepository::claim(
			array(
				'product_id' => $pid,
				'slots'      => array( new \DateTimeImmutable( $start ) ),
				'duration'   => $duration,
				'buffer'     => $buffer,
				'status'     => 'confirmed',
			)
		)[0];
	}

	/**
	 * Müşteri REST yanıtı.
	 *
	 * @param int $pid Ürün.
	 * @return array<string,mixed>
	 */
	private function get( int $pid ): array {
		$req = new \WP_REST_Request( 'GET', '/slotiva/v1/availability' );
		$req->set_query_params(
			array(
				'product' => $pid,
				'day'     => self::DAY,
			)
		);
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		return (array) $res->get_data();
	}

	/**
	 * Süzgece gelen label → seatsLeft.
	 *
	 * @return array<string,int>
	 */
	private function seats_by_label(): array {
		$out = array();
		foreach ( $this->calls as $c ) {
			$out[ (string) $c[0]['label'] ] = $c[1];
		}
		return $out;
	}

	public function test_group_slot_stays_available_with_seats_left_until_full(): void {
		$pid                = $this->product();
		$this->caps[ $pid ] = 3;
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		add_filter( 'slotiva_availability_slot', array( $this, 'record_slot' ), 10, 4 );

		$data = $this->get( $pid );
		$this->assertSame( array( '10:00', '10:30', '11:00', '11:30' ), array_column( $data['slots'], 'label' ), '2/3 dolu grup saati sunulur' );
		$this->assertSame( 4, $data['days'][0]['free'] );
		$this->assertSame(
			array(
				'10:00' => 1,
				'10:30' => 3,
				'11:00' => 3,
				'11:30' => 3,
			),
			$this->seats_by_label()
		);
		foreach ( $this->calls as $c ) {
			$this->assertSame( 3, $c[2], 'kapasite' );
			$this->assertSame( $pid, $c[3], 'ürün' );
			$this->assertSame( array( 'start', 'label' ), array_keys( $c[0] ), 'süzgece giden dilim 1.0.3 biçiminde' );
		}

		$this->book( $pid, '2026-11-17T07:00:00Z' ); // 3/3 — durum olayı sürümü artırır.
		$this->calls = array();
		$full        = $this->get( $pid );
		$this->assertSame( array( '10:30', '11:00', '11:30' ), array_column( $full['slots'], 'label' ), 'dolu grup saati çıkar' );
		$this->assertSame( 3, $full['days'][0]['free'] );
		$this->assertArrayNotHasKey( '10:00', $this->seats_by_label() );
	}

	public function test_lite_adds_no_slot_field_without_filter_and_filter_can_add(): void {
		$pid                = $this->product();
		$this->caps[ $pid ] = 3;
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		foreach ( $this->get( $pid )['slots'] as $slot ) {
			$this->assertSame( array( 'start', 'label' ), array_keys( $slot ), 'K8: Lite alan eklemez' );
		}

		add_filter( 'slotiva_availability_slot', array( $this, 'add_seats_field' ), 10, 2 );
		RestController::bump_version();
		$slots = $this->get( $pid )['slots'];
		$this->assertSame( 2, $slots[0]['seatsLeft'] );
		$this->assertSame( 3, $slots[1]['seatsLeft'] );
	}

	public function test_partial_overlap_with_group_is_not_offered(): void {
		Rules::save( array( 'bufferMin' => 15 ) );
		$pid                = $this->product();
		$this->caps[ $pid ] = 3;
		$this->book( $pid, '2026-11-17T07:00:00Z', 30, 15 ); // 10:00 grubu, birimler 10:00–10:45 (ara dahil).
		$labels = array_column( $this->get( $pid )['slots'], 'label' );
		$this->assertContains( '10:00', $labels, 'aynı başlangıç katılabilir' );
		$this->assertNotContains( '10:30', $labels, 'kural 4: grupla kısmen çakışan başlangıç dolu' );
		$this->assertSame( array( '10:00', '11:00', '11:30' ), $labels );
	}

	public function test_other_product_occupancy_blocks_group_product(): void {
		$group                = $this->product();
		$solo                 = $this->product();
		$this->caps[ $group ] = 3;
		$this->book( $solo, '2026-11-17T07:00:00Z' );
		$this->assertSame( array( '10:30', '11:00', '11:30' ), array_column( $this->get( $group )['slots'], 'label' ) );
	}

	public function test_flush_action_bumps_cache_version(): void {
		$pid    = $this->product();
		$before = (int) get_option( RestController::VERSION_OPTION, 0 );
		do_action( 'slotiva_flush_availability' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- önek slotiva_.
		$this->assertSame( $before + 1, (int) get_option( RestController::VERSION_OPTION, 0 ) );

		// Kapasite değişince (Pro ürün sekmesi) eylem önbelleği eskitir; eylemsiz yanıt 60 sn bayat kalırdı.
		// 3 → 2 düşürme yeni katılımı hemen kapatır (K4: min(3, 2) = 2 dolu).
		$this->caps[ $pid ] = 3;
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$this->assertContains( '10:00', array_column( $this->get( $pid )['slots'], 'label' ) );
		$this->caps[ $pid ] = 2;
		$this->assertContains( '10:00', array_column( $this->get( $pid )['slots'], 'label' ), 'önbellekten (bayat)' );
		do_action( 'slotiva_flush_availability' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- önek slotiva_.
		$this->assertNotContains( '10:00', array_column( $this->get( $pid )['slots'], 'label' ) );
	}

	public function test_availability_reads_occupancy_in_one_query(): void {
		$pid                = $this->product();
		$this->caps[ $pid ] = 3;
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$this->book( $pid, '2026-11-17T07:30:00Z' );
		$bp = BookableProduct::from( wc_get_product( $pid ) );
		$this->assertNotNull( $bp );
		$this->start_counting();
		$days = SlotCalculator::days( $bp, '2026-11-17', '2026-12-10' );
		$n    = $this->stop_counting( Schema::table( 'occupancy' ) );
		$this->assertSame( 4, $days[0]['free'], '1/3 dolu gruplar yeri olan saat sayılır' );
		$this->assertSame( 1, $n, 'K9: 24 günlük aralık için doluluk tek sorguda (N+1 yok)' );
	}

	/**
	 * Sorgu sayacı.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/** Sorgu sayımını başlatır. */
	private function start_counting(): void {
		$this->queries = array();
		add_filter( 'query', array( $this, 'count_query' ) );
	}

	/**
	 * Sayımı bitirir; tabloya değen sorgu sayısı.
	 *
	 * @param string $table Tablo.
	 */
	private function stop_counting( string $table ): int {
		remove_filter( 'query', array( $this, 'count_query' ) );
		return count( array_filter( $this->queries, static fn( string $q ): bool => str_contains( $q, $table ) ) );
	}

	/**
	 * Sorgu kaydı.
	 *
	 * @param string $q Sorgu.
	 */
	public function count_query( $q ) {
		$this->queries[] = (string) $q;
		return $q;
	}

	public function test_admin_grid_offers_group_start_with_seats_and_not_overlaps(): void {
		$pid                = $this->product();
		$this->caps[ $pid ] = 3;
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$labels = array_column( SlotCalculator::adminSlots( 30, 0, self::DAY, 0, $pid ), 'label' );
		$this->assertSame( array( '10:00', '10:30', '10:45', '11:00', '11:15', '11:30' ), $labels, '10:15 grupla kısmen çakışır' );

		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$this->assertSame( array( '10:30', '10:45', '11:00', '11:15', '11:30' ), array_column( SlotCalculator::adminSlots( 30, 0, self::DAY, 0, $pid ), 'label' ), 'dolu grup çıkar' );

		// Kapasite 1 (süzgeç yok): grup ürünü değilken bugünkü gibi.
		$solo = $this->product();
		$this->book( $solo, '2026-11-18T07:00:00Z' );
		$this->assertSame( array( '10:30', '10:45', '11:00', '11:15', '11:30' ), array_column( SlotCalculator::adminSlots( 30, 0, '2026-11-18', 0, $solo ), 'label' ) );
	}

	public function test_admin_grid_does_not_offer_join_off_current_grid(): void {
		$pid                = $this->product();
		$this->caps[ $pid ] = 3;
		$this->book( $pid, '2026-11-17T07:15:00Z' ); // Yönetici 10:15'e eklemiş (ızgara dışı, boş saat).
		$labels = array_column( SlotCalculator::adminSlots( 30, 0, self::DAY, 0, $pid ), 'label' );
		$this->assertNotContains( '10:15', $labels, 'K6: ızgara dışı gruba yeni katılım yok' );
		$this->assertSame( array( '10:45', '11:00', '11:15', '11:30' ), $labels );
	}

	public function test_reschedule_grid_for_group_offers_rule_4_starts_with_seats(): void {
		$pid                = $this->product();
		$this->caps[ $pid ] = 3;
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$this->book( $pid, '2026-11-17T07:00:00Z' );
		$mover = $this->book( $pid, '2026-11-17T08:00:00Z' ); // 11:00, capacity 3 damgalı.
		$r     = ReservationRepository::find( $mover );
		$this->assertNotNull( $r );
		$this->assertSame( 3, $r->capacity );
		$labels = array_column( SlotCalculator::adminSlots( 30, 0, self::DAY, $mover, $pid, $r->capacity ), 'label' );
		$this->assertSame( array( '10:00', '10:30', '10:45', '11:00', '11:15', '11:30' ), $labels );

		// Birebir (capacity 1) rezervasyon yalnız BOŞ saate taşınır (K4).
		$this->caps[ $pid ] = 1;
		$single             = $this->book( $pid, '2026-11-17T08:30:00Z' );
		$this->caps[ $pid ] = 3;
		$labels             = array_column( SlotCalculator::adminSlots( 30, 0, self::DAY, $single, $pid, 1 ), 'label' );
		$this->assertNotContains( '10:00', $labels );
	}

	/**
	 * R18: yönetici ızgarasının sunduğu her başlangıç kabulden geçer, sunmadığı her başlangıç reddedilir.
	 */
	public function test_admin_grid_matches_admission_decision(): void {
		Rules::save( array( 'bufferMin' => 15 ) );
		$group                = $this->product();
		$solo                 = $this->product();
		$this->caps[ $group ] = 3;
		$this->book( $group, '2026-11-17T07:00:00Z', 30, 15 );
		$this->book( $group, '2026-11-17T07:00:00Z', 30, 15 );
		$this->book( $solo, '2026-11-17T08:15:00Z', 30, 15 );
		$offered = array_column( SlotCalculator::adminSlots( 30, 15, self::DAY, 0, $group ), 'start' );
		$utc     = new \DateTimeZone( 'UTC' );
		for ( $m = 0; $m <= 90; $m += 15 ) {
			$start = ( new \DateTimeImmutable( '2026-11-17 07:00:00', $utc ) )->modify( "+{$m} minutes" );
			try {
				SeatPolicy::place( Units::RESOURCE_ID, $group, $start, Units::span( $start, 30, 15 ), 3 );
				$ok = true;
			} catch ( SlotTakenException $e ) {
				$ok = false;
			}
			$this->assertSame( $ok, in_array( $start->format( 'Y-m-d\TH:i:s\Z' ), $offered, true ), $start->format( 'H:i' ) . 'Z' );
		}
	}
}
