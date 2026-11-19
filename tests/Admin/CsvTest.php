<?php
/**
 * CSV dışa aktarma (spec §8): UTF-8 BOM, `;` ayraç, sabit sütunlar, formül enjeksiyonu koruması.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Admin;

use MHM\Slotiva\Admin\Csv;
use MHM\Slotiva\Reservation\ReservationRepository;
use MHM\Slotiva\Tests\Support\CheckoutFixtures;
use MHM\Slotiva\Tests\Support\SlotivaTestCase;

class CsvTest extends SlotivaTestCase {

	use CheckoutFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_checkout();
	}

	public function tear_down(): void {
		$this->tear_down_checkout();
		parent::tear_down();
	}

	/**
	 * Elle onaylı randevu.
	 *
	 * @param int                  $pid      Ürün.
	 * @param string               $slot     Dilim.
	 * @param array<string,string> $customer Müşteri.
	 * @param string               $name     Ürün adı anlık kopyası.
	 */
	private function booking( int $pid, string $slot, array $customer, string $name = 'Danışmanlık' ): int {
		return ReservationRepository::claim(
			array(
				'product_id'    => $pid,
				'product_name'  => $name,
				'slots'         => array( new \DateTimeImmutable( $slot ) ),
				'duration'      => 30,
				'status'        => 'confirmed',
				'payment_state' => 'manual',
				'customer'      => $customer,
				'notify'        => false,
			)
		)[0];
	}

	/**
	 * BOM'suz satırlar, her biri hücre listesi.
	 *
	 * @param string $csv CSV.
	 * @return list<list<string>>
	 */
	private static function parse( string $csv ): array {
		$body = substr( $csv, 3 );
		$h    = fopen( 'php://memory', 'r+' );
		fwrite( $h, $body );
		rewind( $h );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $h, 0, ';', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- okuma döngüsü.
			$rows[] = array_map( 'strval', $row );
		}
		fclose( $h );
		return $rows;
	}

	public function test_csv_bom_semicolon_and_formula_guard(): void {
		$pid = $this->bookable();
		$this->booking(
			$pid,
			'2026-11-17T07:00:00Z',
			array(
				'name'  => '=SUM(1)',
				'phone' => '+905551112233',
				'email' => '@evil.test',
			),
			'-Hizmet;"tırnak"'
		);
		$this->booking(
			$pid,
			'2026-11-17T08:00:00Z',
			array(
				'name'  => "\tTab",
				'phone' => "\rCR",
				'email' => 'ok@example.test',
			)
		);

		$csv = Csv::build( array() );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertStringContainsString( "\r\n", $csv );
		$rows = self::parse( $csv );
		$this->assertCount( 3, $rows );
		$this->assertSame( array( 'Date', 'Time', 'Service', 'Name', 'Phone', 'Email', 'Appointment status', 'Payment status', 'Order no' ), $rows[0] );

		$first = $rows[1];
		$this->assertSame( '2026-11-17', $first[0] );
		$this->assertSame( '10:00', $first[1] );
		$this->assertSame( "'-Hizmet;\"tırnak\"", $first[2], 'ayraç ve tırnak içeren hücre tırnaklanır; - önekli' );
		$this->assertSame( "'=SUM(1)", $first[3] );
		$this->assertSame( "'+905551112233", $first[4] );
		$this->assertSame( "'@evil.test", $first[5] );
		$this->assertSame( 'Confirmed', $first[6] );
		$this->assertSame( 'Manual', $first[7] );
		$this->assertSame( '', $first[8] );

		$second = $rows[2];
		$this->assertSame( "'\tTab", $second[3] );
		$this->assertSame( "'\rCR", $second[4] );
		$this->assertSame( 'ok@example.test', $second[5] );

		// Ham dizgide de: formül hücresi hiçbir yerde tek başına `=` ile başlamaz.
		$this->assertStringContainsString( ";'=SUM(1);", $csv );
		$this->assertDoesNotMatchRegularExpression( '/(^|;)=SUM/m', $csv );
	}

	public function test_csv_turkish_chars_utf8(): void {
		$pid = $this->bookable();
		$this->booking(
			$pid,
			'2026-11-17T07:00:00Z',
			array(
				'name'  => 'Çağrı Şişli Öztürk',
				'phone' => '05551112233',
				'email' => 'ığüşöç@example.test',
			),
			'Seramik atölyesi — İleri düzey'
		);
		$csv = Csv::build( array() );
		$this->assertTrue( mb_check_encoding( $csv, 'UTF-8' ) );
		$this->assertStringContainsString( 'Çağrı Şişli Öztürk', $csv );
		$this->assertStringContainsString( 'Seramik atölyesi — İleri düzey', $csv );
		$this->assertStringContainsString( 'ığüşöç@example.test', $csv );
		$this->assertSame( 1, substr_count( $csv, "\xEF\xBB\xBF" ), 'tek BOM, yalnız başta' );
	}

	public function test_csv_filters_order_and_excludes_superseded(): void {
		$pid = $this->bookable();
		$b   = $this->booking( $pid, '2026-11-18T07:00:00Z', array( 'name' => 'B' ) );
		$a   = $this->booking( $pid, '2026-11-17T07:00:00Z', array( 'name' => 'A' ) );
		$c   = $this->booking( $pid, '2026-11-19T07:00:00Z', array( 'name' => 'C' ) );
		ReservationRepository::release( $c, 'superseded' );
		$this->assertNotSame( $a, $b );

		$rows = self::parse( Csv::build( array() ) );
		$this->assertSame( array( 'A', 'B' ), array_column( array_slice( $rows, 1 ), 3 ) );

		$only = self::parse(
			Csv::build(
				array(
					'from' => '2026-11-18',
					'to'   => '2026-11-18',
				)
			)
		);
		$this->assertSame( array( 'B' ), array_column( array_slice( $only, 1 ), 3 ) );
	}

	public function test_csv_order_number_and_payment_label(): void {
		$pid   = $this->bookable();
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		ReservationRepository::claim(
			array(
				'product_id'    => $pid,
				'product_name'  => 'Danışmanlık',
				'order_id'      => $order->get_id(),
				'slots'         => array( new \DateTimeImmutable( '2026-11-17T07:00:00Z' ) ),
				'duration'      => 30,
				'status'        => 'confirmed',
				'payment_state' => 'on_site',
			)
		);
		$rows = self::parse( Csv::build( array() ) );
		$this->assertSame( 'Pay on site', $rows[1][7] );
		$this->assertSame( $order->get_order_number(), $rows[1][8] );
	}

	public function test_csv_formula_guard_after_leading_whitespace_and_fullwidth(): void {
		$this->assertSame( "' =1+1", Csv::cell( ' =1+1' ) );
		$this->assertSame( "\"'\n=1+1\"", Csv::cell( "\n=1+1" ) );
		$this->assertSame( "'\u{00A0}@x", Csv::cell( "\u{00A0}@x" ) );
		$this->assertSame( "'\x01-2", Csv::cell( "\x01-2" ) );
		foreach ( array( '＝1+1', '＋90', '－5', '＠x' ) as $v ) {
			$this->assertSame( "'" . $v, Csv::cell( $v ), $v );
		}
		$this->assertSame( '  hello', Csv::cell( '  hello' ) );
		$this->assertSame( 'a=b', Csv::cell( 'a=b' ) );
		$this->assertSame( '', Csv::cell( '' ) );
	}

	/**
	 * (Final review F5 / m-2) Türkçe Excel'de baştaki 0 düşmesin: yalnız rakamdan oluşan ve 0 ile başlayan (ya da 15
	 * haneyi aşan) telefon hücresi `="…"` metin formülü olarak yazılır; diğer telefonlar ve diğer sütunlar değişmez.
	 */
	public function test_csv_phone_keeps_leading_zero_as_text(): void {
		$pid = $this->bookable();
		$this->booking(
			$pid,
			'2026-11-17T07:00:00Z',
			array(
				'name'  => '05551112233',
				'phone' => '05551112233',
				'email' => 'a@example.test',
			)
		);
		$this->booking(
			$pid,
			'2026-11-17T08:00:00Z',
			array(
				'name'  => 'B',
				'phone' => '1234567890123456',
				'email' => 'b@example.test',
			)
		);
		$this->booking(
			$pid,
			'2026-11-18T07:00:00Z',
			array(
				'name'  => 'C',
				'phone' => '5551112233',
				'email' => 'c@example.test',
			)
		);
		$this->booking(
			$pid,
			'2026-11-18T08:00:00Z',
			array(
				'name'  => 'D',
				'phone' => '0555 111 22 33',
				'email' => 'd@example.test',
			)
		);

		$csv  = Csv::build( array() );
		$rows = self::parse( $csv );
		$this->assertSame( '="05551112233"', $rows[1][4], 'baştaki 0 metin olarak korunur' );
		$this->assertSame( '05551112233', $rows[1][3], 'yalnız telefon sütunu' );
		$this->assertSame( '="1234567890123456"', $rows[2][4], '15 haneyi aşan sayı da metin' );
		$this->assertSame( '5551112233', $rows[3][4], '0 ile başlamayan kısa sayı olduğu gibi' );
		$this->assertSame( '0555 111 22 33', $rows[4][4], 'boşluklu değer zaten metin' );
		$this->assertStringContainsString( ';"=""05551112233""";', $csv, 'ham CSV: tırnaklı hücre' );
	}
}
