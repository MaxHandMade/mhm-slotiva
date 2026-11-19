<?php
/**
 * Readme.txt, WordPress.org'un gerçekten gösterdiği sınırlara sığmalı (Rentiva ReadmeChangelogLengthTest uyarlaması).
 *
 * WordPress.org bir bölümü 5.000 karakterde sessizce keser; kısa açıklamayı 150'de keser; 5'ten fazla etiketi atar.
 * Hiçbiri hata vermez, yalnız sayfa kesik görünür. Bu yüzden sınırlar testtir, uyarı değil.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Tools;

use PHPUnit\Framework\TestCase;

class ReadmeLimitsTest extends TestCase {

	private const SECTION_LIMIT = 5000;

	/**
	 * Readme.txt metni.
	 *
	 * @var string
	 */
	private string $readme = '';

	protected function setUp(): void {
		parent::setUp();
		$this->readme = (string) file_get_contents( dirname( __DIR__, 2 ) . '/readme.txt' );
		$this->assertNotSame( '', $this->readme, 'readme.txt is missing or empty.' );
	}

	/**
	 * Başlık alanı (örn. `Stable tag`).
	 *
	 * @param string $name Alan adı.
	 */
	private function header( string $name ): string {
		$this->assertSame( 1, preg_match( '/^' . preg_quote( $name, '/' ) . ':[ \t]*(.+)$/mi', $this->readme, $m ), "readme.txt has no '{$name}' header." );
		return trim( $m[1] );
	}

	/**
	 * `== Başlık ==` bölümünün metni (sonraki `== ... ==` ya da dosya sonuna kadar).
	 *
	 * @param string $title Bölüm adı.
	 */
	private function section( string $title ): string {
		$start = strpos( $this->readme, '== ' . $title . ' ==' );
		$this->assertNotFalse( $start, "readme.txt has no '{$title}' section." );
		$next = preg_match( '/^== [^=]+ ==\s*$/m', $this->readme, $m, PREG_OFFSET_CAPTURE, $start + 5 ) ? $m[0][1] : strlen( $this->readme );
		return substr( $this->readme, $start, $next - $start );
	}

	public function test_every_section_fits_what_wordpress_org_renders(): void {
		preg_match_all( '/^== ([^=]+) ==\s*$/m', $this->readme, $m );
		$this->assertContains( 'Changelog', $m[1] );
		foreach ( $m[1] as $title ) {
			$this->assertLessThan( self::SECTION_LIMIT, strlen( $this->section( trim( $title ) ) ), "Section '{$title}' is over " . self::SECTION_LIMIT . ' characters; WordPress.org truncates it.' );
		}
	}

	public function test_the_changelog_names_the_version_being_shipped(): void {
		$tag = $this->header( 'Stable tag' );
		$this->assertStringContainsString( '= ' . $tag . ' ', $this->section( 'Changelog' ), 'The changelog has no entry for the Stable tag.' );
	}

	public function test_the_short_description_is_at_most_150_characters(): void {
		// Kısa açıklama = başlık bloğundan sonraki ilk düz paragraf (== Description == öncesi).
		$head = substr( $this->readme, 0, (int) strpos( $this->readme, '== Description ==' ) );
		$this->assertSame( 1, preg_match( '/^License URI:.*\R\R(.+?)\R\R/ms', $head . "\n\n", $m ), 'Cannot find the short description.' );
		$short = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
		$this->assertNotSame( '', $short );
		$this->assertLessThanOrEqual( 150, strlen( $short ), 'Short description is ' . strlen( $short ) . ' characters.' );
	}

	public function test_at_most_five_tags(): void {
		$tags = array_filter( array_map( 'trim', explode( ',', $this->header( 'Tags' ) ) ) );
		$this->assertGreaterThanOrEqual( 1, count( $tags ) );
		$this->assertLessThanOrEqual( 5, count( $tags ), 'WordPress.org ignores tags after the fifth.' );
	}

	public function test_tested_up_to_is_a_major_minor_and_stable_tag_is_a_plain_version(): void {
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $this->header( 'Tested up to' ) );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $this->header( 'Stable tag' ) );
	}

	public function test_required_wordpress_org_sections_exist(): void {
		foreach ( array( 'Description', 'Installation', 'Changelog', 'External services', 'Source code' ) as $title ) {
			$this->assertNotSame( '', $this->section( $title ) );
		}
	}

	public function test_external_services_names_the_google_link_and_its_policies(): void {
		$text = $this->section( 'External services' );
		$this->assertStringContainsString( 'https://policies.google.com/terms', $text );
		$this->assertStringContainsString( 'https://policies.google.com/privacy', $text );
		$this->assertStringContainsString( 'calendar.google.com', $text );
	}

	public function test_source_code_gives_the_repo_and_the_build_steps(): void {
		$text = $this->section( 'Source code' );
		$this->assertStringContainsString( 'https://github.com/MaxHandMade/mhm-slotiva', $text );
		$this->assertStringContainsString( 'composer install --no-dev', $text );
		$this->assertStringContainsString( 'npm ci', $text );
		$this->assertStringContainsString( 'npm run build', $text );
	}

	public function test_every_screenshot_file_has_exactly_one_caption(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/.wordpress-org/screenshot-*.png' );
		$this->assertNotEmpty( $files, 'No .wordpress-org/screenshot-N.png files.' );
		$nums = array();
		foreach ( $files as $file ) {
			$this->assertSame( 1, preg_match( '/screenshot-(\d+)\.png$/', $file, $m ), 'Unexpected screenshot name: ' . basename( $file ) );
			$nums[] = (int) $m[1];
		}
		sort( $nums );
		$this->assertSame( range( 1, count( $nums ) ), $nums, 'Screenshot files must be numbered 1..N without gaps.' );

		preg_match_all( '/^(\d+)\. \S/m', $this->section( 'Screenshots' ), $caps );
		$this->assertSame( $nums, array_map( 'intval', $caps[1] ), 'The Screenshots captions must match the screenshot files one to one.' );
	}

	public function test_it_never_overpromises_payment_methods(): void {
		$this->assertStringContainsString( 'WooCommerce Checkout block', $this->readme );
		$this->assertDoesNotMatchRegularExpression( '/every (payment )?gateway|all (payment )?gateways|any gateway/i', $this->readme );
	}
}
