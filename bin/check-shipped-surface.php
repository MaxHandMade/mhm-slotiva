<?php
/**
 * Shipped-surface kapısı: ZIP'in TÜM içeriği bin/shipped-surface.txt ile ad ad TAM aynı olmalı (iki yönlü).
 *
 *   php bin/check-shipped-surface.php [--zip=YOL]   0 = eşleşti, 1 = fark var, 2 = kapı kurulamadı
 *   php bin/check-shipped-surface.php --write       pini ZIP'ten yeniden üretir
 *
 * Manifest yalnız src/'yi sabitler; geri kalan yüzey (build/, templates/, languages/, kök dosyalar, vendor/mhm/ui-core)
 * burada sabitlenir. Sayı tek başına yetmez (biri eklenip biri düşünce sayı aynı kalır): karşılaştırma ada göredir.
 * Pin ZIP'ten üretilir (ZIP'i bin/build-zip.sh yapar); build/ ZIP'e girdiği için oraya düşen her artık da yakalanır.
 * ui-core yolları bilerek pindedir: paket yeni bir sürümde başka dosya taşırsa biri diff'te görmeli.
 * php-zip gerekir: Docker içinde çalıştırın.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

require __DIR__ . '/lib/zip-list.php';

$base   = dirname( __DIR__ );
$pin    = $base . '/bin/shipped-surface.txt';
$actual = slotiva_zip_files( slotiva_zip_path( $base, $argv ), 'check-shipped-surface' );

if ( in_array( '--write', $argv, true ) ) {
	file_put_contents( $pin, implode( "\n", $actual ) . "\n" );
	printf( "check-shipped-surface: yazildi, %d dosya\n", count( $actual ) );
	exit( 0 );
}

exit( slotiva_compare_pin( $actual, slotiva_read_pin( $pin, 'check-shipped-surface' ), 'check-shipped-surface', 'ZIP yuzeyi', 'php bin/check-shipped-surface.php --write' ) );
