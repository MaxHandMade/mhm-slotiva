<?php
/**
 * WP.org Yönerge 5 kapısı: ZIP'e giren yüzeyde Pro / lisans / yükseltme (upsell) sözü kalmasın.
 *
 *   php bin/check-no-pro-refs.php          tarar; temizse 0, bulgu varsa 1, kapı kurulamadıysa 2
 *   php bin/check-no-pro-refs.php --list   taranan dosyaları yazar (test için)
 *
 * Tarama kümesi: src/ src-react/ templates/ build/ languages/*.pot readme.txt mhm-slotiva.php uninstall.php
 * ve vendor/mhm/ui-core'un ZIP'e giren yolları. ui-core yolları elle yazılmaz: bin/build-zip.sh'taki
 * UICORE_ALLOW / UICORE_EXCLUDE değişkenleri okunup diskteki ui-core'a aynen uygulanır (tek kaynak; dışlama
 * listesinin ikinci bir kopyası tutulmaz). `build/` taze derlenmiş olmalı: rm -rf build && npm run build.
 *
 * Desenler somut Pro/lisans yüzeyleridir; "license" ya da "pro" tek başına yasak değildir (GPL başlık satırları geçer).
 * Muafiyetler aşağıda dosya + tam alt dizge olarak, gerekçesiyle yazılır; deseni gevşetmek yerine muafiyet eklenir.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

$base = dirname( __DIR__ );

/** @var array<string, string> */
$patterns = array(
	// Sinir: `` `_` icinde tetiklenmez (slotiva_is_pro); harf-olmayan sinirlar kullanilir. Hepsi buyuk/kucuk harfe duyarsizdir
	// ($licenseManager, ispro). camelCase kolu `(?-i:)` ile duyarlidir ve `(?![a-z])` ile biter: isProActive yakalanir,
	// isProcessing / isProduct / isprotected yakalanmaz; tamamen kucuk harfli `ispro` ayri kolla yakalanir.
	'edition class'   => '/LicenseManager|LicenseRest|LicenseAdmin|UpdateNotice|VerifyEndpoint/i',
	'edition camel'   => '/(?-i:[iI]sPro(?![a-z]))|(?<![a-z])ispro(?![a-z])/i',
	'edition snake'   => '/pro_(?:seam|feature)|(?<![a-z])(?:is_pro|feature_token|license_key|license_status)(?![a-z])/i',
	'update uri'      => '/Update URI/i',
	'upsell copy'     => '/available in (?:the )?pro\b|requires? (?:slotiva )?pro\b|upgrade to\b|\bpro version\b|unlock (?:pro|premium)\b|(?:get|buy) a licen[sc]e|paid plugin|not distributed on WordPress\.org/i',
	'purchase url'    => '#wpalemi\.com/slotiva#i',
	'license option'  => '/slotiva_license(?:_checked)?/i',
	'license wording' => '/licen[sc]e[ _-]?(?:key|server|screen|panel|check|status)|licensed site|licen[sc]e (?:and|&) updates|SLOTIVA_LICENSE/i',
	'TR pro/license'  => '/pro\s+sürüm|lisans/iu',
);

/**
 * Muafiyetler: dosya (köke göre) => satırdan DÜŞÜLECEK tam alt dizgeler. Düşüldükten sonra satır yeniden taranır;
 * muaf alt dizge dışında kalan bir yasak sözcük yine yakalanır.
 *
 * @var array<string, list<string>>
 */
$exemptions = array(
	// Bu Jest testi lisans/upsell metninin DOM'da YOKLUĞUNU doğrular (yasak sözcük bir regex literali); ZIP'e girmez ama src-react taranır.
	'src-react/admin/__tests__/appointments-checklist.test.js' => array( '/licen[cs]e|lisans/i' ),
	// GPL başlık satırları: bugün hiçbir dosyada "GPL lisansı" yazmıyor (denetlendi); İngilizce "License:" zaten desene girmez.
);

$exts = array_fill_keys( array( 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'txt', 'pot' ), true );

require __DIR__ . '/lib/shipped-tree.php';

$files = slotiva_shipped_files( $base, 'check-no-pro-refs' );

if ( in_array( '--list', $argv, true ) ) {
	echo implode( "\n", $files ) . "\n";
	exit( 0 );
}

if ( array() === $files || ! in_array( 'build/admin/appointments.js', $files, true ) ) {
	fwrite( STDERR, "check-no-pro-refs FAILED -- build/ yok ya da bos: once `rm -rf build && npm run build`.\n" );
	exit( 2 );
}

/**
 * @param array<string, string> $rules
 * @return list<string>
 */
function slotiva_matches( string $line, array $rules ): array {
	$out = array();
	foreach ( $rules as $label => $re ) {
		if ( 1 === preg_match( $re, $line ) ) {
			$out[] = $label;
		}
	}
	return $out;
}

// Negatif kontrol: kapı gerçek bir yasak sözcüğü yakalamalı, GPL başlıklarını yakalamamalı.
if (
	array() === slotiva_matches( 'new LicenseManager()', $patterns )
	|| array() === slotiva_matches( 'Pro sürüm gerekir', $patterns )
	|| array() !== slotiva_matches( ' * License:              GPL-2.0-or-later', $patterns )
	|| array() !== slotiva_matches( ' * License URI:          https://www.gnu.org/licenses/gpl-2.0.html', $patterns )
) {
	fwrite( STDERR, "check-no-pro-refs FAILED -- kapinin negatif kontrolu gecersiz.\n" );
	exit( 2 );
}

$hits = array();
foreach ( $files as $rel ) {
	$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	if ( ! isset( $exts[ $ext ] ) ) {
		continue;
	}
	$lines = file( $base . '/' . $rel );
	if ( false === $lines ) {
		continue;
	}
	foreach ( $lines as $n => $line ) {
		foreach ( $exemptions[ $rel ] ?? array() as $ok ) {
			$line = str_replace( $ok, '', $line );
		}
		foreach ( slotiva_matches( $line, $patterns ) as $label ) {
			$hits[] = sprintf( '%s:%d [%s] %s', $rel, $n + 1, $label, mb_substr( trim( $line ), 0, 160 ) );
		}
	}
}

if ( array() !== $hits ) {
	fwrite( STDERR, "check-no-pro-refs FAILED -- ZIP yuzeyinde Pro/lisans sozu var:\n" . implode( "\n", $hits ) . "\n" );
	exit( 1 );
}
echo 'check-no-pro-refs: temiz (' . count( $files ) . " dosya tarandi)\n";
