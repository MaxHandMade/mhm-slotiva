<?php
/**
 * Mutation testleri: yüzey kapıları (manifest, shipped-surface, external-http, short-prefix, uicore-version)
 * gerçekten bozulmayı yakalar mı. "Temiz" çıktı tek başına bir şey kanıtlamaz; bu yüzden gerçek ağaca/ZIP'e
 * kontrollü bir bozulma verilir, kapı çalıştırılır, "yakaladı" çıkış kodu (1) TAM olarak denetlenir (kapı
 * "kurulamadı" anlamında 2 dönerse bu yakalama sayılmaz) ve sonda dosyaları başarısızlıkta bile silinir.
 *
 * ZIP kapıları dist/mhm-slotiva-<sürüm>.zip ister (önce `bin/build-zip.sh`); yoksa test BAŞARISIZ olur, atlanmaz.
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Tools;

use PHPUnit\Framework\TestCase;

class SurfaceGatesTest extends TestCase {

	/**
	 * Silinecek geçici dosyalar (sonda dosyaları ve hazırlanmış ZIP kopyaları).
	 *
	 * @var list<string>
	 */
	private array $cleanup = array();

	protected function tearDown(): void {
		foreach ( $this->cleanup as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		$this->cleanup = array();
		parent::tearDown();
	}

	/**
	 * Manifest + shipped-surface (ZIP üzerinde, iki yönlü).
	 */
	public function test_manifest_gate_passes_the_real_zip(): void {
		$r = $this->gate( 'check-manifest.php', array( '--zip=' . $this->realZip() ) );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	public function test_manifest_gate_fails_on_unlisted_file_in_zip(): void {
		$zip = $this->stagedZip( array( 'src/ZzUnlisted.php' => "<?php\n" ), array() );
		$r   = $this->gate( 'check-manifest.php', array( '--zip=' . $zip ) );
		$this->assertSame( 1, $r['code'], $r['out'] );
		$this->assertStringContainsString( 'src/ZzUnlisted.php', $r['out'] );
	}

	public function test_manifest_gate_fails_when_a_listed_file_is_missing_from_zip(): void {
		$victim = $this->firstManifestEntry();
		$zip    = $this->stagedZip( array(), array( $victim ) );
		$r      = $this->gate( 'check-manifest.php', array( '--zip=' . $zip ) );
		$this->assertSame( 1, $r['code'], $r['out'] );
		$this->assertStringContainsString( $victim, $r['out'] );
	}

	public function test_manifest_gate_is_unusable_without_a_zip(): void {
		$r = $this->gate( 'check-manifest.php', array( '--zip=' . sys_get_temp_dir() . '/yok-' . getmypid() . '.zip' ) );
		$this->assertSame( 2, $r['code'], $r['out'] );
	}

	public function test_shipped_surface_gate_passes_the_real_zip(): void {
		$r = $this->gate( 'check-shipped-surface.php', array( '--zip=' . $this->realZip() ) );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	public function test_shipped_surface_gate_fails_on_any_extra_file_in_zip(): void {
		$zip = $this->stagedZip( array( 'build/zz-artefact.txt' => 'x' ), array() );
		$r   = $this->gate( 'check-shipped-surface.php', array( '--zip=' . $zip ) );
		$this->assertSame( 1, $r['code'], $r['out'] );
		$this->assertStringContainsString( 'build/zz-artefact.txt', $r['out'] );
	}

	public function test_shipped_surface_gate_fails_when_a_pinned_file_is_dropped(): void {
		$zip = $this->stagedZip( array(), array( 'vendor/mhm/ui-core/assets/react/admin.css' ) );
		$r   = $this->gate( 'check-shipped-surface.php', array( '--zip=' . $zip ) );
		$this->assertSame( 1, $r['code'], $r['out'] );
		$this->assertStringContainsString( 'vendor/mhm/ui-core/assets/react/admin.css', $r['out'] );
	}

	/**
	 * External HTTP.
	 */
	public function test_external_http_gate_passes_the_real_tree(): void {
		$r = $this->gate( 'check-external-http.php' );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	/**
	 * Dış HTTP çağrı örnekleri.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function http_calls(): array {
		return array(
			'wp_remote_get'      => array( "wp_remote_get( 'https://example.com' );" ),
			'wp_remote_post'     => array( 'wp_remote_post( $u, array() );' ),
			'wp_safe_remote_get' => array( 'wp_safe_remote_get( $u );' ),
			'wp_remote_request'  => array( 'wp_remote_request( $u );' ),
			'curl_init'          => array( '$c = curl_init( $u );' ),
			'curl_exec'          => array( 'curl_exec( $c );' ),
			'file_get_contents'  => array( "file_get_contents( 'http://example.com/x' );" ),
			'fsockopen'          => array( "fsockopen( 'example.com', 80 );" ),
			'WP_Http'            => array( '$h = new WP_Http();' ),
		);
	}

	/**
	 * Kapı src/ altındaki dış HTTP çağrısını yakalar.
	 *
	 * @dataProvider http_calls
	 *
	 * @param string $code Sonda koduna girecek çağrı.
	 */
	public function test_external_http_gate_catches_call_under_src( string $code ): void {
		$probe = $this->probeFile( 'src/zz_http_probe_' . getmypid() . '.php', "<?php\n{$code}\n" );
		$r     = $this->gate( 'check-external-http.php' );
		$this->assertSame( 1, $r['code'], "Kapı çağrıyı yakalamadı:\n" . $r['out'] );
		$this->assertStringContainsString( basename( $probe ), $r['out'] );
	}

	public function test_external_http_gate_allows_calendar_link_comments_and_local_file_reads(): void {
		// Müşterinin tıkladığı URL, yorumdaki sözcük ve yerel dosya okuma sunucu tarafı HTTP değildir.
		$this->probeFile(
			'src/zz_http_ok_' . getmypid() . '.php',
			"<?php\n// wp_remote_get( 'x' ) eski lisans istemcisiydi.\n\$u = 'https://calendar.google.com/calendar/render?x=1';\n\$s = file_get_contents( __FILE__ );\n"
		);
		$r = $this->gate( 'check-external-http.php' );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	/**
	 * Kısa önek (spec R4/D12).
	 */
	public function test_short_prefix_gate_passes_the_real_tree(): void {
		$r = $this->gate( 'check-short-prefix.php' );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	/**
	 * Bozuk ad örnekleri: [dosya soneki, içerik, beklenen ad].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function broken_names(): array {
		return array(
			'handle zz-broken (register)'    => array( 'php', "<?php\nwp_register_script( 'zz-broken', 'x.js', array(), '1', true );\n", 'zz-broken' ),
			'handle zz-broken (enqueue)'     => array( 'php', "<?php\nwp_enqueue_style( 'zz-broken', 'x.css' );\n", 'zz-broken' ),
			'handle mhm- (register)'         => array( 'php', "<?php\nwp_register_style( 'mhm-thing', 'x.css' );\n", 'mhm-thing' ),
			'handle via const'               => array( 'php', "<?php\nclass ZzP { const H = 'zz_bad'; function f() { wp_register_script( self::H, 'x.js' ); } }\n", 'zz_bad' ),
			'class .mhm-x (css)'             => array( 'css', ".mhm-x { color: red; }\n", 'mhm-x' ),
			'class .zz-x (css)'              => array( 'css', ".zz-x { color: red; }\n", 'zz-x' ),
			'class mhm-x (php html)'         => array( 'php', "<?php\necho '<div class=\"slotiva-a mhm-x\">';\n", 'mhm-x' ),
			'class mhm-x (jsx)'              => array( 'js', "const a = <div className=\"mhm-x\" />;\n", 'mhm-x' ),
			'id mhm-y (php html)'            => array( 'php', "<?php\necho '<div id=\"mhm-y\">';\n", 'mhm-y' ),
			'id zz-y (php html)'             => array( 'php', "<?php\necho '<div id=\"zz-y\">';\n", 'zz-y' ),
			'id mhm-y (getElementById)'      => array( 'js', "document.getElementById( 'mhm-y' );\n", 'mhm-y' ),
			'compound .a.mhm-x (css)'        => array( 'css', ".a.mhm-x{color:red}\n", 'mhm-x' ),
			'element a.mhm-x (css)'          => array( 'css', "a.mhm-x{color:red}\n", 'mhm-x' ),
			'not().zz-x (css)'               => array( 'css', ":not(.a).zz-x{color:red}\n", 'zz-x' ),
			'attr[].zz-x (css)'              => array( 'css', "a[href].zz-x{color:red}\n", 'zz-x' ),
			'slotiva-card.mhm-active'        => array( 'css', ".slotiva-card.mhm-active{color:red}\n", 'mhm-active' ),
			'minified compound (css)'        => array( 'css', ".slotiva-a{x:1}.slotiva-b>.slotiva-c.mhm-z{y:2}\n", 'mhm-z' ),
			'compound in querySelector'      => array( 'js', "document.querySelector( '.slotiva-card.mhm-active' );\n", 'mhm-active' ),
			'element in querySelector'       => array( 'js', "document.querySelector( 'div.zz-q' );\n", 'zz-q' ),
			'php array class string'         => array( 'php', "<?php\n\$a = array( 'class' => 'mhm-x' );\n", 'mhm-x' ),
			'php array class list'           => array( 'php', "<?php\n\$a = array( 'class' => array( 'slotiva-a', 'mhm-x' ) );\n", 'mhm-x' ),
			'php array class multiline list' => array( 'php', "<?php\n\$a = array(\n\t'class' => array(\n\t\t'slotiva-a',\n\t\t'mhm-x',\n\t),\n);\n", 'mhm-x' ),
			'php array id'                   => array( 'php', "<?php\n\$a = array( 'id' => 'mhm-y' );\n", 'mhm-y' ),
			'setAttribute class'             => array( 'js', "el.setAttribute( 'class', 'slotiva-a mhm-x' );\n", 'mhm-x' ),
			'setAttribute id'                => array( 'js', "el.setAttribute( 'id', 'mhm-y' );\n", 'mhm-y' ),
			'className assignment'           => array( 'js', "el.className = 'mhm-x';\n", 'mhm-x' ),
			'DOM el.id assignment'           => array( 'js', "el.id = 'mhm-y';\n", 'mhm-y' ),
			'DOM popupEl.id assignment'      => array( 'js', "popupEl.id = 'mhm-y';\n", 'mhm-y' ),
			'DOM this.$root.id assignment'   => array( 'js', "this.\$root.id = 'zz-y';\n", 'zz-y' ),
			'inline style string'            => array( 'php', "<?php\nwp_add_inline_style( 'slotiva-x', '.slotiva-a .mhm-x{color:red}' );\n", 'mhm-x' ),
		);
	}

	/**
	 * Kapı yayınlanan dosyadaki bozuk adı yakalar.
	 *
	 * @dataProvider broken_names
	 *
	 * @param string $ext     Dosya soneki.
	 * @param string $content Dosya içeriği.
	 * @param string $name    Beklenen ad.
	 */
	public function test_short_prefix_gate_catches_bad_name_in_shipped_file( string $ext, string $content, string $name ): void {
		// templates/ ZIP'e girer; .css ve .php orada da taranır.
		$probe = $this->probeFile( 'templates/zz_prefix_probe_' . getmypid() . '.' . $ext, $content );
		$r     = $this->gate( 'check-short-prefix.php' );
		$this->assertSame( 1, $r['code'], "Kapı '{$name}' adını yakalamadı:\n" . $r['out'] );
		$this->assertStringContainsString( basename( $probe ), $r['out'] );
		$this->assertStringContainsString( $name, $r['out'] );
	}

	public function test_short_prefix_gate_does_not_flag_the_text_domain(): void {
		$this->probeFile(
			'src/zz_textdomain_probe_' . getmypid() . '.php',
			"<?php\n\$a = __( 'Merhaba', 'mhm-slotiva' );\n_e( 'x', 'mhm-slotiva' );\nload_plugin_textdomain( 'mhm-slotiva', false, 'languages' );\nwp_set_script_translations( 'slotiva-zz', 'mhm-slotiva', __DIR__ );\n\$b = array( 'text_domain' => 'mhm-slotiva' );\n"
		);
		$r = $this->gate( 'check-short-prefix.php' );
		$this->assertSame( 0, $r['code'], "Metin alanı önek ihlali sayılmamalı:\n" . $r['out'] );
	}

	public function test_short_prefix_gate_does_not_misread_urls_decimals_and_sass_calls_as_classes(): void {
		$this->probeFile(
			'templates/zz_css_neg_' . getmypid() . '.scss',
			"@use \"sass:math\";\n@import \"x.css\";\n.slotiva-a { background: url(foo.png) no-repeat; margin: 1.5em 0.5em; transition: opacity .5s; width: math.div(10px, 2); }\na.slotiva-b, button.slotiva-c.is-active, :not(.slotiva-d).slotiva-e { color: red; }\n"
		);
		$this->probeFile(
			'templates/zz_pa_neg_' . getmypid() . '.php',
			"<?php\n\$a = array( 'class' => array( 'slotiva-a', 'button' ), 'id' => 'slotiva-b' );\n\$x = \"el.setAttribute( 'class', 'slotiva-c' );\";\nwp_add_inline_style( 'slotiva-x', '.slotiva-a.slotiva-b{margin:0.5em}' );\n"
		);
		$r = $this->gate( 'check-short-prefix.php' );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	public function test_short_prefix_pending_list_fails_loudly_when_a_listed_name_is_stale(): void {
		$json            = sys_get_temp_dir() . '/zz-pending-' . getmypid() . '.json';
		$this->cleanup[] = $json;
		file_put_contents( $json, json_encode( array( 'vendor/mhm/ui-core/assets/react/admin.css' => array( 'mhm-widget', 'mhm-zz-no-longer-there' ) ) ) );
		$r = $this->gate( 'check-short-prefix.php', array( '--pending-json=' . $json ) );
		$this->assertSame( 1, $r['code'], $r['out'] );
		$this->assertStringContainsString( 'mhm-zz-no-longer-there', $r['out'] );
	}

	/**
	 * Kardeş anahtarlar ve veri modeli atamaları sınıf/kimlik DEĞİLDİR: yalnız 'class'/'id' değeri kurala bağlanır.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function innocent_snippets(): array {
		return array(
			'sibling key url'   => array( 'php', "<?php\n\$a = array( 'class'=>'slotiva-a','url'=>\$u );\n" ),
			'sibling key key'   => array( 'php', "<?php\n\$a = array( 'class' => 'slotiva-a', 'key' => 'v' );\n" ),
			'sibling key tag'   => array( 'php', "<?php\n\$a = array( 'id'=>'slotiva-b','tag'=>'div' );\n" ),
			'list then sibling' => array( 'php', "<?php\n\$a = array( 'class' => array( 'slotiva-a', 'button' ), 'url' => 'x' );\n" ),
			'data model row.id' => array( 'js', "row.id = 'a1';\nitem.id = 'zz';\n" ),
		);
	}

	/**
	 * Kapı kardeş anahtarları ve veri modeli kimliklerini yok sayar.
	 *
	 * @dataProvider innocent_snippets
	 *
	 * @param string $ext     Dosya soneki.
	 * @param string $content Dosya içeriği.
	 */
	public function test_short_prefix_gate_ignores_sibling_keys_and_data_model_ids( string $ext, string $content ): void {
		$this->probeFile( 'templates/zz_innocent_' . getmypid() . '.' . $ext, $content );
		$r = $this->gate( 'check-short-prefix.php' );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	public function test_short_prefix_gate_accepts_slotiva_wp_and_state_names(): void {
		$this->probeFile(
			'templates/zz_prefix_ok_' . getmypid() . '.php',
			"<?php\nwp_enqueue_style( 'wp-components' );\nwp_register_script( 'slotiva-ok', 'x.js' );\necho '<div id=\"slotiva-a\" class=\"slotiva-b is-active wp-core-ui button\">';\n"
		);
		$r = $this->gate( 'check-short-prefix.php' );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	public function test_short_prefix_pending_list_is_exact_not_a_prefix_waiver(): void {
		// ui-core'a bekleyen yeniden adlandırma listesi (dosya + sınıf) yalnız o dosya ve o sınıf için geçerlidir.
		$this->probeFile( 'templates/zz_pending_probe_' . getmypid() . '.css', ".mhm-widget { color: red; }\n" );
		$r = $this->gate( 'check-short-prefix.php' );
		$this->assertSame( 1, $r['code'], "Bekleyen liste başka dosyaya sızdı:\n" . $r['out'] );
	}

	/**
	 * UI-core sürüm kapısı.
	 */
	public function test_uicore_version_gate_passes_the_real_tree(): void {
		$r = $this->gate( 'check-uicore-version.php' );
		$this->assertSame( 0, $r['code'], $r['out'] );
	}

	public function test_uicore_version_gate_fails_on_drifted_literal(): void {
		$plugin    = $this->tmp( "<?php\nmhmuicore_register( '0.1.0', __DIR__ . '/vendor/mhm/ui-core/bootstrap.php' );\n" );
		$bootstrap = $this->tmp( "<?php\ndefine( 'MHMUICORE_VERSION', '0.23.0' );\n" );
		$r         = $this->gate( 'check-uicore-version.php', array( '--plugin=' . $plugin, '--bootstrap=' . $bootstrap ) );
		$this->assertSame( 1, $r['code'], $r['out'] );
	}

	public function test_uicore_version_gate_fails_when_installed_is_outside_composer_constraint(): void {
		$plugin    = $this->tmp( "<?php\nmhmuicore_register( '0.24.0', __DIR__ . '/vendor/mhm/ui-core/bootstrap.php' );\n" );
		$bootstrap = $this->tmp( "<?php\ndefine( 'MHMUICORE_VERSION', '0.24.0' );\n" );
		$r         = $this->gate( 'check-uicore-version.php', array( '--plugin=' . $plugin, '--bootstrap=' . $bootstrap ) );
		$this->assertSame( 1, $r['code'], 'Sürümler eşit ama ^0.23 dışında; kapı yakalamalıydı: ' . $r['out'] );
	}

	public function test_uicore_version_gate_is_unusable_when_inputs_are_unreadable(): void {
		$r = $this->gate( 'check-uicore-version.php', array( '--plugin=' . sys_get_temp_dir() . '/yok-' . getmypid() . '.php' ) );
		$this->assertSame( 2, $r['code'], $r['out'] );
	}

	/**
	 * Yardımcılar.
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	private function realZip(): string {
		$main = (string) file_get_contents( $this->root() . '/mhm-slotiva.php' );
		$this->assertSame( 1, preg_match( "/define\( 'SLOTIVA_VERSION', '([^']+)' \)/", $main, $m ) );
		$zip = $this->root() . '/dist/mhm-slotiva-' . $m[1] . '.zip';
		$this->assertFileExists( $zip, 'Önce bin/build-zip.sh ile ZIP üretin.' );
		return $zip;
	}

	private function firstManifestEntry(): string {
		$lines = file( $this->root() . '/bin/lite-manifest.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$this->assertNotEmpty( $lines );
		foreach ( $lines as $l ) {
			if ( '#' !== $l[0] ) {
				return trim( $l );
			}
		}
		$this->fail( 'Manifest boş.' );
	}

	/**
	 * Gerçek ZIP'in kopyasını ekleyip/çıkarıp hazırlar. Yollar ZIP içindeki kök klasöre (mhm-slotiva/) göre verilir.
	 *
	 * @param array<string,string> $add    Eklenecek yollar ve içerikleri.
	 * @param array<int,string>    $remove Çıkarılacak yollar.
	 */
	private function stagedZip( array $add, array $remove ): string {
		$dst             = sys_get_temp_dir() . '/zz-staged-' . getmypid() . '-' . count( $this->cleanup ) . '.zip';
		$this->cleanup[] = $dst;
		$this->assertTrue( copy( $this->realZip(), $dst ) );
		$z = new \ZipArchive();
		$this->assertTrue( true === $z->open( $dst ) );
		foreach ( $add as $path => $body ) {
			$z->addFromString( 'mhm-slotiva/' . $path, $body );
		}
		foreach ( $remove as $path ) {
			$this->assertTrue( $z->deleteName( 'mhm-slotiva/' . $path ), "ZIP'te yok: {$path}" );
		}
		$z->close();
		return $dst;
	}

	/**
	 * Sonda dosyası yazar (tearDown siler).
	 *
	 * @param string $rel     Kökten göreli yol.
	 * @param string $content İçerik.
	 */
	private function probeFile( string $rel, string $content ): string {
		$path = $this->root() . '/' . $rel;
		file_put_contents( $path, $content );
		$this->cleanup[] = $path;
		return $path;
	}

	private function tmp( string $content ): string {
		$path = sys_get_temp_dir() . '/zz-gate-' . getmypid() . '-' . count( $this->cleanup ) . '.php';
		file_put_contents( $path, $content );
		$this->cleanup[] = $path;
		return $path;
	}

	/**
	 * Kapı betiğini çalıştırır.
	 *
	 * @param string            $script Betik adı (bin/ altında).
	 * @param array<int,string> $args   Bağımsız değişkenler.
	 * @return array{code:int,out:string}
	 */
	private function gate( string $script, array $args = array() ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->root() . '/bin/' . $script );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( $a );
		}
		exec( $cmd . ' 2>&1', $lines, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- kapı betiği alt süreçte koşar.
		return array(
			'code' => $code,
			'out'  => implode( "\n", $lines ),
		);
	}
}
