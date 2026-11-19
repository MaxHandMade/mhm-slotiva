<?php
/**
 * ZIP dosya listesi okuyucu (manifest ve shipped-surface kapıları ortak kullanır).
 *
 * Liste ZIP'in kendisinden okunur; ZIP'i üreten tek yer bin/build-zip.sh'tır, dışlama listesi burada TEKRARLANMAZ.
 * Yollar kök klasör (mhm-slotiva/) atılarak, ileri eğik çizgiyle, sıralı döner.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

/**
 * `--zip=YOL` bağımsız değişkeni ya da dist/mhm-slotiva-<sürüm>.zip.
 */
function slotiva_zip_path( string $base, array $argv ): string {
	foreach ( $argv as $a ) {
		if ( 0 === strpos( $a, '--zip=' ) ) {
			return substr( $a, 6 );
		}
	}
	$main = (string) file_get_contents( $base . '/mhm-slotiva.php' );
	if ( 1 !== preg_match( "/define\( 'SLOTIVA_VERSION', '([^']+)' \)/", $main, $m ) ) {
		return '';
	}
	return $base . '/dist/mhm-slotiva-' . $m[1] . '.zip';
}

/**
 * @return list<string> kök klasörsüz, sıralı dosya yolları; kurulamazsa çıkış kodu 2 ile biter.
 */
function slotiva_zip_files( string $zip_path, string $gate ): array {
	if ( ! class_exists( 'ZipArchive' ) ) {
		fwrite( STDERR, "{$gate} KURULAMADI -- php-zip eklentisi yok (Docker icinde calistirin).\n" );
		exit( 2 );
	}
	if ( '' === $zip_path || ! is_file( $zip_path ) ) {
		fwrite( STDERR, "{$gate} KURULAMADI -- ZIP yok: {$zip_path} (once bin/build-zip.sh).\n" );
		exit( 2 );
	}
	$z = new ZipArchive();
	if ( true !== $z->open( $zip_path ) ) {
		fwrite( STDERR, "{$gate} KURULAMADI -- ZIP acilamadi: {$zip_path}\n" );
		exit( 2 );
	}
	$out = array();
	for ( $i = 0; $i < $z->numFiles; $i++ ) {
		$name = (string) $z->getNameIndex( $i );
		if ( '/' === substr( $name, -1 ) ) {
			continue;
		}
		$out[] = preg_replace( '#^mhm-slotiva/#', '', $name );
	}
	$z->close();
	sort( $out );
	if ( array() === $out ) {
		fwrite( STDERR, "{$gate} KURULAMADI -- ZIP bos.\n" );
		exit( 2 );
	}
	return $out;
}

/**
 * Pin dosyasını okur (# yorum ve boş satırlar atılır).
 *
 * @return list<string>
 */
function slotiva_read_pin( string $path, string $gate ): array {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "{$gate} KURULAMADI -- pin yok: {$path} (--write ile uretin).\n" );
		exit( 2 );
	}
	$out = array();
	foreach ( (array) file( $path, FILE_IGNORE_NEW_LINES ) as $l ) {
		$l = trim( $l );
		if ( '' !== $l && '#' !== $l[0] ) {
			$out[] = str_replace( '\\', '/', $l );
		}
	}
	if ( array() === $out ) {
		fwrite( STDERR, "{$gate} KURULAMADI -- pin bos (bos pin her agaci eksiltmeyle eslestirir).\n" );
		exit( 2 );
	}
	sort( $out );
	return array_values( array_unique( $out ) );
}

/**
 * İki yönlü karşılaştırma + rapor; 0 ya da 1 döner.
 *
 * @param list<string> $actual
 * @param list<string> $pinned
 */
function slotiva_compare_pin( array $actual, array $pinned, string $gate, string $what, string $regen ): int {
	$added   = array_values( array_diff( $actual, $pinned ) );
	$removed = array_values( array_diff( $pinned, $actual ) );
	if ( array() === $added && array() === $removed ) {
		printf( "%s: [OK] %s pin ile ayni (%d dosya)\n", $gate, $what, count( $actual ) );
		return 0;
	}
	fwrite( STDERR, sprintf( "%s FAILED -- %s pin ile eslesmiyor (pin %d, gercek %d):\n", $gate, $what, count( $pinned ), count( $actual ) ) );
	foreach ( $added as $p ) {
		fwrite( STDERR, "  [+] pinde yok, ZIPte var: {$p}\n" );
	}
	foreach ( $removed as $p ) {
		fwrite( STDERR, "  [-] pinde var, ZIPte yok: {$p}\n" );
	}
	fwrite( STDERR, "  Degisiklik bilincliyse pini ayni commit'te yenileyin: {$regen}\n" );
	return 1;
}
