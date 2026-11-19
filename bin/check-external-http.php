<?php
/**
 * Dış HTTP kapısı: Lite sunucu tarafında HİÇBİR dış HTTP çağrısı yapmaz (lisans istemcisi çıktıktan sonra).
 *
 *   php bin/check-external-http.php   0 = temiz, 1 = çağrı/ana makine bulundu, 2 = kapı kurulamadı
 *
 * Tarama kümesi: src/ templates/ mhm-slotiva.php uninstall.php, YALNIZ kod belirteçleri (yorum ve docblock atlanır:
 * yorumda adı geçen işlev istek atmaz). Yasak: wp_remote_* / wp_safe_remote_*, curl_*, fsockopen/pfsockopen,
 * stream_socket_client, download_url, WP_Http, ve ilk bağımsız değişkeni http(s):// düz metni olan
 * file_get_contents/fopen.
 *
 * Bilerek yasak DEĞİL: müşterinin tıkladığı bağlantılar (ör. "Google Takvim'e ekle" URL'si; OrderDisplay) yalnız bir
 * metindir, sunucu istek atmaz. Kapı çağrıyı arar, URL metnini aramaz. Tarayıcı tarafı sızıntı (CDN yazı tipi/betik,
 * analitik, Gravatar) için küçük bir ana makine kara listesi de kod metinlerinde aranır: WP.org paketlenmiş varlık ister.
 *
 * @package MHM\Slotiva
 */

declare( strict_types=1 );

$base = dirname( __DIR__ );

$call_re = '/^(?:wp_(?:safe_)?remote_\w+|curl_\w+|fsockopen|pfsockopen|stream_socket_client|download_url|(?-i:WP_Http(?:_(?:Curl|Streams))?))$/i';

$denied_hosts = array(
	'fonts.googleapis.com',
	'fonts.gstatic.com',
	'ajax.googleapis.com',
	'cdn.jsdelivr.net',
	'cdnjs.cloudflare.com',
	'unpkg.com',
	'google-analytics.com',
	'googletagmanager.com',
	'gravatar.com',
	'ip-api.com',
	'ipapi.co',
	'ipinfo.io',
);

$files = array();
foreach ( array( 'src', 'templates' ) as $dir ) {
	if ( ! is_dir( $base . '/' . $dir ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( 'php' === $f->getExtension() ) {
			$files[] = $f->getPathname();
		}
	}
}
foreach ( array( 'mhm-slotiva.php', 'uninstall.php' ) as $f ) {
	if ( is_file( $base . '/' . $f ) ) {
		$files[] = $base . '/' . $f;
	}
}
if ( array() === $files || ! is_file( $base . '/src/Admin/Menu.php' ) ) {
	fwrite( STDERR, "check-external-http KURULAMADI -- src/ bulunamadi.\n" );
	exit( 2 );
}

$hits = array();
foreach ( $files as $path ) {
	$rel    = ltrim( str_replace( str_replace( '\\', '/', $base ), '', str_replace( '\\', '/', $path ) ), '/' );
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) || in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$line = $t[2];
		if ( in_array( $t[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
			$name = ltrim( $t[1], '\\' );
			if ( 1 === preg_match( $call_re, $name ) ) {
				$hits[] = "{$rel}:{$line} {$name}";
				continue;
			}
			if ( in_array( strtolower( $name ), array( 'file_get_contents', 'fopen' ), true ) ) {
				// İlk anlamlı belirteç `(`, sonraki düz metin http(s):// ile başlıyorsa uzak okumadır.
				$j = $i + 1;
				while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				if ( $j < $count && '(' === $tokens[ $j ] ) {
					++$j;
					while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
						++$j;
					}
					if ( $j < $count && is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0]
						&& 1 === preg_match( '#^[\'"]https?://#i', $tokens[ $j ][1] ) ) {
						$hits[] = "{$rel}:{$line} {$name}(http...)";
					}
				}
			}
			continue;
		}
		if ( in_array( $t[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) ) {
			foreach ( $denied_hosts as $host ) {
				if ( false !== stripos( $t[1], $host ) ) {
					$hits[] = "{$rel}:{$line} yasak ana makine {$host}";
				}
			}
		}
	}
}

if ( array() !== $hits ) {
	sort( $hits );
	fwrite( STDERR, "check-external-http FAILED -- Lite dis HTTP yapmaz:\n  " . implode( "\n  ", $hits ) . "\n" );
	exit( 1 );
}
echo 'check-external-http: temiz (' . count( $files ) . " dosya tarandi)\n";
exit( 0 );
