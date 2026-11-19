<?php
/**
 * Mutation testleri: `bin/check-no-pro-refs.php` ve `bin/check-crippleware.php` yasak bir sözcüğü gerçekten yakalar mı.
 * Gerçek bir yakalama olmadan "ağaç temiz" çıktısı bir şey kanıtlamaz; bu yüzden taranan dizine geçici bir dosya
 * yazılır, kapı çalıştırılır, çıkış kodu denetlenir ve dosya (başarısızlıkta bile) silinir.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Tools;

use PHPUnit\Framework\TestCase;

class NoProRefsGateTest extends TestCase {

	/**
	 * Geçici sonda dosyası (src/ taranır; tearDown her durumda siler).
	 *
	 * @var string
	 */
	private string $probe = '';

	protected function tearDown(): void {
		if ( '' !== $this->probe && is_file( $this->probe ) ) {
			unlink( $this->probe );
		}
		$this->probe = '';
		parent::tearDown();
	}

	/**
	 * Pro sözcükleri.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function forbidden_pro_tokens(): array {
		$tokens = array(
			'LicenseManager',
			'license_key',
			'feature_token',
			'Update URI',
			'isPro',
			'is_pro',
			'pro_seam',
			'pro version',
			'upgrade to',
			'requires pro',
			'available in pro',
			'wpalemi.com/slotiva',
			'slotiva_license',
			'slotiva_license_checked',
			'pro sürüm',
			'lisans anahtar',
			'lisans',
			'slotiva_is_pro',
			'is_pro_active',
			'isProActive',
			'my_pro_seam_x',
			'XUpdateNoticeY',
			'XLicenseManagerY',
			'slotiva_pro_feature_x',
			'$licenseManager',
			'$updateNotice',
			'ispro',
			'licensemanager',
			'mypro_feature',
			'xpro_seam',
			'IsPro',
		);
		$out    = array();
		foreach ( $tokens as $t ) {
			$out[ $t ] = array( $t );
		}
		return $out;
	}

	/**
	 * Pro kapısı sözcüğü yakalar.
	 *
	 * @dataProvider forbidden_pro_tokens
	 *
	 * @param string $token Yasak sözcük.
	 */
	public function test_no_pro_gate_catches_token( string $token ): void {
		$this->writeProbe( "<?php\n// {$token}\n" );
		$r = $this->runGate( 'check-no-pro-refs.php' );
		$this->assertSame( 1, $r['code'], "Kapı '{$token}' sözcüğünü yakalamadı:\n" . $r['out'] );
		$this->assertStringContainsString( basename( $this->probe ), $r['out'] );
	}

	public function test_no_pro_gate_ignores_ordinary_words_containing_pro(): void {
		$this->writeProbe(
			'<?php
// product provider process approve improve protected isProcessing is_product isProduct isprotected
'
		);
		$r = $this->runGate( 'check-no-pro-refs.php' );
		$this->assertSame(
			0,
			$r['code'],
			'Siradan sozcukler yakalanmamaliydi:
' . $r['out']
		);
	}

	public function test_no_pro_gate_passes_license_headers(): void {
		$this->writeProbe( "<?php\n/**\n * License:              GPL-2.0-or-later\n * License URI:          https://www.gnu.org/licenses/gpl-2.0.html\n */\n" );
		$r = $this->runGate( 'check-no-pro-refs.php' );
		$this->assertSame( 0, $r['code'], "GPL başlık satırları geçmeliydi:\n" . $r['out'] );
	}

	public function test_no_pro_gate_scans_real_header_lines_and_ui_core_respects_build_zip(): void {
		$r = $this->runGate( 'check-no-pro-refs.php', '--list' );
		$this->assertSame( 0, $r['code'], $r['out'] );
		$files = array_filter( explode( "\n", $r['out'] ) );
		$this->assertContains( 'mhm-slotiva.php', $files );
		$this->assertContains( 'readme.txt', $files );
		$this->assertContains( 'uninstall.php', $files );
		$this->assertNotEmpty( preg_grep( '#^languages/.*\.pot$#', $files ), '.pot taranmıyor.' );
		$this->assertNotEmpty( preg_grep( '#^vendor/mhm/ui-core/src/#', $files ), 'ui-core src/ taranmıyor.' );
		// build-zip.sh'ın dışladıkları listede olmamalı (tek kaynak: betiğin kendi UICORE_EXCLUDE'u).
		$this->assertEmpty( preg_grep( '#^vendor/mhm/ui-core/(src-react/|assets/react/pro\.css|src/Cli/|README)#', $files ) );
	}

	/**
	 * Crippleware sözcükleri.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function forbidden_crippleware_tokens(): array {
		return array(
			'max_seats'     => array( 'max_seats' ),
			'limit_reached' => array( 'limit_reached' ),
			'quota'         => array( 'quota' ),
			'quota (case)'  => array( 'SeatQuotaException' ),
			'trial'         => array( 'trial' ),
		);
	}

	/**
	 * Crippleware kapısı sözcüğü yakalar.
	 *
	 * @dataProvider forbidden_crippleware_tokens
	 *
	 * @param string $token Yasak sözcük.
	 */
	public function test_crippleware_gate_catches_token( string $token ): void {
		$this->writeProbe( "<?php\n// {$token}\n" );
		$r = $this->runGate( 'check-crippleware.php' );
		$this->assertSame( 1, $r['code'], "Kapı '{$token}' sözcüğünü yakalamadı:\n" . $r['out'] );
		$this->assertStringContainsString( basename( $this->probe ), $r['out'] );
	}

	public function test_crippleware_gate_exempts_only_the_hold_quota_identifiers_in_their_files(): void {
		// Muafiyet dosya+kimlik bazlıdır: başka bir dosyada HoldQuotaException bile yakalanır.
		$this->writeProbe( "<?php\nthrow new HoldQuotaException();\n" );
		$r = $this->runGate( 'check-crippleware.php' );
		$this->assertSame( 1, $r['code'], "Muafiyet başka dosyaya sızdı:\n" . $r['out'] );
		$this->assertStringContainsString( basename( $this->probe ), $r['out'] );
	}

	public function test_crippleware_gate_passes_the_real_tree(): void {
		$r = $this->runGate( 'check-crippleware.php' );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	private function writeProbe( string $code ): void {
		$this->probe = dirname( __DIR__, 2 ) . '/src/zz_gate_probe_' . getmypid() . '.php';
		file_put_contents( $this->probe, $code );
	}

	/**
	 * Kapı betiğini çalıştırır.
	 *
	 * @param string $script Betik adı (bin/ altında).
	 * @param string $arg    Ek bağımsız değişken.
	 * @return array{code:int,out:string}
	 */
	private function runGate( string $script, string $arg = '' ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/' . $script ) . ( '' !== $arg ? ' ' . $arg : '' ) . ' 2>&1';
		exec( $cmd, $lines, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- kapı betiği alt süreçte koşar.
		return array(
			'code' => $code,
			'out'  => implode( "\n", $lines ),
		);
	}
}
