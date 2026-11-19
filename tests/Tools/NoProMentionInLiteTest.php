<?php
/**
 * Lite hiçbir yerde Pro'yu, lisansı ya da bir sınırlamayı pazarlamaz (Rentiva Lite portu).
 *
 * Kaynak taraması bilerek render testi değildir: yükseltme metni, kimsenin render etmeyi düşünmediği yüzeye geri
 * sızar. Dosya düzeyi kapılar `bin/check-no-pro-refs.php` ve `bin/check-crippleware.php`; bu test ayrıca metin
 * (copy) desenlerini kaynakta ve yayın belgesinde (readme.txt) arar.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Tools;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class NoProMentionInLiteTest extends TestCase {

	/**
	 * Kullanıcıya görünen ürün metnini taşıyan yayın belgeleri. readme.txt WordPress.org liste sayfasıdır.
	 *
	 * @var list<string>
	 */
	private const DOC_CANDIDATES = array( 'readme.txt' );

	/**
	 * Pro satan metin desenleri; bilinçli dar tutulur (etki alanı sözcükleri "paid", "license" tek başına yasak değil).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function upsell_pattern_provider(): array {
		return array(
			'available in Pro' => array( '/available in (the )?pro\b/i', 'tells the user a feature is available in Pro' ),
			'requires Pro'     => array( '/requires? (slotiva )?pro\b/i', 'tells the user a feature requires Pro' ),
			'upgrade to Pro'   => array( '/upgrade to (slotiva )?pro\b/i', 'asks the user to upgrade' ),
			'Pro version'      => array( '/\bpro version\b/i', 'refers the user to the Pro version' ),
			'unlock Pro'       => array( '/unlock (pro|premium)\b/i', 'promises unlocking Pro features' ),
			'get a license'    => array( '/get a licen[sc]e/i', 'is a purchase CTA' ),
			'buy a license'    => array( '/buy a licen[sc]e/i', 'is a purchase CTA' ),
			'purchase page'    => array( '#wpalemi\.com/slotiva#i', 'links the product purchase page' ),
			'license key'      => array( '/licen[sc]e[ _-]?key/i', 'asks for a license key' ),
			'paid plugin'      => array( '/paid plugin|not distributed on WordPress\.org/i', 'says the plugin is paid / not on WordPress.org' ),
			'TR pro surum'     => array( '/pro\s+sürüm/iu', 'the Pro edition (TR)' ),
			'TR lisans'        => array( '/\blisans/iu', 'license wording (TR)' ),
		);
	}

	/**
	 * Yayınlanan PHP kaynağında satış metni yok.
	 *
	 * @dataProvider upsell_pattern_provider
	 *
	 * @param string $pattern Yasak desen.
	 * @param string $why     Desenin neden yasak olduğu.
	 */
	public function test_no_upsell_copy_ships_in_lite( string $pattern, string $why ): void {
		$offenders = array();
		foreach ( $this->shipped_php_files() as $path ) {
			foreach ( $this->lines_of( $path ) as $index => $line ) {
				if ( 1 === preg_match( $pattern, $line ) ) {
					$offenders[] = sprintf( '%s:%d  %s', $this->relative( $path ), $index + 1, trim( $line ) );
				}
			}
		}
		$this->assertSame( array(), $offenders, sprintf( "Copy that %s must not ship in Lite:\n%s", $why, implode( "\n", $offenders ) ) );
	}

	/**
	 * Taramanın kendisini korur: hiçbir şey eşleştirmeyen bir glob tüm doğrulamaları boşuna geçirirdi.
	 */
	public function test_the_scan_actually_reads_the_plugin_source(): void {
		$this->assertGreaterThan( 50, count( iterator_to_array( $this->shipped_php_files() ) ), 'The scan found implausibly few PHP files.' );
	}

	/**
	 * Aynı kural yayın belgesine uygulanır; WordPress.org sayfasındaki yanlış iddia projenin en görünür olanıdır.
	 *
	 * @dataProvider upsell_pattern_provider
	 *
	 * @param string $pattern Yasak desen.
	 * @param string $why     Desenin neden yasak olduğu.
	 */
	public function test_no_upsell_copy_ships_in_the_docs( string $pattern, string $why ): void {
		$offenders = array();
		foreach ( $this->doc_files() as $path ) {
			foreach ( $this->lines_of( $path ) as $index => $line ) {
				if ( $this->is_wporg_metadata_header( $line ) ) {
					continue;
				}
				if ( 1 === preg_match( $pattern, $line ) ) {
					$offenders[] = sprintf( '%s:%d  %s', basename( $path ), $index + 1, trim( $line ) );
				}
			}
		}
		$this->assertSame( array(), $offenders, sprintf( "Copy that %s must not ship in Lite's docs:\n%s", $why, implode( "\n", $offenders ) ) );
	}

	/**
	 * Belge, var olmayan sınırları anlatmamalı (EN + TR).
	 *
	 * @dataProvider crippleware_claim_provider
	 *
	 * @param string $pattern Yasak desen.
	 * @param string $why     Desenin neden yasak olduğu.
	 */
	public function test_the_docs_do_not_advertise_limits_that_do_not_exist( string $pattern, string $why ): void {
		$offenders = array();
		foreach ( $this->doc_files() as $path ) {
			foreach ( $this->lines_of( $path ) as $index => $line ) {
				if ( 1 === preg_match( $pattern, $line ) ) {
					$offenders[] = sprintf( '%s:%d  %s', basename( $path ), $index + 1, trim( $line ) );
				}
			}
		}
		$this->assertSame( array(), $offenders, sprintf( "The docs claim %s, which this build does not enforce:\n%s", $why, implode( "\n", $offenders ) ) );
	}

	/**
	 * Var olmayan sınır iddialarının desenleri.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function crippleware_claim_provider(): array {
		return array(
			'Lite vs Pro table'  => array( '#lite\s*(vs\.?|ve|and|&|/)\s*pro#i', 'an edition comparison table' ),
			'maximum N things'   => array( '/\bmaximum\s+\d+\s+\w/i', 'a hard cap on some quantity' ),
			'unlimited upsell'   => array( '/unlimited\s+(in|with)\s+pro/i', 'that a cap lifts in Pro' ),
			'TR maksimum'        => array( '/\bmaksimum\s+\d+/i', 'a hard cap (TR)' ),
			'TR en fazla'        => array( '/\ben fazla\s+\d+/i', 'a hard cap (TR)' ),
			'TR sinirsiz upsell' => array( '/pro.{0,10}(da|de)\s+sınırsız/iu', 'that a cap lifts in Pro (TR)' ),
		);
	}

	/**
	 * Belge taraması gerçekten dosya okur.
	 */
	public function test_the_scan_actually_reads_the_docs(): void {
		$files = iterator_to_array( $this->doc_files(), false );
		$this->assertContains( 'readme.txt', array_map( 'basename', $files ), 'readme.txt is the WordPress.org listing page and must always be scanned.' );
		foreach ( $files as $path ) {
			$this->assertNotSame( '', trim( (string) file_get_contents( $path ) ), basename( $path ) . ' is empty.' );
		}
	}

	/**
	 * Dosyanın satırları.
	 *
	 * @param string $path Dosya yolu.
	 * @return list<string>
	 */
	private function lines_of( string $path ): array {
		$lines = preg_split( '/\R/', (string) file_get_contents( $path ) );
		return false === $lines ? array() : $lines;
	}

	/**
	 * `Plugin URI` / `Author URI` / `License URI` zorunlu üst bilgilerdir; satış CTA'sı değildir.
	 *
	 * @param string $line Belge satırı.
	 */
	private function is_wporg_metadata_header( string $line ): bool {
		return 1 === preg_match( '/^\s*(Plugin URI|Author URI|License URI|Donate link)\s*:/i', $line );
	}

	/**
	 * Taranacak belge dosyaları.
	 *
	 * @return iterable<int, string>
	 */
	private function doc_files(): iterable {
		foreach ( self::DOC_CANDIDATES as $name ) {
			$path = dirname( __DIR__, 2 ) . '/' . $name;
			if ( is_file( $path ) ) {
				yield $path;
			}
		}
	}

	/**
	 * Yayınlanan PHP dosyaları (src, templates).
	 *
	 * @return iterable<int, string>
	 */
	private function shipped_php_files(): iterable {
		foreach ( array( 'src', 'templates' ) as $dir ) {
			$root = dirname( __DIR__, 2 ) . '/' . $dir;
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() ) {
					yield $file->getPathname();
				}
			}
		}
	}

	private function relative( string $path ): string {
		return str_replace( array( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR, '\\' ), array( '', '/' ), $path );
	}
}
