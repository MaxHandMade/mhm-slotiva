<?php
/**
 * Kapıların ortak "ZIP'e giren yüzey" türetmesi (tek kaynak).
 *
 * Küme: src/ src-react/ templates/ build/ languages/*.pot readme.txt mhm-slotiva.php uninstall.php ve
 * vendor/mhm/ui-core'un ZIP'e giren yolları. ui-core yolları elle yazılmaz: bin/build-zip.sh'taki UICORE_ALLOW /
 * UICORE_EXCLUDE okunup diskteki ui-core'a aynen uygulanır (dışlama listesinin ikinci kopyası tutulmaz).
 * `build/` taze derlenmiş olmalı: rm -rf build && npm run build.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

/**
 * bin/build-zip.sh'tan bir değişkenin boşlukla ayrılmış değerlerini okur.
 *
 * @return list<string>
 */
function slotiva_zip_var( string $script, string $name, string $gate ): array {
	if ( 1 !== preg_match( '/^' . $name . '="([^"]*)"/m', $script, $m ) ) {
		fwrite( STDERR, "{$gate} FAILED -- bin/build-zip.sh icinde {$name} okunamadi.\n" );
		exit( 2 );
	}
	return array_values( array_filter( preg_split( '/\s+/', trim( $m[1] ) ) ) );
}

/**
 * @return list<string> köke göre yollar
 */
function slotiva_tree( string $base, string $rel ): array {
	$root = $base . '/' . $rel;
	if ( is_file( $root ) ) {
		return array( $rel );
	}
	if ( ! is_dir( $root ) ) {
		return array();
	}
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$p = str_replace( DIRECTORY_SEPARATOR, '/', $f->getPathname() );
		if ( false !== strpos( $p, '/node_modules/' ) ) {
			continue;
		}
		$out[] = ltrim( substr( $p, strlen( str_replace( DIRECTORY_SEPARATOR, '/', $base ) ) ), '/' );
	}
	return $out;
}

/**
 * @return list<string> köke göre, sıralı, tekil yollar
 */
function slotiva_shipped_files( string $base, string $gate ): array {
	$zip_script = (string) file_get_contents( $base . '/bin/build-zip.sh' );
	$uic_allow  = slotiva_zip_var( $zip_script, 'UICORE_ALLOW', $gate );
	$uic_excl   = slotiva_zip_var( $zip_script, 'UICORE_EXCLUDE', $gate );

	$files = array();
	foreach ( array( 'src', 'src-react', 'templates', 'build' ) as $dir ) {
		$files = array_merge( $files, slotiva_tree( $base, $dir ) );
	}
	foreach ( (array) glob( $base . '/languages/*.pot' ) as $pot ) {
		$files[] = 'languages/' . basename( $pot );
	}
	foreach ( array( 'readme.txt', 'mhm-slotiva.php', 'uninstall.php' ) as $f ) {
		if ( is_file( $base . '/' . $f ) ) {
			$files[] = $f;
		}
	}

	// ui-core: build-zip.sh'ın budamasının aynısı (üst düzey beyaz liste, sonra açık dışlamalar, .git* artıkları).
	$uic_files = array();
	foreach ( slotiva_tree( $base, 'vendor/mhm/ui-core' ) as $rel ) {
		$sub = substr( $rel, strlen( 'vendor/mhm/ui-core/' ) );
		$top = explode( '/', $sub )[0];
		if ( ! in_array( $top, $uic_allow, true ) || 1 === preg_match( '#(^|/)\.git#', $sub ) ) {
			continue;
		}
		foreach ( $uic_excl as $ex ) {
			if ( $sub === $ex || 0 === strpos( $sub, rtrim( $ex, '/' ) . '/' ) ) {
				continue 2;
			}
		}
		$uic_files[] = $rel;
	}
	if ( is_dir( $base . '/vendor/mhm/ui-core' ) && array() === $uic_files ) {
		fwrite( STDERR, "{$gate} FAILED -- ui-core var ama build-zip.sh listesinden hic dosya cikmadi.\n" );
		exit( 2 );
	}
	$files = array_values( array_unique( array_merge( $files, $uic_files ) ) );
	sort( $files );
	return $files;
}
