<?php
/**
 * Crippleware kapısı: Lite'ta yapay sınır / deneme / kota / yükseltme yüzeyi olmasın (WP.org Yönerge 5).
 *
 * Tarar: src/ templates/ src-react/ (testler hariç) ve kök *.php. Temizse 0, bulgu varsa 1.
 *
 * MUAFİYETLER (dosya => düşülecek TAM kimlikler; düşüldükten sonra satır yeniden taranır):
 *  - `quota`: Slotiva'nın kötüye kullanım önleme sınırı. Müşteri başına ödenmemiş bekleyen tutma (hold) sayısını
 *    sınırlar (güvenlik), her sürümde aynıdır, hiçbir yerde yükseltmeye bağlanmaz (Karar R2). Yalnız aşağıdaki
 *    kimlikler ve dosyalar muaftır; başka bir dosyada ya da başka bir `quota` kimliğinde kapı yine başarısız olur.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

/** @var array<string, string> desen => neden */
$forbidden = array(
	'max_?seats|maxSeats'                          => 'seat cap',
	'limit_?reached|limitReached'                  => 'limit notice',
	'quota'                                        => 'quota (artificial limit)',
	'(?<![a-z])trial(?![a-z])'                     => 'trial',
	'LiteOverflow|ProFeatureNotice|displayLimitNotice' => 'overflow limiter / upsell notice',
	'get_comparison_table_data|render_comparison_table|get_pro_features_list' => 'upsell comparison',
	'\bRestrictions::'                            => 'license restriction engine',
);

/** @var array<string, list<string>> */
$exemptions = array(
	'src/Reservation/HoldQuotaException.php'      => array( 'HoldQuotaException', 'slotiva_hold_quota' ),
	'src/Reservation/ReservationRepository.php'   => array( 'HoldQuotaException', 'HOLD_QUOTA', 'slotiva_hold_quota' ),
	'src/Admin/RestController.php'                => array( 'HoldQuotaException', 'slotiva_hold_quota' ),
	'src/Checkout/CheckoutFlow.php'               => array( 'HoldQuotaException', 'slotiva_hold_quota' ),
	'src-react/checkout/api.js'                   => array( 'slotiva_hold_quota' ),
	'src-react/checkout/Picker.js'                => array( 'slotiva_hold_quota' ),
);

$files = array();
foreach ( array( 'src', 'templates', 'src-react' ) as $dir ) {
	$base = $root . '/' . $dir;
	if ( ! is_dir( $base ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$p = str_replace( DIRECTORY_SEPARATOR, '/', $f->getPathname() );
		if ( false !== strpos( $p, '/node_modules/' ) || false !== strpos( $p, '/__tests__/' ) || ! in_array( $f->getExtension(), array( 'php', 'js', 'jsx', 'ts', 'tsx', 'scss', 'css' ), true ) ) {
			continue;
		}
		$files[] = $p;
	}
}
foreach ( (array) glob( $root . '/*.php' ) as $f ) {
	$files[] = str_replace( DIRECTORY_SEPARATOR, '/', $f );
}

$hits   = array();
$prefix = str_replace( DIRECTORY_SEPARATOR, '/', $root ) . '/';
foreach ( $files as $path ) {
	$rel = substr( $path, strlen( $prefix ) );
	foreach ( (array) file( $path ) as $n => $line ) {
		foreach ( $exemptions[ $rel ] ?? array() as $ok ) {
			$line = str_replace( $ok, '', $line );
		}
		foreach ( $forbidden as $p => $why ) {
			if ( 1 === preg_match( '/' . $p . '/i', $line ) ) {
				$hits[] = sprintf( '%s:%d  [%s]  (%s)  %s', $rel, $n + 1, $p, $why, mb_substr( trim( $line ), 0, 120 ) );
			}
		}
	}
}

if ( array() !== $hits ) {
	echo "Crippleware found in Lite:\n\n" . implode( "\n", $hits ) . "\n\n" . count( $hits ) . " found.\n";
	exit( 1 );
}
echo '[OK] Lite is crippleware-free (' . count( $files ) . " files scanned).\n";
exit( 0 );
