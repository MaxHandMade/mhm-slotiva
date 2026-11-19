<?php
/**
 * Sürüm sabitleri kapısı: eklenti sürümü altı yerde yazılıdır ve hepsi AYNI olmalıdır.
 *
 *   php bin/check-version-literals.php [--root=<dizin>]   0 = hepsi aynı, 1 = uyuşmazlık, 2 = kapı kurulamadı
 *
 * Yerler: eklenti başlığı `Version:`, `SLOTIVA_VERSION`, readme.txt `Stable tag:`, tr_TR .po `Project-Id-Version`,
 * package.json `version`, composer.json `version`. Her değer yazdırılır; biri farklıysa ya da bulunamazsa çıkış 1.
 * `--root` yalnız mutasyon testi içindir (geçici kopya üzerinde çalışır).
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

$base = dirname( __DIR__ );
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--root=' ) ) {
		$base = rtrim( substr( $arg, 7 ), '/\\' );
	}
}

if ( ! is_dir( $base ) ) {
	fwrite( STDERR, "check-version-literals KURULAMADI -- kok dizin yok: {$base}\n" );
	exit( 2 );
}

/**
 * Bir dosyadan desenle ilk yakalanan değeri okur; dosya ya da desen yoksa null.
 *
 * @param string $path    Dosya yolu.
 * @param string $pattern Tek yakalama grubu olan desen.
 */
function slotiva_version_read( string $path, string $pattern ): ?string {
	if ( ! is_readable( $path ) ) {
		return null;
	}
	$text = (string) file_get_contents( $path );
	return 1 === preg_match( $pattern, $text, $m ) ? trim( $m[1] ) : null;
}

$places = array(
	'plugin header Version'  => array( 'mhm-slotiva.php', '/^[ \t\/*#@]*Version:[ \t]*(\S+)/mi' ),
	'SLOTIVA_VERSION'        => array( 'mhm-slotiva.php', '/define\(\s*\'SLOTIVA_VERSION\'\s*,\s*\'([^\']+)\'/' ),
	'readme.txt Stable tag'  => array( 'readme.txt', '/^Stable tag:[ \t]*(\S+)/mi' ),
	'.po Project-Id-Version' => array( 'languages/mhm-slotiva-tr_TR.po', '/"Project-Id-Version:\s*MHM Slotiva\s+([^\\\\"\s]+)/' ),
	'package.json version'   => array( 'package.json', '/^\s*"version"\s*:\s*"([^"]+)"/m' ),
	'composer.json version'  => array( 'composer.json', '/^\s*"version"\s*:\s*"([^"]+)"/m' ),
);

$values  = array();
$missing = array();
foreach ( $places as $label => list( $file, $pattern ) ) {
	$value = slotiva_version_read( $base . '/' . $file, $pattern );
	if ( null === $value ) {
		$missing[] = $label . ' (' . $file . ')';
		continue;
	}
	$values[ $label ] = $value;
	echo str_pad( $label, 24 ) . ' ' . $value . '  [' . $file . "]\n";
}

if ( array() !== $missing ) {
	fwrite( STDERR, "check-version-literals FAIL -- value not found:\n  " . implode( "\n  ", $missing ) . "\n" );
	exit( 1 );
}

$distinct = array_values( array_unique( $values ) );
if ( 1 !== count( $distinct ) ) {
	fwrite( STDERR, 'check-version-literals FAIL -- ' . count( $distinct ) . ' different versions: ' . implode( ', ', $distinct ) . "\n" );
	exit( 1 );
}

echo 'check-version-literals OK -- all ' . count( $values ) . ' places say ' . $distinct[0] . "\n";
exit( 0 );
