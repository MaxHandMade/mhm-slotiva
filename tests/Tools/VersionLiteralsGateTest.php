<?php
/**
 * `bin/check-version-literals.php`: altı yerin hepsi aynı sürümü söylemeli; biri değişince kapı başarısız olmalı.
 * Mutasyon geçici bir kopya üzerinde yapılır (gerçek ağaca dokunulmaz); "temiz" çıktı tek başına bir şey kanıtlamaz.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Tools;

use PHPUnit\Framework\TestCase;

class VersionLiteralsGateTest extends TestCase {

	private const FILES = array(
		'mhm-slotiva.php',
		'readme.txt',
		'languages/mhm-slotiva-tr_TR.po',
		'package.json',
		'composer.json',
	);

	/**
	 * Geçici kopya dizini.
	 *
	 * @var string
	 */
	private string $tmp = '';

	protected function tearDown(): void {
		if ( '' !== $this->tmp && is_dir( $this->tmp ) ) {
			foreach ( self::FILES as $f ) {
				if ( is_file( $this->tmp . '/' . $f ) ) {
					unlink( $this->tmp . '/' . $f );
				}
			}
			if ( is_dir( $this->tmp . '/languages' ) ) {
				rmdir( $this->tmp . '/languages' );
			}
			rmdir( $this->tmp );
		}
		$this->tmp = '';
		parent::tearDown();
	}

	/**
	 * Kapıyı çalıştırır.
	 *
	 * @param string $root Kök dizin ('' = gerçek ağaç).
	 * @return array{code: int, out: string}
	 */
	private function run_gate( string $root = '' ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/check-version-literals.php' );
		if ( '' !== $root ) {
			$cmd .= ' ' . escapeshellarg( '--root=' . $root );
		}
		$out  = array();
		$code = 0;
		exec( $cmd . ' 2>&1', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- kapı betiği alt süreçte koşar.
		return array(
			'code' => $code,
			'out'  => implode( "\n", $out ),
		);
	}

	/**
	 * Gerçek dosyaların geçici bir kopyasını kurar.
	 */
	private function copy_tree(): string {
		$this->tmp = sys_get_temp_dir() . '/slotiva-version-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp . '/languages', 0777, true );
		foreach ( self::FILES as $f ) {
			copy( dirname( __DIR__, 2 ) . '/' . $f, $this->tmp . '/' . $f );
		}
		return $this->tmp;
	}

	public function test_the_real_tree_has_one_version_in_all_six_places(): void {
		$r = $this->run_gate();
		$this->assertSame( 0, $r['code'], $r['out'] );
		$this->assertMatchesRegularExpression( '/all 6 places say \d+\.\d+\.\d+/', $r['out'] );
		foreach ( array( 'plugin header Version', 'SLOTIVA_VERSION', 'Stable tag', 'Project-Id-Version', 'package.json', 'composer.json' ) as $label ) {
			$this->assertStringContainsString( $label, $r['out'] );
		}
	}

	/**
	 * Altı yerin her biri için mutasyon.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function mutation_provider(): array {
		return array(
			'plugin header' => array( 'mhm-slotiva.php', '/(\* Version:\s+)\S+/', '${1}9.9.9' ),
			'constant'      => array( 'mhm-slotiva.php', "/(define\\( 'SLOTIVA_VERSION', ')[^']+/", '${1}9.9.9' ),
			'stable tag'    => array( 'readme.txt', '/^(Stable tag:\s*)\S+/m', '${1}9.9.9' ),
			'po'            => array( 'languages/mhm-slotiva-tr_TR.po', '/(Project-Id-Version: MHM Slotiva )[^\\\\"]+/', '${1}9.9.9' ),
			'package.json'  => array( 'package.json', '/^(\s*"version":\s*")[^"]+/m', '${1}9.9.9' ),
			'composer.json' => array( 'composer.json', '/^(\s*"version":\s*")[^"]+/m', '${1}9.9.9' ),
		);
	}

	/**
	 * Her yerdeki uyuşmazlık yakalanır.
	 *
	 * @dataProvider mutation_provider
	 *
	 * @param string $file    Değiştirilecek dosya.
	 * @param string $pattern Değiştirme deseni.
	 * @param string $replace Yeni değer.
	 */
	public function test_the_gate_fails_when_one_place_disagrees( string $file, string $pattern, string $replace ): void {
		$root = $this->copy_tree();
		$this->assertSame( 0, $this->run_gate( $root )['code'], 'The copy must start clean.' );

		$text    = (string) file_get_contents( $root . '/' . $file );
		$changed = preg_replace( $pattern, $replace, $text, 1, $count );
		$this->assertSame( 1, $count, "The mutation did not apply to {$file}." );
		file_put_contents( $root . '/' . $file, $changed );

		$r = $this->run_gate( $root );
		$this->assertSame( 1, $r['code'], "Mutating {$file} was not caught:\n" . $r['out'] );
		$this->assertStringContainsString( '9.9.9', $r['out'] );
	}

	public function test_the_gate_fails_when_a_place_is_missing(): void {
		$root = $this->copy_tree();
		unlink( $root . '/composer.json' );
		$r = $this->run_gate( $root );
		$this->assertSame( 1, $r['code'], $r['out'] );
		$this->assertStringContainsString( 'composer.json', $r['out'] );
	}
}
