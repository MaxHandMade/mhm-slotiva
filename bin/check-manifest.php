<?php
/**
 * Manifest kapısı: ZIP'teki src/ PHP dosyaları bin/lite-manifest.txt ile TAM aynı olmalı (iki yönlü).
 *
 *   php bin/check-manifest.php [--zip=YOL]   0 = eşleşti, 1 = fark var, 2 = kapı kurulamadı
 *   php bin/check-manifest.php --write       manifesti ZIP'ten yeniden üretir
 *
 * Beyaz liste, kara liste değil: manifestte olmayan her src/ dosyası (geri sızan Pro dosyası) ve manifestte olup
 * ZIP'te olmayan her dosya (çürümüş manifest) hata verir. Liste ZIP'ten gelir; ZIP'i bin/build-zip.sh üretir
 * (tek kaynak: dışlama listesinin ikinci kopyası yok). php-zip gerekir: Docker içinde çalıştırın.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

require __DIR__ . '/lib/zip-list.php';

$base     = dirname( __DIR__ );
$manifest = $base . '/bin/lite-manifest.txt';
$all      = slotiva_zip_files( slotiva_zip_path( $base, $argv ), 'check-manifest' );
$actual   = array_values( array_filter( $all, static fn( string $p ): bool => 0 === strpos( $p, 'src/' ) && '.php' === substr( $p, -4 ) ) );

if ( in_array( '--write', $argv, true ) ) {
	$head = "# Slotiva Lite: ZIP'e giren src/ PHP dosyalari (beyaz liste). Elle duzenlenmez:\n# php bin/check-manifest.php --write (once bin/build-zip.sh).\n";
	file_put_contents( $manifest, $head . implode( "\n", $actual ) . "\n" );
	printf( "check-manifest: yazildi, %d dosya\n", count( $actual ) );
	exit( 0 );
}

exit( slotiva_compare_pin( $actual, slotiva_read_pin( $manifest, 'check-manifest' ), 'check-manifest', 'src/ PHP', 'php bin/check-manifest.php --write' ) );
