<?php
/**
 * Yarış testi çocuk süreci (bkz. Race.php). stdin'den JSON yapılandırma okur, WordPress'i SHORTINIT ile test
 * veritabanına bağlar, bir ReservationRepository yöntemini çağırır; deponun COMMIT'inden hemen önce "READY" yazıp
 * `pause_ms` bekler. Son satır: "RESULT {json}".
 *
 * Yalnız test aracı; pakete girmez.
 *
 * @package MHM\Slotiva\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress'in beklediği sabitler ve $table_prefix (ayrı çocuk süreç).
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI çocuk süreci; çıktı ana teste gider.

$slotiva_cfg = json_decode( (string) stream_get_contents( STDIN ), true );
if ( ! is_array( $slotiva_cfg ) ) {
	fwrite( STDERR, "bad config\n" );
	exit( 2 );
}

define( 'ABSPATH', $slotiva_cfg['abspath'] );
define( 'DB_NAME', $slotiva_cfg['db_name'] );
define( 'DB_USER', $slotiva_cfg['db_user'] );
define( 'DB_PASSWORD', $slotiva_cfg['db_pass'] );
define( 'DB_HOST', $slotiva_cfg['db_host'] );
define( 'DB_CHARSET', $slotiva_cfg['charset'] );
define( 'DB_COLLATE', '' );
define( 'SHORTINIT', true );
define( 'WP_DEBUG', false );
$table_prefix = $slotiva_cfg['prefix'];

require ABSPATH . 'wp-settings.php';

if ( ! function_exists( 'get_current_user_id' ) ) {
	/** SHORTINIT kullanıcı API'sini yüklemez; olay geçmişi aktörü `system`. */
	function get_current_user_id(): int {
		return 0;
	}
}

spl_autoload_register(
	static function ( string $cls ) use ( $slotiva_cfg ): void {
		$prefix = 'MHM\\Slotiva\\';
		if ( str_starts_with( $cls, $prefix ) ) {
			$file = $slotiva_cfg['src'] . '/' . str_replace( '\\', '/', substr( $cls, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);

/**
 * Tarih alanlarını DateTimeImmutable'a çevirir.
 *
 * @param mixed $v Değer.
 * @return mixed
 */
function slotiva_race_dates( $v ) {
	if ( ! is_array( $v ) ) {
		return $v;
	}
	$utc = new DateTimeZone( 'UTC' );
	// Üst düzey tarih argümanı (ör. reschedule'un yeni başlangıcı): array( '__dt' => 'Y-m-d H:i:s' ).
	if ( array( '__dt' ) === array_keys( $v ) ) {
		return new DateTimeImmutable( (string) $v['__dt'], $utc );
	}
	foreach ( $v as $k => $item ) {
		if ( 'slots' === $k && is_array( $item ) ) {
			$v[ $k ] = array_map( static fn( $s ) => new DateTimeImmutable( (string) $s, $utc ), $item );
		} elseif ( in_array( $k, array( 'hold_expires_at', 'expect_hold_expires_at' ), true ) && is_string( $item ) ) {
			$v[ $k ] = new DateTimeImmutable( $item, $utc );
		} elseif ( is_array( $item ) ) {
			$v[ $k ] = slotiva_race_dates( $item );
		}
	}
	return $v;
}

MHM\Slotiva\Support\Clock::freeze( new DateTimeImmutable( (string) $slotiva_cfg['now'], new DateTimeZone( 'UTC' ) ) );

// Test kapasitesi: ana süreçteki süzgecin eşi (çocuk ayrı süreçtir, süzgeç taşınmaz). Yalnız test aracı.
$slotiva_capacity = array_map( 'intval', (array) ( $slotiva_cfg['capacity'] ?? array() ) );
if ( $slotiva_capacity ) {
	add_filter(
		'slotiva_product_capacity',
		static fn( $cap, $product_id ) => $slotiva_capacity[ (int) $product_id ] ?? $cap,
		10,
		2
	);
}

$slotiva_paused = false;
$slotiva_res    = $slotiva_cfg['prefix'] . 'slotiva_reservations';
add_filter(
	'query',
	static function ( string $q ) use ( &$slotiva_paused, $slotiva_cfg, $slotiva_res ): string {
		$at = array(
			'commit' => 'COMMIT' === $q,
			'start'  => 'START TRANSACTION' === $q,
			'insert' => str_starts_with( $q, "INSERT INTO `{$slotiva_res}`" ),
		);
		if ( ! $slotiva_paused && ! empty( $at[ (string) $slotiva_cfg['pause_at'] ] ) ) {
			$slotiva_paused = true;
			fwrite( STDOUT, "READY\n" );
			fflush( STDOUT );
			usleep( 1000 * (int) $slotiva_cfg['pause_ms'] );
		}
		return $q;
	}
);

$slotiva_args = array_map( 'slotiva_race_dates', (array) $slotiva_cfg['args'] );
$slotiva_t0   = microtime( true );
try {
	$slotiva_result = call_user_func_array( array( 'MHM\\Slotiva\\Reservation\\ReservationRepository', (string) $slotiva_cfg['op'] ), $slotiva_args );
	$slotiva_out    = array(
		'ok'     => true,
		'result' => $slotiva_result,
	);
} catch ( Throwable $slotiva_e ) {
	$slotiva_out = array(
		'ok'      => false,
		'error'   => get_class( $slotiva_e ),
		'message' => $slotiva_e->getMessage(),
	);
}
$slotiva_out['elapsed_ms'] = (int) round( 1000 * ( microtime( true ) - $slotiva_t0 ) );
fwrite( STDOUT, 'RESULT ' . wp_json_encode( $slotiva_out ) . "\n" );
