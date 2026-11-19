<?php
/**
 * ui-core sürüm kapısı: mhm-slotiva.php'nin `mhmuicore_register( '<sürüm>' )` değişmezi, kurulu paketin
 * MHMUICORE_VERSION'ı ve composer.json kısıtı birbirini tutmalı.
 *
 *   php bin/check-uicore-version.php [--plugin=DOSYA] [--bootstrap=DOSYA]
 *   0 = tutarlı, 1 = fark var, 2 = kapı kurulamadı (girdi okunamadı; asla "başarı" sayılmaz)
 *
 * Neden: kayıt defteri en YÜKSEK sürümü önyükler. Paketten düşük yazılmış bir değişmez sessizce kendini eksik
 * bildirir; daha eski ui-core taşıyan ikinci bir eklenti seçimi kazanır. Değişmez elle yazılır (kayıt, bootstrap
 * yüklenmeden önce çalışır) bu yüzden kapı gerekir. Sabitlenen: KURULU paket (vendor/bootstrap.php), çünkü ZIP'e
 * giren de odur; ek olarak kurulu sürüm composer.json kısıtını (^X.Y) sağlamalı. composer.lock'a ayrıca
 * bağlanılmaz: lock kurulumla zaten kurulu pakete yansır.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

$root      = dirname( __DIR__ );
$plugin    = $root . '/mhm-slotiva.php';
$bootstrap = $root . '/vendor/mhm/ui-core/bootstrap.php';
$composer  = $root . '/composer.json';
foreach ( $argv as $a ) {
	if ( 0 === strpos( $a, '--plugin=' ) ) {
		$plugin = substr( $a, 9 );
	} elseif ( 0 === strpos( $a, '--bootstrap=' ) ) {
		$bootstrap = substr( $a, 12 );
	}
}

foreach ( array( $plugin, $bootstrap, $composer ) as $required ) {
	if ( ! is_readable( $required ) ) {
		fwrite( STDERR, "check-uicore-version KURULAMADI -- okunamadi: {$required} (composer install gerekli).\n" );
		exit( 2 );
	}
}

if ( 1 !== preg_match( '/mhmuicore_register\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents( $plugin ), $m ) ) {
	fwrite( STDERR, "check-uicore-version KURULAMADI -- mhmuicore_register( '<surum>' ) bulunamadi.\n" );
	exit( 2 );
}
$declared = $m[1];

if ( 1 !== preg_match( '/define\(\s*[\'"]MHMUICORE_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents( $bootstrap ), $m ) ) {
	fwrite( STDERR, "check-uicore-version KURULAMADI -- kurulu pakette MHMUICORE_VERSION yok.\n" );
	exit( 2 );
}
$installed = $m[1];

$json = json_decode( (string) file_get_contents( $composer ), true );
$req  = is_array( $json ) ? (string) ( $json['require']['mhm/ui-core'] ?? '' ) : '';
if ( 1 !== preg_match( '/^\^(\d+)\.(\d+)/', $req, $c ) ) {
	fwrite( STDERR, "check-uicore-version KURULAMADI -- composer.json'da mhm/ui-core ^X.Y kisiti yok ('{$req}').\n" );
	exit( 2 );
}

$fail = array();
if ( $declared !== $installed ) {
	$fail[] = "mhmuicore_register degismezi {$declared}, kurulu paket {$installed}: degismezi paketle esitleyin.";
}
// ^0.23: 0.x'te kısıt minör sürümü sabitler (>=0.23.0 <0.24.0); ^1.2: >=1.2.0 <2.0.0.
$v  = explode( '.', $installed );
$ok = ( 0 === (int) $c[1] )
	? ( 0 === (int) ( $v[0] ?? 0 ) && (int) ( $v[1] ?? 0 ) === (int) $c[2] )
	: ( (int) ( $v[0] ?? 0 ) === (int) $c[1] && (int) ( $v[1] ?? 0 ) >= (int) $c[2] );
if ( ! $ok ) {
	$fail[] = "kurulu ui-core {$installed}, composer.json kisiti {$req} disinda.";
}

if ( array() !== $fail ) {
	fwrite( STDERR, "check-uicore-version FAILED --\n  " . implode( "\n  ", $fail ) . "\n" );
	exit( 1 );
}
echo "check-uicore-version: [OK] degismez = kurulu paket = {$installed} ({$req})\n";
exit( 0 );
