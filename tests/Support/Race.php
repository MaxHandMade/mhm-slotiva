<?php
/**
 * Yarış testi koşum aracı: depo işlemini AYRI bir PHP sürecinde (kendi mysqli bağlantısı) çalıştırır.
 *
 * Çocuk süreç (`race-worker.php`) WordPress'i SHORTINIT ile aynı test veritabanına bağlar, istenen depo yöntemini çağırır
 * ve `pause_at` noktasında durur: "READY" yazar, `pause_ms` bekler, sonra devam eder. `commit` = COMMIT'ten hemen önce
 * (tüm kilitler + yazılar tutulurken); `start` = adlı kilitler alındıktan sonra, ilk START TRANSACTION'da (henüz satır
 * kilidi yok — adlı kilidin TEK BAŞINA serileştirdiğini kanıtlar); `insert` = ilk rezervasyon INSERT'inden hemen önce
 * (okumalar yapılmış, yazma yok — boşluk kilidi çakışmalarını açığa çıkarır). Çocuğun saati ana sürecin Clock'una
 * dondurulur. `commit` için: o arada çocuk tüm
 * kilitlerini (GET_LOCK + satır/birim kilitleri) tutmaktadır; ana süreç kendi işlemini tam o anda başlatır ve gerçek
 * bir eşzamanlı çakışma yaşanır (sıralı PHPUnit değil — spec §15 kabul kapısı).
 *
 * @package MHM\Slotiva\Tests
 */

namespace MHM\Slotiva\Tests\Support;

// phpcs:disable WordPress.WP.AlternativeFunctions, Generic.PHP.ForbiddenFunctions, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- test aracı: süreç ve boru yönetimi.

/**
 * Tek bir çocuk süreç.
 */
final class Race {

	/**
	 * Süreç kaynağı.
	 *
	 * @var resource
	 */
	private $proc;

	/**
	 * Borular: 0 stdin, 1 stdout, 2 stderr.
	 *
	 * @var array<int,resource>
	 */
	private array $pipes;

	/**
	 * Okunan stdout.
	 *
	 * @var string
	 */
	private string $out = '';

	/**
	 * Okunan stderr.
	 *
	 * @var string
	 */
	private string $err = '';

	/**
	 * Çocuk süreci başlatır.
	 *
	 * @param string           $op       ReservationRepository yöntemi (claim, replaceForOrder, setStatus, release, reclaim, supersedeForSession).
	 * @param array<int,mixed> $args     Konumsal bağımsız değişkenler; `slots`/`hold_expires_at`/`expect_hold_expires_at` dizgileri UTC tarihe çevrilir.
	 * @param int              $pause_ms Bekleme (ms).
	 * @param string           $pause_at commit|start|insert|never (never = durmaz, READY yazmaz).
	 * @param array<int,int>   $capacity Ürün → kapasite; çocukta `slotiva_product_capacity` süzgecine yazılır (yalnız test).
	 * @throws \RuntimeException Süreç başlatılamadı.
	 */
	public static function start( string $op, array $args, int $pause_ms = 1500, string $pause_at = 'commit', array $capacity = array() ): self {
		global $wpdb;
		$self = new self();
		$cfg  = array(
			'abspath'  => ABSPATH,
			'db_name'  => DB_NAME,
			'db_user'  => DB_USER,
			'db_pass'  => DB_PASSWORD,
			'db_host'  => DB_HOST,
			'charset'  => defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8mb4',
			'prefix'   => $wpdb->prefix,
			'src'      => dirname( __DIR__, 2 ) . '/src',
			'op'       => $op,
			'args'     => $args,
			'pause_ms' => $pause_ms,
			'pause_at' => $pause_at,
			'capacity' => $capacity,
			'now'      => \MHM\Slotiva\Support\Clock::now()->format( 'Y-m-d H:i:s' ),
		);
		$proc = proc_open(
			array( PHP_BINARY, __DIR__ . '/race-worker.php' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $proc ) ) {
			throw new \RuntimeException( 'race: proc_open failed' );
		}
		$self->proc  = $proc;
		$self->pipes = $pipes;
		fwrite( $pipes[0], (string) wp_json_encode( $cfg ) );
		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );
		return $self;
	}

	/**
	 * Beklemeden: çocuk `pause_at` noktasına geldi mi (READY yazdı mı)? Bir kez boruları okur.
	 */
	public function is_ready(): bool {
		if ( ! str_contains( $this->out, "READY\n" ) && is_resource( $this->proc ) ) {
			$this->pump();
		}
		return str_contains( $this->out, "READY\n" );
	}

	/**
	 * Çocuk COMMIT öncesine gelene (kilitleri tutarken) kadar bekler.
	 *
	 * @param float $timeout Saniye.
	 * @throws \RuntimeException Çocuk hazır olmadan bitti ya da zaman aşımı.
	 */
	public function wait_ready( float $timeout = 30.0 ): void {
		$until = microtime( true ) + $timeout;
		while ( ! str_contains( $this->out, "READY\n" ) ) {
			if ( microtime( true ) > $until || ! $this->pump() ) {
				throw new \RuntimeException( 'race: worker not ready. stdout=' . $this->out . ' stderr=' . $this->err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test tanısı.
			}
		}
	}

	/**
	 * Çocuğun bitmesini bekler ve sonucunu döndürür.
	 *
	 * @param float $timeout Saniye.
	 * @return array<string,mixed> {ok:bool, result?:mixed, error?:string, message?:string}
	 * @throws \RuntimeException Sonuç yok.
	 */
	public function finish( float $timeout = 60.0 ): array {
		$until = microtime( true ) + $timeout;
		while ( $this->pump() && microtime( true ) < $until ) {
			usleep( 10000 );
		}
		$this->close();
		if ( ! preg_match( '/^RESULT (.*)$/m', $this->out, $m ) ) {
			throw new \RuntimeException( 'race: no result. stdout=' . $this->out . ' stderr=' . $this->err ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test tanısı.
		}
		$res = json_decode( $m[1], true );
		return is_array( $res ) ? $res : array( 'ok' => false );
	}

	/** Test bir doğrulamada düşse bile çocuk süreç asılı kalmaz. */
	public function __destruct() {
		$this->close();
	}

	/** Süreç hâlâ çalışıyorsa sonlandırır; boruları ve süreci kapatır (bir kez). */
	private function close(): void {
		if ( ! is_resource( $this->proc ) ) {
			return;
		}
		$status = proc_get_status( $this->proc );
		if ( $status['running'] ) {
			proc_terminate( $this->proc );
		}
		foreach ( array( 1, 2 ) as $i ) {
			if ( is_resource( $this->pipes[ $i ] ) ) {
				fclose( $this->pipes[ $i ] );
			}
		}
		proc_close( $this->proc );
	}

	/**
	 * Borulardan okur. Süreç hâlâ çalışıyorsa true.
	 */
	private function pump(): bool {
		$read   = array( $this->pipes[1], $this->pipes[2] );
		$write  = null;
		$except = null;
		if ( stream_select( $read, $write, $except, 0, 50000 ) ) {
			foreach ( $read as $pipe ) {
				$chunk = (string) fread( $pipe, 65536 );
				if ( $pipe === $this->pipes[1] ) {
					$this->out .= $chunk;
				} else {
					$this->err .= $chunk;
				}
			}
		}
		$status = proc_get_status( $this->proc );
		if ( ! $status['running'] ) {
			// Son parçalar.
			$this->out .= (string) stream_get_contents( $this->pipes[1] );
			$this->err .= (string) stream_get_contents( $this->pipes[2] );
			return false;
		}
		return true;
	}
}
